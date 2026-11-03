<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\Patient;
use App\Models\PatientConsent;
use App\Models\Role;
use App\Models\TelehealthSession;
use App\Models\User;
use App\Services\PatientConsentService;
use Database\Seeders\HimsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PatientConsentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(HimsSeeder::class);
    }

    public function test_patient_grants_and_withdraws_consent_as_append_only_versioned_history(): void
    {
        $patientUser = $this->userWithRole('patient');
        $patient = Patient::factory()->create(['user_id' => $patientUser->id]);
        app(PatientConsentService::class)->record($patient, 'DATA_PRIVACY', true, $patientUser, 'self');
        app(PatientConsentService::class)->record($patient, 'TERMS_OF_USE', true, $patientUser, 'self');
        $url = '/api/v1/me/consents';

        $this->actingAs($patientUser)->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.types.AI_ASSISTED.version', '1.0');

        $this->actingAs($patientUser)->postJson($url, [
            'type' => 'AI_ASSISTED',
            'granted' => true,
        ])->assertCreated()
            ->assertJsonPath('data.channel', 'self')
            ->assertJsonPath('data.version', '1.0');

        $this->actingAs($patientUser)->postJson($url, [
            'type' => 'AI_ASSISTED',
            'granted' => false,
        ])->assertCreated()
            ->assertJsonPath('data.granted', false);

        $this->assertSame(2, $patient->consents()->where('type', 'AI_ASSISTED')->count());
        $this->assertFalse(app(PatientConsentService::class)->hasCurrentGrant($patient, 'AI_ASSISTED'));
        $this->assertSame(1, $patient->consents()->where('type', 'AI_ASSISTED')->where('granted', false)->count());
        $this->assertSame(4, \App\Models\AuditLog::where('action', 'RECORD_PATIENT_CONSENT')->count());

        config(['consents.types.AI_ASSISTED.version' => '2.0']);
        $this->actingAs($patientUser)->postJson($url, [
            'type' => 'AI_ASSISTED',
            'granted' => true,
        ])->assertCreated()->assertJsonPath('data.version', '2.0');
        $this->assertTrue(app(PatientConsentService::class)->hasCurrentGrant($patient, 'AI_ASSISTED'));

        $this->get(route('patient-consents.portal', ['required' => 'AI_ASSISTED']))
            ->assertOk()
            ->assertSee(config('consents.types.AI_ASSISTED.text'));
    }

    public function test_registration_requires_data_privacy_consent_and_can_record_other_consent_for_a_patient(): void
    {
        $registration = $this->userWithRole('registration');
        $patientData = [
            'first_name' => 'Consent',
            'last_name' => 'Patient',
            'date_of_birth' => '1993-03-12',
            'sex' => 'Female',
        ];
        $patientCount = Patient::count();

        $this->actingAs($registration)->postJson('/api/v1/patients', $patientData)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('data_privacy_consent');
        $this->assertSame($patientCount, Patient::count());

        $response = $this->actingAs($registration)->postJson('/api/v1/patients', [
            ...$patientData,
            'data_privacy_consent' => true,
        ])->assertCreated();
        $patientId = $response->json('data.id');
        $this->assertDatabaseHas('patient_consents', [
            'patient_id' => $patientId,
            'type' => 'DATA_PRIVACY',
            'version' => config('consents.types.DATA_PRIVACY.version'),
            'granted' => true,
            'channel' => 'staff',
            'recorded_by' => $registration->id,
        ]);

        $this->actingAs($registration)->postJson("/api/v1/patients/{$patientId}/consents", [
            'type' => 'TELEHEALTH',
            'granted' => true,
        ])->assertCreated()->assertJsonPath('data.channel', 'staff');
        $this->actingAs($registration)->getJson("/api/v1/patients/{$patientId}/consents")
            ->assertOk()
            ->assertJsonPath('data.latest.TELEHEALTH.granted', true);

        $nurse = $this->userWithRole('nurse');
        $this->actingAs($nurse)->postJson("/api/v1/patients/{$patientId}/consents", [
            'type' => 'GENERAL',
            'granted' => true,
        ])->assertForbidden();
        $this->actingAs($nurse)->getJson("/api/v1/patients/{$patientId}/consents")->assertOk();
    }

    public function test_ai_tools_and_telehealth_join_require_current_consent(): void
    {
        config(['telehealth.provider' => 'jitsi_demo']);
        $patientUser = $this->userWithRole('patient');
        $patient = Patient::factory()->create(['user_id' => $patientUser->id]);
        app(PatientConsentService::class)->record($patient, 'DATA_PRIVACY', true, $patientUser, 'self');
        app(PatientConsentService::class)->record($patient, 'TERMS_OF_USE', true, $patientUser, 'self');
        $session = $this->telehealthSession($patient);

        $this->actingAs($patientUser)
            ->postJson('/api/v1/symptom-checks', [
                'patient_id' => $patient->id,
                'symptoms' => 'cough',
                'age' => 34,
            ])->assertForbidden()
            ->assertJsonPath('code', 'CONSENT_REQUIRED');

        $this->actingAs($patientUser)
            ->get(route('patients.portal.telehealth.intake', $session))
            ->assertOk()
            ->assertSee('AI-assisted care consent is required');

        $this->actingAs($patientUser)
            ->postJson("/api/v1/telehealth/{$session->id}/intake", [
                'symptoms' => 'cough',
                'duration' => 'one day',
                'severity' => 2,
            ])->assertForbidden()->assertJsonPath('code', 'CONSENT_REQUIRED');

        $joinUrl = route('telehealth.join', [
            'session' => $session,
            'token' => $session->secureJoinToken(),
        ]);
        $this->actingAs($patientUser)->get($joinUrl)
            ->assertRedirect(route('patient-consents.portal', ['required' => 'TELEHEALTH']));

        $consents = app(PatientConsentService::class);
        $consents->record($patient, 'AI_ASSISTED', true, $patientUser, 'self');
        $this->actingAs($patientUser)->postJson('/api/v1/symptom-checks', [
            'patient_id' => $patient->id,
            'symptoms' => 'cough',
            'age' => 34,
        ])->assertCreated();
        $this->actingAs($patientUser)->postJson("/api/v1/telehealth/{$session->id}/intake", [
            'symptoms' => 'cough',
            'duration' => 'one day',
            'severity' => 2,
        ])->assertCreated();

        $consents->record($patient, 'TELEHEALTH', true, $patientUser, 'self');
        $session->update(['status' => TelehealthSession::STATUS_ACTIVE]);
        $this->actingAs($patientUser)->get($joinUrl)->assertOk();
    }

    public function test_consent_records_cannot_be_updated_or_deleted(): void
    {
        $patient = Patient::factory()->create();
        $consent = PatientConsent::create([
            'patient_id' => $patient->id,
            'type' => 'GENERAL',
            'version' => '1.0',
            'granted' => true,
            'recorded_at' => now(),
            'channel' => 'self',
        ]);

        try {
            $consent->update(['granted' => false]);
            $this->fail('Consent history records must not be editable.');
        } catch (\LogicException $exception) {
            $this->assertSame('Patient consent history is append-only.', $exception->getMessage());
        }

        try {
            $consent->delete();
            $this->fail('Consent history records must not be deletable.');
        } catch (\LogicException $exception) {
            $this->assertSame('Patient consent history is append-only.', $exception->getMessage());
        }
    }

    private function telehealthSession(Patient $patient): TelehealthSession
    {
        $doctor = $this->userWithRole('doctor');
        $provider = \App\Models\Provider::create([
            'user_id' => $doctor->id,
            'display_name' => 'Dr. Consent',
            'active' => true,
        ]);
        $appointment = Appointment::create([
            'appointment_number' => 'APT-CONSENT-'.Str::upper(Str::random(8)),
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
            'appointment_type_id' => AppointmentType::where('telehealth', true)->firstOrFail()->id,
            'starts_at' => now(),
            'ends_at' => now()->addMinutes(30),
            'status' => Appointment::STATUS_CONFIRMED,
        ]);

        return TelehealthSession::create([
            'appointment_id' => $appointment->id,
            'start_time' => now(),
            'duration' => 30,
            'status' => TelehealthSession::STATUS_SCHEDULED,
        ]);
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create(['email' => $roleName.'-'.Str::uuid().'@example.test']);
        $user->roles()->attach(Role::where('name', $roleName)->firstOrFail());

        return $user;
    }
}
