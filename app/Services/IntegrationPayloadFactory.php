<?php

namespace App\Services;

use App\Models\CareRequest;
use App\Models\DischargeSummary;
use App\Models\Encounter;
use App\Models\EncounterBill;
use App\Models\IntegrationOutbox;
use App\Models\IntegrationExport;
use Illuminate\Validation\ValidationException;
use App\Models\Patient;
use App\Models\Prescription;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class IntegrationPayloadFactory
{
    public function __construct(private readonly IntegrationReference $references)
    {
    }

    public function make(IntegrationOutbox $outbox, ?string $requestId = null): array
    {
        $reference = $outbox->payload_reference;
        if (in_array($outbox->entity_type, ['vrds_dispatch_request', 'vrds_dispatch_cancel'], true)) {
            if ($outbox->partner->code !== 'vrds') {
                throw new \LogicException('VRDS transport payloads require the VRDS partner.');
            }
            $transport = \App\Models\TransportRequest::query()->findOrFail($outbox->entity_id);
            $fields = $reference['fields'] ?? ['request_ref'];
            app(IntegrationDisclosureService::class)->recordNoPatient(
                $outbox->partner,
                $reference['purpose'] ?? 'patient transport dispatch',
                $fields,
                $requestId ?: (string) Str::uuid()
            );
            $requestRef = app(IntegrationReferenceMapService::class)->for(
                $outbox->partner,
                'transport_request',
                (int) $transport->id
            );

            if ($outbox->entity_type === 'vrds_dispatch_cancel') {
                return ['request_ref' => $requestRef];
            }

            return [
                'request_ref' => $requestRef,
                'priority' => $transport->priority,
                'pickup_zone' => $transport->pickup_zone,
                'destination_zone' => $transport->destination_zone,
                'needs' => $transport->needs ?? [],
                'passenger_count' => $transport->passenger_count,
            ];
        }
        if ($outbox->entity_type === 'sas_audit_event') {
            return [
                'event_type' => $reference['event_type'],
                'actor_ref' => $reference['actor_ref'] ?? null,
                'timestamp' => $reference['timestamp'],
                'outcome' => $reference['outcome'],
            ];
        }
        if ($outbox->entity_type === 'analytics_export') {
            $export = IntegrationExport::query()
                ->whereKey($outbox->entity_id)
                ->where('integration_partner_id', $outbox->integration_partner_id)
                ->firstOrFail();
            if ($export->status !== 'QUEUED' || ! is_array($export->payload_snapshot)) {
                throw ValidationException::withMessages(['export' => ['This export is expired or unavailable for delivery.']]);
            }
            $snapshot = $export->payload_snapshot;
            $content = app(ReportExportService::class)->render(
                $snapshot,
                $export->format,
                'HADS '.str_replace('_', ' ', $export->dataset),
                ['start' => $export->period_from->toDateString(), 'end' => $export->period_to->toDateString()]
            );

            return [
                'export_id' => $export->export_ref,
                'dataset' => $export->dataset,
                'from' => $export->period_from->toDateString(),
                'to' => $export->period_to->toDateString(),
                'format' => $export->format,
                'row_count' => $export->row_count,
                'content_encoding' => 'base64',
                'content' => base64_encode($content),
            ];
        }
        $entity = match ($outbox->entity_type) {
            'billing_statement' => EncounterBill::query()->with(['encounter', 'admission'])->findOrFail($outbox->entity_id),
            'billing_clearance' => \App\Models\BillingClearanceRequest::query()->with(['admission.bills'])->findOrFail($outbox->entity_id),
            'admission_loa' => \App\Models\Admission::query()->findOrFail($outbox->entity_id),
            'care_request_loa' => CareRequest::query()->findOrFail($outbox->entity_id),
            'care_request_lis', 'care_request_ris' => CareRequest::query()->findOrFail($outbox->entity_id),
            'prescription' => Prescription::query()->findOrFail($outbox->entity_id),
            'emrs_encounter' => Encounter::query()->findOrFail($outbox->entity_id),
            'emrs_discharge_summary' => DischargeSummary::query()
                ->with(['encounter', 'admission', 'erVisit'])
                ->findOrFail($outbox->entity_id),
            default => throw new \LogicException('Unsupported integration outbox entity.'),
        };
        $patient = $this->patientFor($entity);
        $fields = match ($outbox->entity_type) {
            'billing_statement', 'billing_clearance' => ['patient_ref', 'statement_ref', 'encounter_ref', 'admission_ref', 'total', 'currency'],
            'care_request_lis' => ['patient_ref', 'request_ref', 'tests', 'urgency'],
            'care_request_ris' => ['patient_ref', 'request_ref', 'modality', 'urgency'],
            'prescription' => ['patient_ref', 'prescription_ref', 'drug_code', 'quantity', 'instructions'],
            'emrs_encounter' => array_values(array_filter([
                'patient_ref', 'encounter_ref', 'encounter_type', 'started_at', 'ended_at',
                $entity->primary_diagnosis_code ? 'diagnosis_code' : null,
            ])),
            'emrs_discharge_summary' => array_values(array_filter([
                'patient_ref',
                $entity->encounter_id !== null ? 'encounter_ref' : null,
                'discharge_ref',
                'discharged_at',
            ])),
            default => ['patient_ref', 'admission_ref', 'request_ref', 'coverage_code', 'requested_amount'],
        };
        $this->disclose($outbox, $patient, $reference['purpose'], $fields);

        if ($outbox->entity_type === 'care_request_lis') {
            return [
                'patient_ref' => $this->references->for('patient', (int) $patient->id),
                'request_ref' => $this->references->for('care_request', (int) $entity->id),
                'tests' => ['laboratory'],
                'urgency' => $entity->urgency,
            ];
        }
        if ($outbox->entity_type === 'care_request_ris') {
            return [
                'patient_ref' => $this->references->for('patient', (int) $patient->id),
                'request_ref' => $this->references->for('care_request', (int) $entity->id),
                'modality' => 'imaging',
                'urgency' => $entity->urgency,
            ];
        }
        if ($outbox->entity_type === 'prescription') {
            return [
                'patient_ref' => $this->references->for('patient', (int) $patient->id),
                'prescription_ref' => $this->references->for('prescription', (int) $entity->id),
                'items' => [[
                    'drug_code' => trim((string) $entity->medication_name),
                    'quantity' => (int) $entity->quantity,
                    'instructions' => trim((string) $entity->instructions),
                ]],
            ];
        }
        if ($outbox->entity_type === 'emrs_encounter') {
            $partner = $outbox->partner;
            $mapped = app(IntegrationReferenceMapService::class);
            return array_filter([
                'patient_ref' => $mapped->for($partner, 'patient', (int) $patient->id),
                'encounter_ref' => $mapped->for($partner, 'encounter', (int) $entity->id),
                'encounter_type' => $entity->type,
                'started_at' => $entity->started_at?->toIso8601String(),
                'ended_at' => $entity->ended_at?->toIso8601String(),
                'diagnosis_code' => $entity->primary_diagnosis_code ?: null,
            ], static fn ($value) => $value !== null);
        }
        if ($outbox->entity_type === 'emrs_discharge_summary') {
            $partner = $outbox->partner;
            $mapped = app(IntegrationReferenceMapService::class);
            $encounter = $entity->encounter;
            $dischargedAt = $entity->admission?->discharge?->discharged_at
                ?? $encounter?->ended_at
                ?? $entity->erVisit?->updated_at;

            return array_filter([
                'patient_ref' => $mapped->for($partner, 'patient', (int) $patient->id),
                'encounter_ref' => $encounter ? $mapped->for($partner, 'encounter', (int) $encounter->id) : null,
                'discharge_ref' => $mapped->for($partner, 'discharge_summary', (int) $entity->id),
                'discharged_at' => $dischargedAt?->toIso8601String(),
            ], static fn ($value) => $value !== null && $value !== '');
        }

        if ($outbox->partner->code === 'bdms') {
            $bill = $entity instanceof EncounterBill
                ? $entity
                : ($entity instanceof \App\Models\BillingClearanceRequest ? $entity->admission->bills->first() : null);
            $total = $bill?->total;
            if ($total === null && $entity instanceof \App\Models\BillingClearanceRequest) {
                $total = app(BillingAggregationService::class)->previewForAdmission($entity->admission)['total'];
            }

            return [
                'patient_ref' => $this->references->for('patient', (int) $patient->id),
                'statement_ref' => $this->references->for('statement', $bill?->id ?? $entity->id),
                'encounter_ref' => $bill?->encounter_id
                    ? $this->references->for('encounter', (int) $bill->encounter_id)
                    : null,
                'admission_ref' => $bill?->admission_id
                    ? $this->references->for('admission', (int) $bill->admission_id)
                    : ($entity instanceof \App\Models\BillingClearanceRequest
                        ? $this->references->for('admission', (int) $entity->admission_id)
                        : null),
                'total' => $total ?? '0.00',
                'currency' => $bill?->currency ?? 'PHP',
            ];
        }

        return [
            'patient_ref' => $this->references->for('patient', (int) $patient->id),
            'admission_ref' => $entity instanceof \App\Models\Admission
                ? $this->references->for('admission', (int) $entity->id)
                : null,
            'request_ref' => $entity instanceof CareRequest
                ? $this->references->for('care_request', (int) $entity->id)
                : null,
            'coverage_code' => $entity->coverage_code,
            'requested_amount' => (string) $entity->loa_requested_amount,
        ];
    }

    private function patientFor(Model $entity): Patient
    {
        if ($entity instanceof EncounterBill) {
            return $entity->patient;
        }
        if ($entity instanceof \App\Models\BillingClearanceRequest) {
            return $entity->admission->patient;
        }
        if ($entity instanceof DischargeSummary) {
            return $entity->encounter?->patient
                ?? $entity->admission?->patient
                ?? $entity->erVisit->patient;
        }

        return $entity->patient;
    }

    private function disclose(IntegrationOutbox $outbox, Patient $patient, string $purpose, array $fields): void
    {
        app(IntegrationDisclosureService::class)->record(
            $outbox->partner,
            $patient,
            $purpose,
            $fields,
            (string) ($outbox->payload_reference['request_id'] ?? $outbox->idempotency_key)
        );
    }
}
