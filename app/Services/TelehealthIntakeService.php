<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\TelehealthAiSummary;
use App\Models\TelehealthIntake;
use App\Models\TelehealthSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TelehealthIntakeService
{
    public function __construct(
        protected AiTriageService $aiTriage,
        protected AuditLogService $audit,
        protected EncounterService $encounters,
        protected PatientConsentService $consents
    ) {}

    public function saveIntake(TelehealthSession $session, User $patientUser, array $data): TelehealthIntake
    {
        abort_unless(
            $patientUser->hasRole('patient')
                && $patientUser->patient?->id === $session->appointment?->patient_id,
            403
        );

        $patient = $session->appointment->patient;
        abort_unless(
            $this->consents->hasCurrentGrant($patient, 'AI_ASSISTED'),
            403,
            'Current AI-assisted care consent is required before submitting telehealth intake.'
        );
        abort_if(
            $session->aiSummary()->exists(),
            409,
            'The intake cannot be changed after the doctor starts a consultation summary.'
        );
        $vitals = array_filter($data['vitals'] ?? [], static fn ($value): bool => $value !== null && $value !== '');
        $symptoms = array_values(array_filter(array_map(
            'trim',
            preg_split('/[,;\n]+/', $data['symptoms']) ?: []
        )));
        $analysis = $this->aiTriage->analyze([
            'chief_complaint' => $data['symptoms'],
            'symptoms' => $symptoms,
            'age' => $patient->age,
            'pain_score' => (int) $data['severity'],
            'vitals' => $vitals,
            'pre_arrival_profile' => [
                'visit_reason' => $data['symptoms'].' Duration: '.$data['duration'],
                'allergies' => $data['allergies'] ?? '',
                'current_medications' => $data['current_medications'] ?? '',
            ],
        ]);

        return TelehealthIntake::updateOrCreate(
            ['telehealth_session_id' => $session->id],
            [
                'patient_id' => $patient->id,
                'submitted_by' => $patientUser->id,
                'symptoms' => $data['symptoms'],
                'duration' => $data['duration'],
                'severity' => (int) $data['severity'],
                'allergies' => $data['allergies'] ?? null,
                'current_medications' => $data['current_medications'] ?? null,
                'vitals' => $vitals,
                'ai_urgency' => $analysis['priority'],
                'ai_reasons' => $analysis['reasons'],
                'is_emergency' => $analysis['level'] === AiTriageService::LEVEL_EMERGENCY,
            ]
        );
    }

    public function generateDraft(TelehealthSession $session, User $doctor, string $doctorNotes): TelehealthAiSummary
    {
        $this->assertAssignedDoctor($session, $doctor);
        $intake = $session->intake;
        abort_unless($intake, 422, 'Patient intake must be completed before generating a consultation summary.');
        abort_unless(
            $this->consents->hasCurrentGrant($intake->patient, 'AI_ASSISTED'),
            403,
            'Current AI-assisted care consent is required to generate a consultation summary.'
        );

        $summary = $session->aiSummary;
        abort_if($summary?->status === TelehealthAiSummary::STATUS_APPROVED, 409, 'An approved consultation summary is immutable.');
        $urgencyReasons = implode(' ', $intake->ai_reasons ?? []);
        $summaryText = implode("\n", array_filter([
            'Symptoms: '.$intake->symptoms,
            'Duration: '.$intake->duration,
            'Patient-reported severity: '.$intake->severity.'/10',
            'AI urgency: '.$intake->ai_urgency.'. This is decision support only.',
            $urgencyReasons !== '' ? 'AI reasons: '.$urgencyReasons : null,
            filled($intake->allergies) ? 'Allergies reported: '.$intake->allergies : 'Allergies: none reported.',
            filled($intake->current_medications) ? 'Current medications: '.$intake->current_medications : 'Current medications: none reported.',
            'Doctor notes: '.$doctorNotes,
        ]));

        $summary = TelehealthAiSummary::updateOrCreate(
            ['telehealth_session_id' => $session->id],
            [
                'patient_id' => $intake->patient_id,
                'generated_by' => $doctor->id,
                'doctor_notes' => $doctorNotes,
                'summary_text' => $summaryText,
                'status' => TelehealthAiSummary::STATUS_DRAFT,
                'generated_at' => now(),
                'approved_by' => null,
                'approved_at' => null,
                'encounter_id' => null,
            ]
        );

        $this->audit->log(
            'GENERATE_TELEHEALTH_AI_SUMMARY',
            'telehealth_ai_summary',
            $summary->id,
            'SUCCESS',
            ['telehealth_session_id' => $session->id, 'status' => $summary->status],
            $doctor->id
        );

        return $summary;
    }

    public function updateDraft(
        TelehealthSession $session,
        TelehealthAiSummary $summary,
        User $doctor,
        string $summaryText
    ): TelehealthAiSummary {
        $this->assertAssignedDoctor($session, $doctor);
        abort_unless($summary->telehealth_session_id === $session->id, 404);
        abort_unless($summary->status === TelehealthAiSummary::STATUS_DRAFT, 409, 'Only a draft consultation summary can be edited.');

        $summary->update(['summary_text' => $summaryText]);
        $this->audit->log(
            'EDIT_TELEHEALTH_AI_SUMMARY',
            'telehealth_ai_summary',
            $summary->id,
            'SUCCESS',
            ['telehealth_session_id' => $session->id],
            $doctor->id
        );

        return $summary->fresh();
    }

    public function approveDraft(
        TelehealthSession $session,
        TelehealthAiSummary $summary,
        User $doctor
    ): TelehealthAiSummary {
        $this->assertAssignedDoctor($session, $doctor);

        return DB::transaction(function () use ($session, $summary, $doctor): TelehealthAiSummary {
            $lockedSummary = TelehealthAiSummary::query()->lockForUpdate()->findOrFail($summary->id);
            abort_unless($lockedSummary->telehealth_session_id === $session->id, 404);
            abort_unless($lockedSummary->status === TelehealthAiSummary::STATUS_DRAFT, 409, 'Only a draft consultation summary can be approved.');

            $appointment = Appointment::query()
                ->with('provider')
                ->lockForUpdate()
                ->findOrFail($session->appointment_id);
            $encounter = Encounter::firstOrCreate([
                'appointment_id' => $appointment->id,
                'patient_id' => $appointment->patient_id,
            ], [
                'encounter_number' => $this->encounters->generateNumber(),
                'provider_id' => $appointment->provider_id,
                'type' => Encounter::TYPE_TELEHEALTH,
                'started_at' => $session->start_time ?? now(),
                'status' => Encounter::STATUS_COMPLETED,
                'chief_complaint' => $appointment->appointmentType?->name ?? 'Telehealth consultation',
            ]);

            $lockedSummary->update([
                'encounter_id' => $encounter->id,
                'status' => TelehealthAiSummary::STATUS_APPROVED,
                'approved_by' => $doctor->id,
                'approved_at' => now(),
            ]);
            $this->audit->log(
                'APPROVE_TELEHEALTH_AI_SUMMARY',
                'telehealth_ai_summary',
                $lockedSummary->id,
                'SUCCESS',
                ['telehealth_session_id' => $session->id, 'encounter_id' => $encounter->id],
                $doctor->id
            );

            return $lockedSummary->fresh();
        });
    }

    public function assertAssignedDoctor(TelehealthSession $session, User $doctor): void
    {
        abort_unless(
            $doctor->hasRole('doctor')
                && $doctor->provider?->id === $session->appointment?->provider_id,
            403,
            'Only the doctor assigned to this telehealth session may manage its consultation summary.'
        );
    }
}
