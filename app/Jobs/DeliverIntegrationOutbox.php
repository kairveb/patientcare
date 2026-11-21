<?php

namespace App\Jobs;

use App\Models\IntegrationLog;
use App\Exceptions\PartnerRequestException;
use App\Models\IntegrationOutbox;
use App\Services\IntegrationPayloadFactory;
use App\Services\IntegrationReference;
use App\Services\MockPartnerClient;
use App\Services\HttpPartnerClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Throwable;

class DeliverIntegrationOutbox implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $outboxId)
    {
    }

    public function handle(IntegrationPayloadFactory $payloads): void
    {
        $outbox = IntegrationOutbox::query()->with('partner')->findOrFail($this->outboxId);
        if (in_array($outbox->status, ['SENT', 'DEAD'], true)) {
            return;
        }
        if ($outbox->entity_type === 'vrds_dispatch_request') {
            $transport = \App\Models\TransportRequest::query()->findOrFail($outbox->entity_id);
            if ($transport->status === 'CANCELLED' && ! $transport->external_id) {
                $outbox->update([
                    'status' => 'DEAD',
                    'last_error' => 'Cancelled before partner dispatch.',
                    'next_retry_at' => null,
                ]);

                return;
            }
        }

        $requestId = (string) Str::uuid();
        try {
            $payload = $payloads->make($outbox, $requestId);
            $client = config('integrations.mode', 'mock') === 'mock'
                ? app(MockPartnerClient::class)
                : app(HttpPartnerClient::class);
            $path = match ($outbox->entity_type) {
                'billing_statement', 'billing_clearance' => '/billing-statements',
                'admission_loa', 'care_request_loa' => '/loa-requests',
                'care_request_lis' => '/lab-orders',
                'care_request_ris' => '/imaging-orders',
                'prescription' => '/prescriptions',
                'emrs_encounter' => '/encounters',
                'emrs_discharge_summary' => '/discharge-summaries',
                'analytics_export' => '/exports',
                'sas_audit_event' => '/audit-events',
                'vrds_dispatch_request' => '/dispatch-requests',
                'vrds_dispatch_cancel' => '/dispatch-requests/'.rawurlencode((string) $outbox->payload_reference['external_id']).'/cancel',
                default => throw new \LogicException('Unsupported integration outbox entity.'),
            };
            $result = $client->send($outbox->partner, $path, $payload, $outbox->idempotency_key, $requestId);

            $outbox->update(['status' => 'SENT', 'next_retry_at' => null, 'last_error' => null]);
            $this->storePartnerResponse($outbox, $result['body']);
            if ($outbox->entity_type === 'vrds_dispatch_request'
                && config('integrations.mode', 'mock') === 'mock'
                && is_array($result['body']['mock_callbacks'] ?? null)) {
                $transport = \App\Models\TransportRequest::query()->find($outbox->entity_id);
                foreach ($result['body']['mock_callbacks'] as $callback) {
                    if ($transport) {
                        app(\App\Services\TransportRequestService::class)->applyMockStatus($transport, $callback);
                        $transport->refresh();
                    }
                }
            }
            IntegrationLog::create([
                'integration' => $outbox->partner->code,
                'event' => $path,
                'status' => 'SUCCESS',
                'metadata' => ['request_id' => $requestId, 'outbox_id' => $outbox->id],
                'request_id' => $requestId,
                'integration_partner_id' => $outbox->integration_partner_id,
                'status_code' => $result['status'],
            ]);
        } catch (Throwable $exception) {
            Log::warning('Integration partner delivery failed; retry handling will continue asynchronously.', [
                'partner' => $outbox->partner->code,
                'outbox_id' => $outbox->id,
                'attempt' => $outbox->attempts + 1,
            ]);
            $attempts = $outbox->attempts + 1;
            $maxAttempts = max(1, (int) config('integrations.max_attempts', 8));
            $dead = $exception instanceof \Illuminate\Validation\ValidationException || $attempts >= $maxAttempts;
            $delay = min(3600, 30 * (2 ** min($attempts - 1, 7)));
            $outbox->update([
                'status' => $dead ? 'DEAD' : 'FAILED',
                'attempts' => $attempts,
                'next_retry_at' => $dead ? null : now()->addSeconds($delay),
                'last_error' => Str::limit($exception->getMessage(), 1000),
            ]);
            if ($outbox->entity_type === 'analytics_export' && $dead) {
                \App\Models\IntegrationExport::query()
                    ->whereKey($outbox->entity_id)
                    ->where('status', 'QUEUED')
                    ->update(['status' => 'FAILED']);
            }
            IntegrationLog::create([
                'integration' => $outbox->partner->code,
                'event' => 'OUTBOUND_DELIVERY',
                'status' => $dead ? 'DEAD' : 'FAILED',
                'metadata' => ['request_id' => $requestId, 'outbox_id' => $outbox->id],
                'request_id' => $requestId,
                'integration_partner_id' => $outbox->integration_partner_id,
                'status_code' => $exception instanceof PartnerRequestException ? $exception->statusCode : null,
            ]);
            if (! $dead) {
                self::dispatch($outbox->id)->delay(now()->addSeconds($delay));
            }
        }
    }

    private function storePartnerResponse(IntegrationOutbox $outbox, array $body): void
    {
        $externalId = $body['external_id'] ?? null;
        $status = strtoupper((string) ($body['status'] ?? 'RECEIVED'));
        if (in_array($outbox->entity_type, ['analytics_export', 'sas_audit_event'], true)) {
            return;
        }
        if ($outbox->entity_type === 'care_request_lis' || $outbox->entity_type === 'care_request_ris') {
            if ($externalId) {
                \App\Models\CareRequest::query()->whereKey($outbox->entity_id)->update([
                    'external_id' => $externalId,
                    'external_partner_code' => $outbox->partner->code,
                    'partner_status' => in_array($status, ['ACCEPTED', 'IN_PROGRESS', 'RESULTED', 'CANCELLED', 'REJECTED'], true)
                        ? $status
                        : 'REQUESTED',
                    'partner_status_updated_at' => now(),
                    'accession_reference' => $body['accession_reference'] ?? $externalId,
                ]);
            }

            return;
        }
        if ($outbox->entity_type === 'prescription') {
            if ($externalId) {
                \App\Models\Prescription::query()->whereKey($outbox->entity_id)->update([
                    'external_id' => $externalId,
                    'partner_status' => in_array($status, ['ACCEPTED', 'REJECTED'], true) ? $status : 'SENT',
                    'partner_status_updated_at' => now(),
                ]);
            }

            return;
        }
        if ($outbox->entity_type === 'vrds_dispatch_request') {
            if (! is_string($externalId) || trim($externalId) === '') {
                throw new \UnexpectedValueException('VRDS dispatch response did not include an external request ID.');
            }
            if ($externalId) {
                $transport = \App\Models\TransportRequest::query()->find($outbox->entity_id);
                if ($transport && ! $transport->external_id) {
                    $transport->update(['external_id' => $externalId]);
                    if ($transport->status === 'CANCELLED') {
                        app(\App\Services\TransportRequestService::class)->queuePartnerCancellation($transport);
                    } else {
                        app(\App\Services\TransportRequestService::class)->recordOutboundAccepted(
                            $transport,
                            in_array($status, ['REQUESTED', 'ACCEPTED'], true) ? $status : 'ACCEPTED'
                        );
                    }
                }
            }

            return;
        }
        if ($outbox->entity_type === 'vrds_dispatch_cancel') {
            return;
        }
        if (! $externalId) {
            return;
        }

        match ($outbox->entity_type) {
            'billing_statement' => \App\Models\EncounterBill::query()->whereKey($outbox->entity_id)->update([
                'integration_external_id' => $externalId,
                'integration_status' => in_array($status, ['REQUESTED', 'RECEIVED'], true) ? $status : 'RECEIVED',
            ]),
            'billing_clearance' => \App\Models\BillingClearanceRequest::query()->whereKey($outbox->entity_id)->update([
                'integration_external_id' => $externalId,
                'integration_status' => in_array($status, ['REQUESTED', 'RECEIVED'], true) ? $status : 'RECEIVED',
            ]),
            'admission_loa' => \App\Models\Admission::query()->whereKey($outbox->entity_id)->update([
                'integration_external_id' => $externalId,
                'integration_status' => 'RECEIVED',
                'loa_status' => 'PENDING',
            ]),
            'care_request_loa' => \App\Models\CareRequest::query()->whereKey($outbox->entity_id)->update([
                'integration_external_id' => $externalId,
                'integration_status' => 'RECEIVED',
                'loa_status' => 'PENDING',
            ]),
        };
    }
}
