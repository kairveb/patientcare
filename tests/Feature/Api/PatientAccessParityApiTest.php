<?php

namespace Tests\Feature\Api;

use App\Events\EmergencyQueueAlert;
use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\ErVisit;
use App\Models\Patient;
use App\Models\Provider;
use App\Models\Role;
use App\Models\SymptomCheck;
use App\Models\TelehealthSession;
use App\Models\TriageAssessment;
use App\Models\TriageReassessment;
use App\Models\User;
use Database\Seeders\HimsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use App\Services\PatientConsentService;
use App\Services\PhilippineAddressDataset;
use Tests\TestCase;

class PatientAccessParityApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(HimsSeeder::class);
    }

    public function test_patient_identifier_numbers_are_normalized_on_api_create_and_update(): void
    {
        $registration = $this->userWithRole('registration');
        $response = $this->actingAs($registration)->postJson('/api/v1/patients', [
            'first_name' => 'Identifier',
            'last_name' => 'Patient',
            'date_of_birth' => '1990-05-15',
            'sex' => 'Female',
            'civil_status' => 'Single',
            'data_privacy_consent' => true,
            'philhealth_number' => '1234-5678 9012',
            'senior_id_number' => '123-456',
            'pwd_id_number' => '789 012',
            'hmo_member_id' => '345-678',
        ])->assertCreated();

        $patientId = $response->json('data.id');
        $patient = Patient::findOrFail($patientId);
        $this->assertSame('123456789012', $patient->philhealth_number);
        $this->assertSame('123456', $patient->senior_id_number);
        $this->assertSame('789012', $patient->pwd_id_number);
        $this->assertSame('345678', $patient->hmo_member_id);

        $this->actingAs($registration)->patchJson("/api/v1/patients/{$patientId}", [
            'pwd_id_number' => '222-333',
        ])->assertOk();
        $this->assertSame('222333', $patient->fresh()->pwd_id_number);

        $this->actingAs($registration)->patchJson("/api/v1/patients/{$patientId}", [
            'hmo_member_id' => 'ABC-123',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('hmo_member_id');
    }

    public function test_patient_api_accepts_and_persists_ncr_address_without_a_province(): void
    {
        $registration = $this->userWithRole('registration');
        $dataset = app(PhilippineAddressDataset::class);
        $manila = collect($dataset->citiesByRegion('1300000000'))
            ->firstWhere('name', 'City of Manila');
        $district = $dataset->submunicipalities($manila['psgc_code'])[0];
        $barangay = $dataset->barangays($district['psgc_code'])[0];

        $address = [
            'region' => 'National Capital Region (NCR)',
            'region_psgc_code' => '1300000000',
            'city' => $manila['name'],
            'city_psgc_code' => $manila['psgc_code'],
            'submunicipality' => $district['name'],
            'submunicipality_psgc_code' => $district['psgc_code'],
            'barangay' => $barangay['name'],
            'barangay_psgc_code' => $barangay['psgc_code'],
        ];

        $response = $this->actingAs($registration)->postJson('/api/v1/patients', [
            'first_name' => 'NCR',
            'last_name' => 'Patient',
            'date_of_birth' => '1990-05-15',
            'sex' => 'Female',
            'data_privacy_consent' => true,
            'address' => $address,
        ])->assertCreated();

        $patient = Patient::findOrFail($response->json('data.id'));
        $savedAddress = $patient->primaryAddress();
        $this->assertSame($address['region_psgc_code'], $savedAddress->region_psgc_code);
        $this->assertSame($address['region'], $savedAddress->region);
        $this->assertNull($savedAddress->province_psgc_code);
        $this->assertNull($savedAddress->province);
        $this->assertSame($address['city_psgc_code'], $savedAddress->city_psgc_code);
        $this->assertSame($address['city'], $savedAddress->city);
        $this->assertSame($address['submunicipality_psgc_code'], $savedAddress->submunicipality_psgc_code);
        $this->assertSame($address['barangay_psgc_code'], $savedAddress->barangay_psgc_code);

        $this->actingAs($registration)->patchJson("/api/v1/patients/{$patient->id}", [
            'address' => $address,
        ])->assertOk();
    }

    public function test_triage_api_requires_override_reason_and_blocks_patient_role(): void
    {
        $nurse = $this->userWithRole('nurse');
        $visit = $this->activeVisit($nurse);
        $url = "/api/v1/emergency/{$visit->id}/triage";
        $payload = [
            'chief_complaint' => 'cough',
            'symptoms' => ['cough'],
            'pain_score' => 1,
            'priority_override' => 'Emergency',
            'ai_confirmed' => true,
        ];
        $assessmentCount = TriageAssessment::count();

        $this->actingAs($nurse)->postJson($url, $payload)->assertUnprocessable()
            ->assertJsonValidationErrors('override_reason');
        $this->assertSame($assessmentCount, TriageAssessment::count());

        $patient = $this->userWithRole('patient');
        $this->actingAs($patient)->postJson($url, $payload)->assertForbidden();
    }

    public function test_symptom_checker_api_validates_input_and_diverts_red_flags(): void
    {
        $patientUser = $this->userWithRole('patient');
        $patient = Patient::factory()->create(['user_id' => $patientUser->id]);
        $this->acceptRequiredPrivacyDocuments($patient, $patientUser);
        app(PatientConsentService::class)->record($patient, 'AI_ASSISTED', true, $patientUser, 'self');

        $this->actingAs($patientUser)->postJson('/api/v1/symptom-checks', [
            'patient_id' => $patient->id,
            'symptoms' => 'chest pain',
            'age' => 35,
        ])->assertCreated()
            ->assertJsonPath('next', 'emergency-instructions')
            ->assertJsonPath('data.requires_er', true);

        $this->assertSame($patient->id, SymptomCheck::firstOrFail()->patient_id);

        $this->actingAs($patientUser)->postJson('/api/v1/symptom-checks', [
            'patient_id' => $patient->id,
            'symptoms' => 'cough',
            'age' => 121,
        ])->assertUnprocessable()->assertJsonValidationErrors('age');

        $this->actingAs($this->userWithRole('nurse'))
            ->postJson('/api/v1/symptom-checks', [])
            ->assertForbidden();
    }

    public function test_reassessment_api_uses_shared_validation_and_role_guards(): void
    {
        $nurse = $this->userWithRole('nurse');
        $doctor = $this->userWithRole('doctor');
        $registration = $this->userWithRole('registration');
        $visit = $this->activeVisit($nurse);
        $vitals = [
            'vitals' => [
                'blood_pressure' => '120/80',
                'heart_rate' => 80,
                'respiratory_rate' => 16,
                'temperature' => 37.0,
                'spo2' => 85,
                'gcs' => 8,
            ],
        ];

        $this->actingAs($nurse)->postJson("/api/v1/emergency/visits/{$visit->id}/reassessments", [
            'vitals' => ['heart_rate' => 500],
        ])->assertUnprocessable()->assertJsonValidationErrors('vitals.heart_rate');

        $this->actingAs($nurse)->postJson("/api/v1/emergency/visits/{$visit->id}/reassessments", $vitals)
            ->assertCreated();
        $reassessment = TriageReassessment::firstOrFail();
        $confirmUrl = "/api/v1/emergency/visits/{$visit->id}/reassessments/{$reassessment->id}/confirm";

        $this->actingAs($doctor)->postJson($confirmUrl, ['final_level' => 'Routine'])
            ->assertUnprocessable()->assertJsonValidationErrors('override_reason');
        $this->actingAs($registration)->postJson($confirmUrl, ['final_level' => 'Routine'])
            ->assertForbidden();
        $this->assertSame(TriageReassessment::STATUS_PENDING, $reassessment->fresh()->status);
    }

    public function test_only_assigned_doctor_can_escalate_telehealth_session_through_api(): void
    {
        Event::fake([EmergencyQueueAlert::class]);
        [$doctor, $session] = $this->telehealthSession();
        $otherDoctor = $this->userWithRole('doctor');
        Provider::create(['user_id' => $otherDoctor->id, 'display_name' => 'Dr. Other', 'active' => true]);
        $url = "/api/v1/telehealth/{$session->id}/escalate-to-er";

        $this->actingAs($otherDoctor)->postJson($url)->assertForbidden();
        $this->assertSame(0, ErVisit::where('telehealth_session_id', $session->id)->count());

        $this->actingAs($doctor)->postJson($url)->assertCreated()
            ->assertJsonPath('success', true);
        $this->assertSame(1, ErVisit::where('telehealth_session_id', $session->id)->count());

        $this->actingAs($this->userWithRole('nurse'))->postJson($url)->assertForbidden();
    }

    private function activeVisit(User $nurse): ErVisit
    {
        $patient = Patient::factory()->create();
        $visit = ErVisit::create([
            'visit_number' => 'ER-API-'.Str::upper(Str::random(8)),
            'patient_id' => $patient->id,
            'arrived_at' => now(),
            'chief_complaint' => 'Cough',
            'status' => ErVisit::STATUS_TRIAGED,
        ]);
        TriageAssessment::create([
            'er_visit_id' => $visit->id,
            'patient_id' => $patient->id,
            'triage_nurse_id' => $nurse->id,
            'triaged_at' => now(),
            'chief_complaint' => 'Cough',
            'symptoms' => ['cough'],
            'pain_score' => 1,
            'priority' => 'Level 5',
            'priority_score' => 5,
            'triage_color' => 'green',
            'final_level' => 'Routine',
            'ai_suggested_level' => 'Routine',
            'is_overridden' => false,
            'status' => 'COMPLETE',
        ]);

        return $visit;
    }

    private function telehealthSession(): array
    {
        $doctor = $this->userWithRole('doctor');
        $patient = Patient::factory()->create();
        $provider = Provider::create([
            'user_id' => $doctor->id,
            'display_name' => 'Dr. Assigned',
            'active' => true,
        ]);
        $appointment = Appointment::create([
            'appointment_number' => 'APT-API-'.Str::upper(Str::random(8)),
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

        return [$doctor, $session];
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create(['email' => $roleName.'-'.Str::uuid().'@example.test']);
        $user->roles()->attach(Role::where('name', $roleName)->firstOrFail());

        return $user;
    }
}
