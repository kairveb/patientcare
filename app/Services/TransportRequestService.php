<?php

namespace App\Services;

use App\Models\Admission;
use App\Models\ErVisit;
use App\Models\IntegrationLog;
use App\Models\IntegrationEvent;
use App\Models\IntegrationPartner;
use App\Models\TransportRequest;
use App\Models\TransportRequestHistory;
use App\Models\User;
use App\Notifications\TransportStatusNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class TransportRequestService
{
    private const TRANSITIONS = [
        'REQUESTED' => ['ACCEPTED', 'CANCELLED'],
        'ACCEPTED' => ['DISPATCHED', 'CANCELLED'],
        'DISPATCHED' => ['ARRIVED', 'CANCELLED'],
        'ARRIVED' => ['COMPLETED'],
        'COMPLETED' => [],
        'CANCELLED' => [],
        'FAILED' => [],
    ];

    public function create(array $data, User $actor): TransportRequest
    {
        $source = $data['source_type'] === 'er_visit'
            ? ErVisit::query()->findOrFail($data['source_id'])
            : Admission::query()->findOrFail($data['source_id']);
        $priority = $data['source_type'] === 'er_visit'
            ? $this->erPriority($source)
            : $data['priority'];
        $allowedNeeds = ['stretcher_required', 'oxygen_support', 'wheelchair'];
        $needs = array_intersect_key($data['needs'] ?? [], array_flip($allowedNeeds));
        $needs = array_map(static fn ($value): bool => (bool) $value, $needs);

        $transport = DB::transaction(function () use ($data, $actor, $priority, $needs): TransportRequest {
            $transport = TransportRequest::create([
                'source_type' => $data['source_type'],
                'source_id' => $data['source_id'],
                'priority' => $priority,
                'pickup_zone' => $data['pickup_zone'],
                'destination_zone' => $data['destination_zone'],
                'needs' => $needs,
                'passenger_count' => $data['passenger_count'] ?? 1,
                'status' => 'REQUESTED',
                'requested_by' => $actor->id,
                'status_updated_at' => now(),
            ]);
            $this->history($transport, null, 'REQUESTED', $actor->id, 'USER');

            return $transport;
        });

        $this->enqueue($transport, 'vrds_dispatch_request', [
            'purpose' => 'patient transport dispatch',
            'fields' => ['request_ref', 'priority', 'pickup_zone', 'destination_zone', 'needs', 'passenger_count'],
        ]);

        return $transport->fresh();
    }

    public function cancel(TransportRequest $transport, User $actor, ?string $reason = null): TransportRequest
    {
        DB::transaction(function () use ($transport, $actor, $reason): void {
            $transport = TransportRequest::query()->lockForUpdate()->findOrFail($transport->id);
            if ($transport->status === 'COMPLETED') {
                throw new ConflictHttpException('Completed transport requests cannot be cancelled.');
            }
            if ($transport->status === 'CANCELLED') {
                return;
            }

            $from = $transport->status;
            $transport->update([
                'status' => 'CANCELLED',
                'cancelled_reason' => $reason,
                'status_updated_at' => now(),
            ]);
            $this->history($transport, $from, 'CANCELLED', $actor->id, 'USER');
        });

        $transport->refresh();
        $this->queuePartnerCancellation($transport);

        return $transport;
    }

    public function queuePartnerCancellation(TransportRequest $transport): void
    {
        if ($transport->status !== 'CANCELLED' || ! $transport->external_id) {
            return;
        }

        $this->enqueue($transport, 'vrds_dispatch_cancel', [
            'purpose' => 'patient transport dispatch',
            'fields' => ['request_ref'],
            'external_id' => $transport->external_id,
        ]);
    }

    public function applyPartnerStatus(IntegrationPartner $partner, array $data, string $requestId): array
    {
        if ($partner->code !== 'vrds') {
            return $this->error(403, 'partner_forbidden', 'This webhook is not authorized for this partner.');
        }
        $status = strtoupper($data['status']);
        if (! in_array($status, ['REQUESTED', 'ACCEPTED', 'DISPATCHED', 'ARRIVED', 'COMPLETED', 'CANCELLED'], true)) {
            return $this->error(422, 'invalid_status', 'Transport status is not supported.');
        }

        $transport = TransportRequest::query()
            ->where('external_id', $data['external_id'])
            ->lockForUpdate()
            ->first();
        if (! $transport) {
            return $this->error(404, 'external_id_not_found', 'The external transport reference was not found.');
        }

        $current = $transport->status;
        if ($current === $status) {
            $details = ['status_updated_at' => now()];
            if (array_key_exists('vehicle_ref', $data)) {
                $details['vehicle_ref'] = $data['vehicle_ref'];
            }
            if (array_key_exists('eta_at', $data)) {
                $details['eta_at'] = $data['eta_at'];
            }
            if (count($details) > 1) {
                $transport->update($details);
            }

            return ['status' => 200, 'body' => ['success' => true, 'data' => ['status' => $status]]];
        }
        if (! in_array($status, self::TRANSITIONS[$current] ?? [], true)) {
            IntegrationLog::create([
                'integration' => 'vrds',
                'event' => 'DISPATCH_STATUS_REJECTED',
                'status' => 'FAILURE',
                'metadata' => ['external_id' => $data['external_id'], 'from_status' => $current, 'to_status' => $status],
                'request_id' => $requestId,
                'integration_partner_id' => $partner->id,
                'status_code' => 409,
            ]);

            return $this->error(409, 'invalid_status_transition', 'The transport status transition is not allowed.');
        }

        $attributes = ['status' => $status, 'status_updated_at' => now()];
        if (array_key_exists('vehicle_ref', $data)) {
            $attributes['vehicle_ref'] = $data['vehicle_ref'];
        }
        if (array_key_exists('eta_at', $data)) {
            $attributes['eta_at'] = $data['eta_at'];
        }
        $transport->update($attributes);
        $this->history($transport, $current, $status, null, 'PARTNER');
        if (in_array($status, ['DISPATCHED', 'ARRIVED'], true)) {
            $this->notifyStatus($transport, $status);
        }

        return ['status' => 200, 'body' => ['success' => true, 'data' => ['status' => $status]]];
    }

    public function applyMockStatus(TransportRequest $transport, array $callback): void
    {
        $partner = IntegrationPartner::query()->where('code', 'vrds')->firstOrFail();
        $eventId = (string) $callback['event_id'];
        DB::transaction(function () use ($partner, $callback, $eventId): void {
            $existing = IntegrationEvent::query()
                ->where('integration_partner_id', $partner->id)
                ->where('event_id', $eventId)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                return;
            }

            $result = $this->applyPartnerStatus($partner, $callback, $eventId);
            IntegrationEvent::create([
                'integration_partner_id' => $partner->id,
                'event_id' => $eventId,
                'response_status' => $result['status'],
                'response_body' => $result['body'],
                'processed_at' => now(),
            ]);
        });
    }

    public function recordOutboundAccepted(TransportRequest $transport, string $status): void
    {
        if (! in_array($status, ['REQUESTED', 'ACCEPTED'], true) || $transport->status === $status) {
            return;
        }
        $from = $transport->status;
        if (! in_array($status, self::TRANSITIONS[$from] ?? [], true)) {
            return;
        }
        $transport->update(['status' => $status, 'status_updated_at' => now()]);
        $this->history($transport, $from, $status, null, 'PARTNER');
    }

    private function enqueue(TransportRequest $transport, string $entityType, array $reference): void
    {
        try {
            $partner = IntegrationPartner::query()->where('code', 'vrds')->where('active', true)->firstOrFail();
            if ($entityType === 'vrds_dispatch_request') {
                app(IntegrationReferenceMapService::class)->for($partner, 'transport_request', (int) $transport->id);
            }
            app(IntegrationOutboxService::class)->enqueue($partner->code, $entityType, (int) $transport->id, $reference);
        } catch (\Throwable $exception) {
            Log::warning('VRDS transport delivery could not be queued; local transport request was retained.', [
                'transport_request_id' => $transport->id,
                'error' => Str::limit($exception->getMessage(), 250),
            ]);
        }
    }

    private function erPriority(ErVisit $visit): string
    {
        $assessment = $visit->triageAssessments()->latest('triaged_at')->first();
        $severity = strtolower(trim((string) $assessment?->severity_category));
        $level = strtoupper(trim((string) ($assessment?->final_level ?: $assessment?->priority)));
        if (in_array($level, ['LEVEL_1', 'LEVEL 1', 'EMERGENCY'], true)
            || $visit->queue?->priority === 'Level 1') {
            return 'EMERGENCY';
        }
        if (in_array($level, ['LEVEL_2', 'LEVEL 2', 'URGENT'], true)
            || $visit->queue?->priority === 'Level 2') {
            return 'URGENT';
        }
        if (str_starts_with($severity, 'severe')) {
            return 'EMERGENCY';
        }
        if ($severity === 'urgent') {
            return 'URGENT';
        }

        return 'ROUTINE';
    }

    private function history(TransportRequest $transport, ?string $from, string $to, ?int $actorId, string $source): void
    {
        TransportRequestHistory::create([
            'transport_request_id' => $transport->id,
            'from_status' => $from,
            'to_status' => $to,
            'changed_by' => $actorId,
            'change_source' => $source,
            'metadata' => null,
            'changed_at' => now(),
        ]);
    }

    private function notifyStatus(TransportRequest $transport, string $status): void
    {
        $module = $transport->source_type === 'er_visit' ? 'EERTS' : 'IBMS';
        if ($transport->requester) {
            $transport->requester->notify(new TransportStatusNotification($status, $transport->priority === 'EMERGENCY', $module));
        }
        if ($transport->priority === 'EMERGENCY') {
            $nurses = User::query()->where('is_active', true)
                ->whereHas('roles', fn ($query) => $query->where('name', 'nurse'))
                ->get();
            Notification::send($nurses, new TransportStatusNotification($status, true, $module));
        }
    }

    private function error(int $status, string $code, string $message): array
    {
        return [
            'status' => $status,
            'body' => [
                'success' => false,
                'code' => $code,
                'message' => $message,
            ],
        ];
    }
}
