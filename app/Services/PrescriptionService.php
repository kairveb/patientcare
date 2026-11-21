<?php

namespace App\Services;

use App\Models\Encounter;
use App\Models\Prescription;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PrescriptionService
{
    public function __construct(
        protected QueueDisplayService $queueDisplay,
        protected AuditLogService $audit,
    ) {}

    public function createForEncounter(Encounter $encounter, array $data, User $prescriber): Prescription
    {
        return DB::transaction(function () use ($encounter, $data, $prescriber): Prescription {
            $prescription = $encounter->prescriptions()->create([
                'telehealth_session_id' => null,
                'patient_id' => $encounter->patient_id,
                'medication_name' => trim($data['medication_name']),
                'dosage' => trim($data['dosage']),
                'instructions' => trim($data['instructions']),
                'prescribed_by' => $prescriber->id,
                'prescribed_at' => now(),
            ]);

            $this->queueDisplay->addPrescription($prescription);
            $this->audit->issuePrescription($prescription->id, $encounter->id);
            app(RoundTwoIntegrationService::class)->enqueuePrescription($prescription);

            return $prescription;
        });
    }
}
