<?php

namespace Tests\Feature\Api;

use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\Provider;
use App\Models\Role;
use App\Models\TelehealthAiSummary;
use App\Models\TelehealthIntake;
use App\Models\TelehealthSession;
use App\Models\User;
use Database\Seeders\HimsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Services\PatientConsentService;
use Tests\TestCase;

class TelehealthAiIntakeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(HimsSeeder::class);
    }

    public function test_patient_submits_encrypted_intake_and_staff_can_read_it(): void
    {
        [$patientUser, , $session] = $this->telehealthSession();
        $payload = $this->intakePayload();

        $this->actingAs($patientUser)->postJson("/api/v1/telehealth/{$session->id}/intake", $payload)
            ->assertCreated()
            ->assertJsonPath('data.ai_urgency', 'Emergency')
            ->assertJsonPath('data.is_emergency', true)
            ->assertJsonPath('next', 'emergency-instructions');

        $intake = TelehealthIntake::firstOrFail();
        $row = DB::table('telehealth_intakes')->where('id', $intake->id)->first();
        $this->assertNotSame($payload['allergies'], $row->allergies);
        $this->assertNotSame($payload['current_medications'], $row->current_medications);
        $this->assertSame($payload['allergies'], $intake->fresh()->allergies);
        $this->assertSame($payload['current_medications'], $intake->fresh()->current_medications);

        $nurse = $this->userWithRole('nurse');
        $this->actingAs($nurse)->getJson("/api/v1/telehealth/{$session->id}/intake")
            ->assertOk()
            ->assertJsonPath('data.ai_urgency', 'Emergency');

        $registration = $this->userWithRole('registration');
        $this->actingAs($registration)->getJson("/api/v1/telehealth/{$session->id}/intake")->assertForbidden();
        $this->actingAs($patientUser)->getJson("/api/v1/telehealth/{$session->id}/emergency-instructions")
            ->assertOk()
            ->assertJsonPath('data.is_emergency', true);
    }

    public function test_intake_validates_patient_ownership_and_vitals(): void
    {
        [$patientUser, , $session] = $this->telehealthSession();

        $this->actingAs($patientUser)
            ->postJson("/api/v1/telehealth/{$session->id}/intake", [
                'symptoms' => 'cough',
                'duration' => 'two days',
                'severity' => 11,
                'vitals' => ['gcs' => 2],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['severity', 'vitals.gcs']);

        $otherPatient = $this->userWithRole('patient');
        $this->actingAs($otherPatient)
            ->postJson("/api/v1/telehealth/{$session->id}/intake", $this->intakePayload())
            ->assertForbidden();
        $this->assertSame(0, TelehealthIntake::count());
    }

    public function test_intake_uses_the_linked_patient_age_for_age_adjusted_ai_triage(): void
    {
        [$patientUser, , $session] = $this->telehealthSession();
        $session->appointment->patient->update([
            'date_of_birth' => now()->subYears(70)->toDateString(),
        ]);

        $this->actingAs($patientUser)
            ->postJson("/api/v1/telehealth/{$session->id}/intake", $this->intakePayload())
            ->assertCreated();

        $this->assertStringContainsString(
            'Geriatric age-adjusted vital-sign thresholds are provisional and require clinical validation.',
            implode(' ', TelehealthIntake::firstOrFail()->ai_reasons)
        );
    }

    public function test_assigned_doctor_generates_edits_and_approves_only_after_review(): void
    {
        [$patientUser, $doctor, $session] = $this->telehealthSession();
        $this->actingAs($patientUser)
            ->postJson("/api/v1/telehealth/{$session->id}/intake", $this->intakePayload())
            ->assertCreated();

        $this->actingAs($doctor)->get(route('telehealth.show', $session))
            ->assertOk()
            ->assertSee('AI intake indicates emergency urgency')
            ->assertSee('AI-generated draft, doctor must review');

        $summaryUrl = "/api/v1/telehealth/{$session->id}/ai-summary";
        $this->actingAs($doctor)->postJson($summaryUrl, ['doctor_notes' => 'Patient alert and speaking in full sentences.'])
            ->assertOk()
            ->assertJsonPath('data.status', TelehealthAiSummary::STATUS_DRAFT);

        $summary = TelehealthAiSummary::firstOrFail();
        $this->assertStringContainsString('Patient alert and speaking in full sentences.', $summary->summary_text);
        $this->assertDatabaseMissing('encounters', ['appointment_id' => $session->appointment_id]);
        $rawSummary = DB::table('telehealth_ai_summaries')->where('id', $summary->id)->value('summary_text');
        $this->assertStringNotContainsString('Patient alert and speaking in full sentences.', $rawSummary);

        $this->actingAs($patientUser)->getJson($summaryUrl)->assertNotFound();

        $nurse = $this->userWithRole('nurse');
        $this->actingAs($nurse)->getJson($summaryUrl)
            ->assertOk()
            ->assertJsonPath('data.status', TelehealthAiSummary::STATUS_DRAFT);
        $this->actingAs($nurse)->postJson($summaryUrl, ['doctor_notes' => 'Not allowed.'])->assertForbidden();

        $editedText = "Reviewed by doctor.\nNo acute distress on video.";
        $this->actingAs($doctor)->patchJson($summaryUrl, ['summary_text' => $editedText])
            ->assertOk()
            ->assertJsonPath('data.summary_text', $editedText);
        $this->actingAs($doctor)->postJson($summaryUrl.'/approve')->assertOk()
            ->assertJsonPath('data.status', TelehealthAiSummary::STATUS_APPROVED);

        $approved = $summary->fresh();
        $encounter = Encounter::findOrFail($approved->encounter_id);
        $this->assertSame($session->appointment_id, $encounter->appointment_id);
        $this->assertSame($patientUser->patient->id, $encounter->patient_id);
        $this->assertSame($doctor->id, $approved->approvedBy?->id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'GENERATE_TELEHEALTH_AI_SUMMARY', 'resource_id' => $summary->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'EDIT_TELEHEALTH_AI_SUMMARY', 'resource_id' => $summary->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'APPROVE_TELEHEALTH_AI_SUMMARY', 'resource_id' => $summary->id]);

        $this->actingAs($patientUser)->getJson($summaryUrl)
            ->assertOk()
            ->assertJsonPath('data.summary_text', $editedText);
        $this->actingAs($patientUser)->get(route('patients.portal.telehealth.intake', $session))
            ->assertOk()
            ->assertSee('Pre-consultation Intake');
        $this->actingAs($patientUser)->get(route('patients.portal.telehealth.emergency', $session))
            ->assertOk()
            ->assertSee('Do not wait for a virtual appointment');
        $this->actingAs($patientUser)->get(route('patients.portal.history'))
            ->assertOk()
            ->assertSee('Doctor-approved consultation summary')
            ->assertSee('No acute distress on video.');
    }

    public function test_only_the_assigned_doctor_can_manage_summary_and_invalid_notes_are_rejected(): void
    {
        [$patientUser, , $session] = $this->telehealthSession();
        $this->actingAs($patientUser)
            ->postJson("/api/v1/telehealth/{$session->id}/intake", $this->intakePayload())
            ->assertCreated();

        $otherDoctor = $this->userWithRole('doctor');
        Provider::create(['user_id' => $otherDoctor->id, 'display_name' => 'Dr. Unassigned', 'active' => true]);
        $summaryUrl = "/api/v1/telehealth/{$session->id}/ai-summary";

        $this->actingAs($otherDoctor)
            ->postJson($summaryUrl, ['doctor_notes' => 'Short notes.'])
            ->assertForbidden();
        $this->assertSame(0, TelehealthAiSummary::count());

        $assignedDoctor = $session->appointment->provider->user;
        $this->actingAs($assignedDoctor)->postJson($summaryUrl, ['doctor_notes' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('doctor_notes');
        $this->assertSame(0, TelehealthAiSummary::count());
    }

    private function telehealthSession(): array
    {
        $patientUser = $this->userWithRole('patient');
        $doctor = $this->userWithRole('doctor');
        $patient = Patient::factory()->create(['user_id' => $patientUser->id]);
        app(PatientConsentService::class)->record($patient, 'AI_ASSISTED', true, $patientUser, 'self');
        $provider = Provider::create([
            'user_id' => $doctor->id,
            'display_name' => 'Dr. Assigned',
            'active' => true,
        ]);
        $appointment = Appointment::create([
            'appointment_number' => 'APT-INTAKE-'.Str::upper(Str::random(8)),
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
            'appointment_type_id' => AppointmentType::where('telehealth', true)->firstOrFail()->id,
            'starts_at' => now(),
            'ends_at' => now()->addMinutes(30),
            'status' => Appointment::STATUS_IN_CONSULTATION,
        ]);
        $session = TelehealthSession::create([
            'appointment_id' => $appointment->id,
            'start_time' => now(),
            'duration' => 30,
            'status' => TelehealthSession::STATUS_ONGOING,
        ]);

        return [$patientUser, $doctor, $session];
    }

    private function intakePayload(): array
    {
        return [
            'symptoms' => 'difficulty breathing',
            'duration' => 'since this morning',
            'severity' => 8,
            'allergies' => 'Peanut allergy',
            'current_medications' => 'Salbutamol inhaler',
            'vitals' => ['spo2' => 96, 'heart_rate' => 110],
        ];
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create(['email' => $roleName.'-'.Str::uuid().'@example.test']);
        $user->roles()->attach(Role::where('name', $roleName)->firstOrFail());

        return $user;
    }
}
