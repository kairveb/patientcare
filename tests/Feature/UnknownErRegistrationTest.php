<?php

namespace Tests\Feature;

use App\Models\AppointmentType;
use App\Models\AuditLog;
use App\Models\ErVisit;
use App\Models\Patient;
use App\Models\Provider;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\HimsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class UnknownErRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(HimsSeeder::class);
    }

    public function test_nurse_can_register_unknown_er_arrival_with_null_unknown_demographics(): void
    {
        $nurse = $this->userWithRole('nurse');

        $response = $this->actingAs($nurse)->post(route('emergency.unknown-arrivals.store'), [
            'chief_complaint' => 'Found unconscious near the entrance',
            'arrival_method' => 'Ambulance',
        ])->assertRedirect();

        $visit = ErVisit::query()->latest('id')->firstOrFail();
        $patient = $visit->patient;

        $this->assertSame(route('emergency.show', $visit), $response->headers->get('Location'));
        $this->assertTrue($patient->is_temporary_identity);
        $this->assertStringStartsWith('ER-UNKNOWN-', $patient->temporary_identity_label);
        $this->assertSame('Unknown patient ('.$patient->temporary_identity_label.')', $patient->full_name);
        $this->assertNull($patient->date_of_birth);
        $this->assertNull($patient->sex);
        $this->assertNull($patient->age);
        $this->assertSame($patient->id, $visit->patient_id);
        $this->assertSame($nurse->id, $visit->created_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'CREATE_PATIENT']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'CREATE_ER_VISIT', 'resource_id' => $visit->id]);
    }

    public function test_api_unknown_arrival_validates_required_complaint_and_restricts_roles(): void
    {
        $registration = $this->userWithRole('registration');
        $url = '/api/v1/emergency/visits/unknown';

        $this->actingAs($registration)->postJson($url, [
            'arrival_method' => 'Walk-in',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('chief_complaint');

        $doctor = $this->userWithRole('doctor');
        $this->actingAs($doctor)->postJson($url, [
            'chief_complaint' => 'Unknown arrival',
        ])->assertForbidden();

        $response = $this->actingAs($registration)->postJson($url, [
            'first_name' => 'KnownFirst',
            'date_of_birth' => '1980-05-06',
            'sex' => 'Female',
            'chief_complaint' => 'Unknown arrival',
        ])->assertCreated()
            ->assertJsonPath('data.patient.is_temporary_identity', true)
            ->assertJsonPath('data.patient.date_of_birth', '1980-05-06')
            ->assertJsonPath('data.patient.sex', 'Female');

        $this->assertDatabaseHas('er_visits', [
            'id' => $response->json('data.id'),
            'patient_id' => $response->json('data.patient_id'),
            'chief_complaint' => 'Unknown arrival',
        ]);
    }

    public function test_registration_staff_completes_identity_without_relinking_er_history(): void
    {
        $nurse = $this->userWithRole('nurse');
        $visit = $this->actingAs($nurse)->postJson('/api/v1/emergency/visits/unknown', [
            'chief_complaint' => 'Unknown patient arrival',
        ])->assertCreated()->json('data');
        $patientId = $visit['patient_id'];
        $patient = Patient::findOrFail($patientId);

        $completeUrl = "/api/v1/patients/{$patientId}/complete-temporary-identity";
        $this->actingAs($nurse)->postJson($completeUrl, [
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'date_of_birth' => '1988-09-20',
            'sex' => 'Female',
        ])->assertForbidden();

        $registration = $this->userWithRole('registration');
        $this->actingAs($registration)->postJson($completeUrl, [
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'date_of_birth' => '1988-09-20',
            'sex' => 'Female',
        ])->assertOk()
            ->assertJsonPath('data.is_temporary_identity', false)
            ->assertJsonPath('data.temporary_identity_label', null)
            ->assertJsonPath('data.first_name', 'Maria');

        $this->assertSame('Maria Santos', $patient->fresh()->full_name);
        $this->assertFalse($patient->fresh()->is_temporary_identity);
        $this->assertDatabaseHas('er_visits', [
            'id' => $visit['id'],
            'patient_id' => $patientId,
        ]);
        $this->assertSame(1, AuditLog::where('action', 'COMPLETE_TEMPORARY_PATIENT_IDENTITY')->count());
    }

    public function test_temporary_er_patient_cannot_be_booked_before_identity_completion(): void
    {
        $registration = $this->userWithRole('registration');
        $patient = Patient::factory()->create([
            'is_temporary_identity' => true,
            'temporary_identity_label' => 'ER-UNKNOWN-'.Str::upper(Str::random(10)),
            'date_of_birth' => null,
            'sex' => null,
        ]);
        $provider = Provider::factory()->create();
        $appointmentType = AppointmentType::query()->firstOrFail();

        $this->actingAs($registration)->postJson('/api/v1/appointments', [
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
            'appointment_type_id' => $appointmentType->id,
            'starts_at' => now()->addDays(2)->setTime(10, 0)->toDateTimeString(),
            'duration' => 30,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('patient_id');
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create([
            'email' => $roleName.'-'.Str::uuid().'@example.test',
        ]);
        $user->roles()->attach(Role::where('name', $roleName)->firstOrFail());

        return $user;
    }
}
