<?php

namespace App\Services;

use App\Contracts\Icd10CodeValidator;
use App\Models\Appointment;
use App\Models\Encounter;
use App\Models\EncounterNote;
use App\Models\User;
use App\Models\Vital;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EncounterService
{
    protected ?AppointmentService $appointments;

    protected Icd10CodeValidator $icd10Codes;

    public function __construct(
        protected AuditLogService $audit,
        ?AppointmentService $appointments = null,
        ?Icd10CodeValidator $icd10Codes = null,
    ) {
        $this->appointments = $appointments;
        $this->icd10Codes = $icd10Codes ?? app(Icd10CodeValidator::class);
    }

    /**
     * Generate the next encounter number.
     */
    public function generateNumber(): string
    {
        $year = now()->format('Y');
        $candidates = Encounter::where('encounter_number', 'like', "ENC-{$year}-%")
            ->lockForUpdate()
            ->pluck('encounter_number');

        $maxSequence = 0;
        foreach ($candidates as $number) {
            if (preg_match('/^ENC-'.preg_quote($year, '/').'-(\d{6})$/', $number, $matches)) {
                $maxSequence = max($maxSequence, (int) $matches[1]);
            }
        }

        return sprintf('ENC-%s-%06d', $year, $maxSequence + 1);
    }

    /**
     * Create an encounter (outpatient, telehealth, or emergency).
     */
    public function create(array $data): Encounter
    {
        return DB::transaction(function () use ($data) {
            $appointment = null;
            if (! empty($data['appointment_id'])) {
                $appointment = Appointment::query()->lockForUpdate()->findOrFail($data['appointment_id']);
                if (
                    (int) $appointment->patient_id !== (int) $data['patient_id']
                    || (int) $appointment->provider_id !== (int) $data['provider_id']
                    || $appointment->status !== Appointment::STATUS_CHECKED_IN
                ) {
                    throw ValidationException::withMessages([
                        'appointment_id' => ['The linked appointment must belong to this patient and provider and be checked in.'],
                    ]);
                }
            }

            $encounter = $this->createEncounterRecord($data);
            if ($appointment) {
                $this->appointments()->transitionStatus(
                    $appointment,
                    Appointment::STATUS_IN_CONSULTATION,
                    auth()->id(),
                    'OPD encounter started'
                );
                $this->attachAppointmentVitals($encounter, $appointment);
            }
            $this->attachOptionalVitals($encounter, $data['vitals'] ?? null);
            $this->attachOptionalNotes($encounter, $data['notes'] ?? null);
            $this->audit->createEncounter($encounter->id);

            return $encounter;
        });
    }

    protected function createEncounterRecord(array $data): Encounter
    {
        $diagnosisCode = $data['primary_diagnosis_code'] ?? null;
        $this->assertValidDiagnosisCode($diagnosisCode);
        if (is_string($diagnosisCode) && trim($diagnosisCode) === '') {
            $diagnosisCode = null;
        }

        $status = $data['status'] ?? 'OPEN';
        if ($status === 'COMPLETED') {
            $this->validateCompletionDocumentation(
                $data['assessment'] ?? null,
                $data['plan'] ?? null,
                $data['history_of_present_illness'] ?? null,
                $data['physical_examination'] ?? null,
                $data['primary_diagnosis_code'] ?? null,
                $data['primary_diagnosis_description'] ?? null,
            );
        }

        return Encounter::create([
            'encounter_number' => $this->generateNumber(),
            'patient_id' => $data['patient_id'],
            'provider_id' => $data['provider_id'],
            'appointment_id' => $data['appointment_id'] ?? null,
            'type' => $data['type'],
            'started_at' => $data['started_at'] ?? now(),
            'ended_at' => $data['ended_at'] ?? null,
            'chief_complaint' => $data['chief_complaint'] ?? null,
            'history_of_present_illness' => $data['history_of_present_illness'] ?? null,
            'physical_examination' => $data['physical_examination'] ?? null,
            'primary_diagnosis_code' => $diagnosisCode === null ? null : strtoupper(trim($diagnosisCode)),
            'primary_diagnosis_description' => $data['primary_diagnosis_description'] ?? null,
            'assessment' => $data['assessment'] ?? null,
            'plan' => $data['plan'] ?? null,
            'follow_up_date' => $data['follow_up_date'] ?? null,
            'status' => $status,
        ]);
    }

    protected function attachOptionalVitals(Encounter $encounter, ?array $vitals): void
    {
        if (empty($vitals) || $encounter->vitals()->exists()) {
            return;
        }

        $this->recordVitals($encounter, $vitals);
    }

    protected function attachAppointmentVitals(Encounter $encounter, Appointment $appointment): void
    {
        Vital::query()->where('appointment_id', $appointment->id)
            ->whereNull('encounter_id')
            ->update(['encounter_id' => $encounter->id]);
    }

    protected function attachOptionalNotes(Encounter $encounter, ?string $notes): void
    {
        if (empty($notes)) {
            return;
        }

        $this->addNote($encounter, $notes, auth()->id());
    }

    public function recordVitals(Encounter $encounter, array $vitals): Vital
    {
        return Vital::create([
            'encounter_id' => $encounter->id,
            'patient_id' => $encounter->patient_id,
            'recorded_by' => auth()->id(),
            'blood_pressure' => $vitals['blood_pressure'] ?? null,
            'heart_rate' => $vitals['heart_rate'] ?? null,
            'respiratory_rate' => $vitals['respiratory_rate'] ?? null,
            'temperature' => $vitals['temperature'] ?? null,
            'spo2' => $vitals['spo2'] ?? null,
            'weight' => $vitals['weight'] ?? null,
            'pain_score' => $vitals['pain_score'] ?? null,
            'recorded_at' => now(),
        ]);
    }

    public function addNote(Encounter $encounter, string $body, ?int $authorId = null): EncounterNote
    {
        return EncounterNote::create([
            'encounter_id' => $encounter->id,
            'author_id' => $authorId ?? auth()->id(),
            'body' => $body,
        ]);
    }

    public function complete(Encounter $encounter, array $data, User $completingUser): Encounter
    {
        return DB::transaction(function () use ($encounter, $data, $completingUser): Encounter {
            $lockedEncounter = Encounter::query()
                ->with('appointment')
                ->lockForUpdate()
                ->findOrFail($encounter->id);

            abort_unless(
                $completingUser->hasRole('doctor')
                    && (int) $completingUser->provider?->id === (int) $lockedEncounter->provider_id,
                403,
                'Only the assigned doctor may complete this encounter.'
            );

            if ($lockedEncounter->status !== Encounter::STATUS_OPEN) {
                throw ValidationException::withMessages([
                    'status' => ['Only an open encounter may be completed.'],
                ]);
            }

            $assessment = $data['assessment'] ?? $lockedEncounter->assessment;
            $plan = $data['plan'] ?? $lockedEncounter->plan;
            $hpi = $data['history_of_present_illness'] ?? $lockedEncounter->history_of_present_illness;
            $physicalExam = $data['physical_examination'] ?? $lockedEncounter->physical_examination;
            $diagnosisCode = $data['primary_diagnosis_code'] ?? $lockedEncounter->primary_diagnosis_code;
            $diagnosisDescription = $data['primary_diagnosis_description'] ?? $lockedEncounter->primary_diagnosis_description;
            $this->assertValidDiagnosisCode($diagnosisCode);
            $diagnosisCode = strtoupper(trim((string) $diagnosisCode));
            $this->validateCompletionDocumentation($assessment, $plan, $hpi, $physicalExam, $diagnosisCode, $diagnosisDescription);

            $lockedEncounter->update([
                'history_of_present_illness' => $hpi,
                'physical_examination' => $physicalExam,
                'primary_diagnosis_code' => $diagnosisCode,
                'primary_diagnosis_description' => $diagnosisDescription,
                'assessment' => $assessment,
                'plan' => $plan,
                'follow_up_date' => $data['follow_up_date'] ?? $lockedEncounter->follow_up_date,
                'ended_at' => now(),
                'status' => Encounter::STATUS_COMPLETED,
            ]);

            if ($lockedEncounter->appointment_id) {
                $appointment = Appointment::query()->lockForUpdate()->findOrFail($lockedEncounter->appointment_id);
                if ($appointment->status === Appointment::STATUS_CHECKED_IN) {
                    $appointment = $this->appointments()->transitionStatus(
                        $appointment,
                        Appointment::STATUS_IN_CONSULTATION,
                        $completingUser->id,
                        'OPD encounter consultation recorded'
                    );
                }
                if ($appointment->status === Appointment::STATUS_IN_CONSULTATION) {
                    $this->appointments()->transitionStatus(
                        $appointment,
                        Appointment::STATUS_COMPLETED,
                        $completingUser->id,
                        'OPD encounter completed'
                    );
                } elseif ($appointment->status === Appointment::STATUS_COMPLETED) {
                    $this->appointments()->syncQueueDisplay($appointment);
                }
            }

            $this->audit->completeEncounter($lockedEncounter->id);
            app(RoundTwoIntegrationService::class)->enqueueCompletedEncounter($lockedEncounter);

            return $lockedEncounter->refresh();
        });
    }

    private function assertValidDiagnosisCode(mixed $code): void
    {
        if ($code === null || $code === '') {
            return;
        }

        if (! is_string($code) || ! $this->icd10Codes->isValid($code)) {
            throw ValidationException::withMessages([
                'primary_diagnosis_code' => ['The primary diagnosis code must match the ICD-10 code format.'],
            ]);
        }
    }

    private function appointments(): AppointmentService
    {
        return $this->appointments ?? app(AppointmentService::class);
    }

    private function validateCompletionDocumentation(
        ?string $assessment,
        ?string $plan,
        ?string $hpi = null,
        ?string $physicalExam = null,
        ?string $diagnosisCode = null,
        ?string $diagnosisDescription = null,
    ): void {
        $errors = [];

        if (blank($assessment)) {
            $errors['assessment'] = 'Assessment is required before completing an encounter.';
        }

        if (blank($plan)) {
            $errors['plan'] = 'Plan is required before completing an encounter.';
        }

        if (blank($hpi)) {
            $errors['history_of_present_illness'] = 'History of Present Illness is required before completing an encounter.';
        }

        if (blank($physicalExam)) {
            $errors['physical_examination'] = 'Physical Examination is required before completing an encounter.';
        }

        if (blank($diagnosisCode)) {
            $errors['primary_diagnosis_code'] = 'Primary diagnosis code is required before completing an encounter.';
        }

        if (blank($diagnosisDescription)) {
            $errors['primary_diagnosis_description'] = 'Primary diagnosis description is required before completing an encounter.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
