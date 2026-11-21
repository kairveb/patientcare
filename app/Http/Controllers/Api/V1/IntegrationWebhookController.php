<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Admission;
use App\Models\BillingClearanceRequest;
use App\Models\EncounterBill;
use App\Models\IntegrationEvent;
use App\Models\IntegrationClinicalDocument;
use App\Models\IntegrationPartner;
use App\Models\IntegrationReferenceMap;
use App\Models\Encounter;
use App\Models\Prescription;
use App\Models\CareRequest;
use App\Services\AuditLogService;
use App\Services\RoundThreeIntegrationService;
use App\Services\TransportRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Throwable;

class IntegrationWebhookController extends Controller
{
    public function vrdsDispatchStatus(Request $request, TransportRequestService $transports): JsonResponse
    {
        $validated = $request->validate([
            'event_id' => ['required', 'string', 'max:191'],
            'external_id' => ['required', 'string', 'max:191'],
            'status' => ['required', 'string', 'max:20'],
            'vehicle_ref' => ['nullable', 'string', 'max:191'],
            'eta_at' => ['nullable', 'date'],
        ]);

        return $this->process(
            $request,
            fn (array $data, IntegrationPartner $partner): array => $transports->applyPartnerStatus(
                $partner,
                $validated,
                (string) $request->attributes->get('request_id')
            ),
            ['external_id', 'status']
        );
    }

    public function hrmsProviderAvailability(Request $request, RoundThreeIntegrationService $roundThree): JsonResponse
    {
        $validated = $request->validate([
            'event_id' => ['required', 'string', 'max:191'],
            'provider_ref' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'version' => ['required', 'integer', 'min:1'],
            'schedule' => ['required', 'array', 'max:14'],
            'schedule.*.day' => ['required', 'integer', 'between:0,6'],
            'schedule.*.start' => ['required', 'date_format:H:i'],
            'schedule.*.end' => ['required', 'date_format:H:i'],
            'unavailable' => ['required', 'array', 'max:50'],
            'unavailable.*.starts_at' => ['required', 'date'],
            'unavailable.*.ends_at' => ['required', 'date'],
            'unavailable.*.reason_code' => ['required', 'in:SICK_LEAVE,EMERGENCY,OTHER'],
        ]);
        foreach ($validated['schedule'] as $schedule) {
            if ($schedule['end'] <= $schedule['start']) {
                return response()->json([
                    'success' => false,
                    'code' => 'invalid_schedule_period',
                    'message' => 'A schedule end time must be after its start time.',
                    'request_id' => $request->attributes->get('request_id'),
                ], 422);
            }
        }
        foreach ($validated['unavailable'] as $period) {
            if (strtotime($period['ends_at']) <= strtotime($period['starts_at'])) {
                return response()->json([
                    'success' => false,
                    'code' => 'invalid_unavailable_period',
                    'message' => 'An unavailable end time must be after its start time.',
                    'request_id' => $request->attributes->get('request_id'),
                ], 422);
            }
        }

        return $this->process($request, fn (array $data, IntegrationPartner $partner): array => $roundThree->syncProviderAvailability(
            $partner,
            $validated,
            (string) $request->route('externalId'),
            (string) $request->attributes->get('request_id')
        ), ['provider_ref', 'version', 'schedule', 'unavailable']);
    }

    public function hadsExportStatus(Request $request, RoundThreeIntegrationService $roundThree): JsonResponse
    {
        return $this->process($request, fn (array $data, IntegrationPartner $partner): array => $roundThree->updateHadsExportStatus(
            $partner,
            $data,
            (string) $request->attributes->get('request_id')
        ), ['export_id', 'status']);
    }

    public function sasAccountStatus(Request $request, RoundThreeIntegrationService $roundThree): JsonResponse
    {
        return $this->process($request, fn (array $data, IntegrationPartner $partner): array => $roundThree->updateSasAccountStatus(
            $partner,
            $data,
            (string) $request->attributes->get('request_id')
        ), ['external_id', 'status']);
    }

    public function sasUserProvisioning(Request $request, RoundThreeIntegrationService $roundThree): JsonResponse
    {
        return $this->process($request, fn (array $data, IntegrationPartner $partner): array => $roundThree->mapSasUserRole(
            $partner,
            $data,
            (string) $request->route('externalId'),
            (string) $request->attributes->get('request_id')
        ), ['external_id', 'role']);
    }

    public function lisResults(Request $request, AuditLogService $audit): JsonResponse
    {
        return $this->careRequestResult($request, $audit, 'lis', 'lab');
    }

    public function risResults(Request $request, AuditLogService $audit): JsonResponse
    {
        return $this->careRequestResult($request, $audit, 'ris', 'imaging');
    }

    public function pmsDispenseStatus(Request $request, AuditLogService $audit): JsonResponse
    {
        return $this->process($request, function (array $data, IntegrationPartner $partner) use ($audit): array {
            if ($partner->code !== 'pms') {
                return $this->errorResult(403, 'partner_forbidden', 'This webhook is not authorized for this partner.');
            }
            $status = strtoupper($data['status']);
            if (! in_array($status, ['SENT', 'ACCEPTED', 'REJECTED', 'PARTIALLY_DISPENSED', 'DISPENSED', 'CANCELLED'], true)) {
                return $this->errorResult(422, 'invalid_status', 'Prescription status is not supported.');
            }
            $prescription = Prescription::query()->where('external_id', $data['external_id'])->lockForUpdate()->first();
            if (! $prescription) {
                return $this->errorResult(404, 'external_id_not_found', 'The external prescription reference was not found.');
            }
            if (! $this->validPrescriptionTransition($prescription->partner_status, $status)
                || ($prescription->dispensed_at && ! in_array($status, ['PARTIALLY_DISPENSED', 'DISPENSED'], true))) {
                $audit->log('INTEGRATION_STATUS_REJECTED', 'prescription', $prescription->id, 'FAILURE', [
                    'partner' => $partner->code,
                    'from_status' => $prescription->partner_status,
                    'to_status' => $status,
                ]);

                return $this->errorResult(409, 'invalid_status_transition', 'The prescription status transition is not allowed.');
            }

            $attributes = [
                'partner_status' => $status,
                'partner_status_updated_at' => now(),
            ];
            if (in_array($status, ['PARTIALLY_DISPENSED', 'DISPENSED'], true)) {
                $attributes['dispensed_at'] = $prescription->dispensed_at ?? now();
            }
            $prescription->update($attributes);

            return ['status' => 200, 'body' => ['success' => true, 'data' => ['status' => $status]]];
        });
    }

    public function emrsClinicalDocument(Request $request, AuditLogService $audit): JsonResponse
    {
        return $this->process($request, function (array $data, IntegrationPartner $partner) use ($audit): array {
            if ($partner->code !== 'emrs') {
                return $this->errorResult(403, 'partner_forbidden', 'This webhook is not authorized for this partner.');
            }
            if (! preg_match('/^[A-Za-z0-9._:-]+$/', $data['encounter_ref'])
                || ! preg_match('/^[A-Za-z0-9._:-]+$/', $data['document_ref'])) {
                return $this->errorResult(422, 'invalid_document_reference', 'Opaque encounter and document references are required.');
            }
            if (! in_array(strtoupper($data['status']), ['AVAILABLE', 'RECEIVED', 'REJECTED'], true)) {
                return $this->errorResult(422, 'invalid_status', 'Clinical document status is not supported.');
            }
            $mapping = IntegrationReferenceMap::query()
                ->where('integration_partner_id', $partner->id)
                ->where('resource_type', 'encounter')
                ->where('emr_reference', $data['encounter_ref'])
                ->first();
            if (! $mapping || ! Encounter::query()->whereKey($mapping->resource_id)->exists()) {
                return $this->errorResult(404, 'encounter_ref_not_found', 'The partner encounter reference was not found.');
            }
            $document = IntegrationClinicalDocument::query()
                ->where('integration_partner_id', $partner->id)
                ->where('document_reference', $data['document_ref'])
                ->lockForUpdate()
                ->first();
            if ($document && (int) $document->encounter_id !== (int) $mapping->resource_id) {
                $audit->log('INTEGRATION_DOCUMENT_CONFLICT', 'encounter', $mapping->resource_id, 'FAILURE', [
                    'partner' => $partner->code,
                ]);

                return $this->errorResult(409, 'document_link_conflict', 'This document reference is already linked to another encounter.');
            }
            if ($document) {
                return ['status' => 200, 'body' => ['success' => true, 'data' => ['status' => $document->status]]];
            }
            IntegrationClinicalDocument::create([
                'integration_partner_id' => $partner->id,
                'encounter_id' => $mapping->resource_id,
                'document_reference' => $data['document_ref'],
                'status' => strtoupper($data['status']),
            ]);

            return ['status' => 200, 'body' => ['success' => true, 'data' => ['status' => strtoupper($data['status'])]]];
        }, ['encounter_ref', 'document_ref', 'status']);
    }

    private function careRequestResult(Request $request, AuditLogService $audit, string $partnerCode, string $type): JsonResponse
    {
        return $this->process($request, function (array $data, IntegrationPartner $partner) use ($audit, $partnerCode, $type): array {
            if ($partner->code !== $partnerCode) {
                return $this->errorResult(403, 'partner_forbidden', 'This webhook is not authorized for this partner.');
            }
            $status = strtoupper($data['status']);
            if (! in_array($status, ['REQUESTED', 'ACCEPTED', 'IN_PROGRESS', 'RESULTED', 'CANCELLED', 'REJECTED'], true)) {
                return $this->errorResult(422, 'invalid_status', 'Care request status is not supported.');
            }
            if ($status === 'RESULTED' && blank($data['result_ref'] ?? $data['document_ref'] ?? null)) {
                return $this->errorResult(422, 'result_reference_required', 'A result or document reference is required for RESULTED status.');
            }
            $resultReference = $data['result_ref'] ?? $data['document_ref'] ?? null;
            if ($resultReference !== null && (
                ! is_string($resultReference)
                || strlen($resultReference) > 191
                || ! preg_match('/^[A-Za-z0-9._:-]+$/', $resultReference)
            )) {
                return $this->errorResult(422, 'invalid_result_reference', 'The result reference is invalid.');
            }
            $careRequest = CareRequest::query()
                ->where('external_id', $data['external_id'])
                ->where('external_partner_code', $partnerCode)
                ->lockForUpdate()
                ->first();
            if (! $careRequest || $careRequest->type !== $type) {
                return $this->errorResult(404, 'external_id_not_found', 'The external care-request reference was not found.');
            }
            if (! $this->validCareRequestTransition($careRequest->partner_status, $status)) {
                $audit->log('INTEGRATION_STATUS_REJECTED', 'care_request', $careRequest->id, 'FAILURE', [
                    'partner' => $partnerCode,
                    'from_status' => $careRequest->partner_status,
                    'to_status' => $status,
                ]);

                return $this->errorResult(409, 'invalid_status_transition', 'The care-request status transition is not allowed.');
            }
            if ($status === 'RESULTED' && (
                (filled($careRequest->result_text) && $careRequest->result_reference !== $resultReference)
                || ($careRequest->result_reference !== null && $careRequest->result_reference !== $resultReference)
            )) {
                $audit->log('INTEGRATION_RESULT_CONFLICT', 'care_request', $careRequest->id, 'FAILURE', [
                    'partner' => $partnerCode,
                ]);

                return $this->errorResult(409, 'result_conflict', 'A local result already exists; the partner result was not linked.');
            }
            $careRequest->update([
                'partner_status' => $status,
                'partner_status_updated_at' => now(),
                'result_reference' => $status === 'RESULTED' ? $resultReference : $careRequest->result_reference,
            ]);

            return ['status' => 200, 'body' => ['success' => true, 'data' => ['status' => $status]]];
        });
    }

    public function paymentStatus(Request $request, AuditLogService $audit): JsonResponse
    {
        return $this->process($request, function (array $data, IntegrationPartner $partner) use ($audit): array {
            $status = strtoupper($data['status']);
            if (! in_array($status, ['REQUESTED', 'RECEIVED', 'UNPAID', 'PAID', 'CLEARED', 'REJECTED'], true)) {
                return $this->errorResult(422, 'invalid_status', 'Payment status is not supported.');
            }
            if (in_array($status, ['PAID', 'CLEARED'], true) && blank($data['payment_ref'] ?? null)) {
                return $this->errorResult(422, 'payment_reference_required', 'A payment reference is required for this status.');
            }

            $bill = EncounterBill::query()->where('integration_external_id', $data['external_id'])->lockForUpdate()->first();
            $clearance = BillingClearanceRequest::query()->where('integration_external_id', $data['external_id'])->lockForUpdate()->first();
            if (! $bill && ! $clearance) {
                return $this->errorResult(404, 'external_id_not_found', 'The external billing reference was not found.');
            }

            $entity = $clearance ?? $bill;
            $current = $entity->integration_status;
            if (! $this->validPaymentTransition($current, $status, $clearance !== null)) {
                $audit->log('INTEGRATION_STATUS_REJECTED', 'billing_integration', $entity->id, 'FAILURE', [
                    'partner' => $partner->code,
                    'from_status' => $current,
                    'to_status' => $status,
                ]);
                return $this->errorResult(409, 'invalid_status_transition', 'The payment status transition is not allowed.');
            }

            if ($bill && $status === 'PAID') {
                $bill->update([
                    'status' => EncounterBill::STATUS_PAID,
                    'amount_paid' => $bill->total,
                    'payment_reference' => $data['payment_ref'],
                    'paid_at' => $bill->paid_at ?? now(),
                    'integration_status' => $status,
                ]);
            } elseif ($bill) {
                $bill->update(['integration_status' => $status]);
            }

            if ($clearance && in_array($status, ['CLEARED', 'REJECTED'], true)) {
                $clearance->update(['status' => $status, 'integration_status' => $status]);
                $audit->log('DECIDE_BILLING_CLEARANCE', 'billing_clearance_request', $clearance->id, 'SUCCESS', [
                    'decision' => $status,
                    'partner' => $partner->code,
                    'has_payment_reference' => filled($data['payment_ref'] ?? null),
                ]);
            } elseif ($clearance) {
                $clearance->update(['integration_status' => $status]);
            }

            return [
                'status' => 200,
                'body' => ['success' => true, 'data' => ['status' => $status]],
            ];
        });
    }

    public function loaStatus(Request $request, AuditLogService $audit): JsonResponse
    {
        return $this->process($request, function (array $data, IntegrationPartner $partner) use ($audit): array {
            $status = strtoupper($data['status']);
            if (! in_array($status, ['PENDING', 'APPROVED', 'DENIED', 'EXPIRED', 'CANCELLED'], true)) {
                return $this->errorResult(422, 'invalid_status', 'LOA status is not supported.');
            }
            if ($status === 'APPROVED' && (! isset($data['approved_amount']) || ! is_numeric($data['approved_amount']) || (float) $data['approved_amount'] < 0)) {
                return $this->errorResult(422, 'approved_amount_required', 'A non-negative approved amount is required.');
            }

            $record = Admission::query()->where('integration_external_id', $data['external_id'])->lockForUpdate()->first()
                ?? CareRequest::query()->where('integration_external_id', $data['external_id'])->lockForUpdate()->first();
            if (! $record) {
                return $this->errorResult(404, 'external_id_not_found', 'The external LOA reference was not found.');
            }
            if (! $this->validLoaTransition($record->loa_status, $status)) {
                $audit->log('INTEGRATION_LOA_STATUS_REJECTED', $record instanceof Admission ? 'admission' : 'care_request', $record->id, 'FAILURE', [
                    'partner' => $partner->code,
                    'from_status' => $record->loa_status,
                    'to_status' => $status,
                ]);
                return $this->errorResult(409, 'invalid_status_transition', 'The LOA status transition is not allowed.');
            }

            $record->update([
                'integration_status' => $status,
                'loa_status' => $status,
                'loa_approved_amount' => $status === 'APPROVED' ? $data['approved_amount'] : null,
                'loa_decided_at' => in_array($status, ['APPROVED', 'DENIED', 'EXPIRED', 'CANCELLED'], true) ? now() : null,
                'loa_payer_reference' => $data['payer_reference'] ?? null,
            ]);
            $audit->log('UPDATE_LOA_STATUS', $record instanceof Admission ? 'admission' : 'care_request', $record->id, 'SUCCESS', [
                'partner' => $partner->code,
                'status' => $status,
                'has_payer_reference' => filled($data['payer_reference'] ?? null),
            ]);

            return [
                'status' => 200,
                'body' => ['success' => true, 'data' => ['status' => $status]],
            ];
        });
    }

    private function process(Request $request, callable $apply, array $required = ['external_id', 'status']): JsonResponse
    {
        $requestId = (string) $request->attributes->get('request_id');
        $partner = $request->attributes->get('integration_partner');
        $data = $request->json()->all();
        $eventId = (string) ($request->header('X-Event-Id') ?: ($data['event_id'] ?? ''));
        $validData = true;
        foreach ($required as $field) {
            $value = $data[$field] ?? null;
            $validData = $validData && (
                (is_string($value) && trim($value) !== '')
                || is_array($value)
                || is_numeric($value)
            );
        }
        foreach (['external_id' => 191, 'encounter_ref' => 64, 'document_ref' => 191] as $field => $maxLength) {
            if (isset($data[$field]) && (! is_string($data[$field]) || strlen($data[$field]) > $maxLength)) {
                $validData = false;
            }
        }
        if (! $partner instanceof IntegrationPartner || $eventId === '' || strlen($eventId) > 191 || ! $validData) {
            return response()->json([
                'success' => false,
                'code' => 'invalid_webhook_payload',
                'message' => 'Event ID and required webhook fields are required.',
                'request_id' => $requestId,
            ], 422);
        }

        try {
            $result = DB::transaction(function () use ($partner, $eventId, $data, $apply): array {
                $existing = IntegrationEvent::query()
                    ->where('integration_partner_id', $partner->id)
                    ->where('event_id', $eventId)
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    return [
                        'status' => $existing->response_status ?? 200,
                        'body' => $existing->response_body ?? ['success' => true],
                    ];
                }

                $result = $apply($data, $partner);
                IntegrationEvent::create([
                    'integration_partner_id' => $partner->id,
                    'event_id' => $eventId,
                    'response_status' => $result['status'],
                    'response_body' => $result['body'],
                    'processed_at' => now(),
                ]);

                return $result;
            });
        } catch (QueryException $exception) {
            $existing = IntegrationEvent::query()
                ->where('integration_partner_id', $partner->id)
                ->where('event_id', $eventId)
                ->first();
            if ($existing) {
                $result = [
                    'status' => $existing->response_status ?? 200,
                    'body' => $existing->response_body ?? ['success' => true],
                ];
            } else {
                report($exception);
                return response()->json([
                    'success' => false,
                    'code' => 'integration_processing_failed',
                    'message' => 'The partner event could not be processed.',
                    'request_id' => $requestId,
                ], 500);
            }
        } catch (Throwable $exception) {
            report($exception);
            return response()->json([
                'success' => false,
                'code' => 'integration_processing_failed',
                'message' => 'The partner event could not be processed.',
                'request_id' => $requestId,
            ], 500);
        }

        $result['body']['request_id'] = $requestId;

        return response()->json($result['body'], $result['status']);
    }

    private function validCareRequestTransition(?string $from, string $to): bool
    {
        if ($from === $to) {
            return true;
        }

        return in_array($to, match ($from) {
            null, 'REQUESTED' => ['REQUESTED', 'ACCEPTED', 'CANCELLED', 'REJECTED'],
            'ACCEPTED' => ['IN_PROGRESS', 'RESULTED', 'CANCELLED', 'REJECTED'],
            'IN_PROGRESS' => ['RESULTED', 'CANCELLED', 'REJECTED'],
            default => [],
        }, true);
    }

    private function validPrescriptionTransition(?string $from, string $to): bool
    {
        if ($from === $to) {
            return true;
        }

        return in_array($to, match ($from) {
            null => ['SENT', 'ACCEPTED', 'REJECTED', 'CANCELLED'],
            'SENT' => ['ACCEPTED', 'REJECTED', 'CANCELLED'],
            'ACCEPTED' => ['PARTIALLY_DISPENSED', 'DISPENSED', 'REJECTED', 'CANCELLED'],
            'PARTIALLY_DISPENSED' => ['DISPENSED'],
            default => [],
        }, true);
    }

    private function validPaymentTransition(?string $from, string $to, bool $isClearance): bool
    {
        if ($from === $to) {
            return true;
        }

        $allowed = match ($from) {
            null => ['REQUESTED', 'RECEIVED', 'UNPAID'],
            'REQUESTED' => ['RECEIVED', 'UNPAID', 'REJECTED'],
            'RECEIVED' => ['UNPAID', 'PAID', 'REJECTED'],
            'UNPAID' => ['PAID', 'REJECTED'],
            'PAID' => ['CLEARED'],
            default => [],
        };
        if ($isClearance && in_array($from, ['REQUESTED', 'RECEIVED', 'UNPAID', 'PAID'], true)) {
            $allowed[] = 'CLEARED';
            $allowed[] = 'REJECTED';
        }

        return in_array($to, $allowed, true);
    }

    private function validLoaTransition(?string $from, string $to): bool
    {
        if ($from === $to) {
            return true;
        }

        return in_array($to, match ($from) {
            null => ['PENDING'],
            'PENDING' => ['APPROVED', 'DENIED', 'EXPIRED', 'CANCELLED'],
            default => [],
        }, true);
    }

    private function errorResult(int $status, string $code, string $message): array
    {
        return ['status' => $status, 'body' => ['success' => false, 'code' => $code, 'message' => $message]];
    }
}
