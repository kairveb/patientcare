<?php

namespace Tests\Feature\Api;

use App\Models\Appointment;
use App\Models\Provider;
use App\Models\Role;
use App\Models\TelehealthSession;
use App\Models\User;
use Database\Seeders\HimsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use App\Services\PatientConsentService;
use Tests\TestCase;

class TelehealthParticipantApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(HimsSeeder::class);
    }

    public function test_doctor_can_add_participant_to_telehealth_session(): void
    {
        $user = User::where('email', 'doctor@coor.test')->first();
        $appointment = Appointment::first();
        app(PatientConsentService::class)->record($appointment->patient, 'TELEHEALTH', true, $user, 'staff');
        Sanctum::actingAs($user);

        $session = TelehealthSession::create([
            'appointment_id' => $appointment->id,
            'start_time' => now()->addDay(),
            'duration' => 30,
            'status' => 'SCHEDULED',
        ]);

        $response = $this->actingAs($user)->postJson("/api/v1/telehealth/{$session->id}/participants", [
            'participant_type' => 'provider',
            'participant_id' => $user->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.role', 'provider');
    }

    public function test_other_doctor_and_staff_cannot_view_or_add_to_an_unassigned_session(): void
    {
        $appointment = Appointment::firstOrFail();
        $assignedDoctor = User::where('email', 'doctor@coor.test')->firstOrFail();
        $otherDoctor = User::factory()->create();
        $otherDoctor->roles()->attach(Role::where('name', 'doctor')->firstOrFail());
        Provider::create(['user_id' => $otherDoctor->id, 'display_name' => 'Dr. Other']);
        $session = TelehealthSession::create([
            'appointment_id' => $appointment->id,
            'start_time' => now()->addDay(),
            'duration' => 30,
            'status' => 'SCHEDULED',
        ]);

        foreach ([
            $otherDoctor,
            User::where('email', 'registration@coor.test')->firstOrFail(),
        ] as $unauthorized) {
            $this->actingAs($unauthorized)->getJson("/api/v1/telehealth/{$session->id}/participants")
                ->assertForbidden();
            $this->actingAs($unauthorized)->postJson("/api/v1/telehealth/{$session->id}/participants", [
                'participant_type' => 'patient',
                'participant_id' => $assignedDoctor->id,
            ])->assertForbidden();
        }
    }

    public function test_patient_owner_and_super_admin_can_view_and_add_participants_but_invalid_type_is_rejected(): void
    {
        $appointment = Appointment::firstOrFail();
        $patientUser = User::where('email', 'patient@coor.test')->firstOrFail();
        $patient = $appointment->patient;
        $patient->update(['user_id' => $patientUser->id]);
        app(PatientConsentService::class)->record($patient, 'TELEHEALTH', true, $patientUser, 'self');
        $session = TelehealthSession::create([
            'appointment_id' => $appointment->id,
            'start_time' => now()->addDay(),
            'duration' => 30,
            'status' => 'SCHEDULED',
        ]);
        $this->acceptRequiredPrivacyDocuments($patient, $patientUser);

        $this->actingAs($patientUser)->getJson("/api/v1/telehealth/{$session->id}/participants")
            ->assertOk();
        $this->actingAs($patientUser)->postJson("/api/v1/telehealth/{$session->id}/participants", [
            'participant_type' => 'invalid-type',
            'participant_id' => $patientUser->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('participant_type');

        $superAdmin = User::where('email', 'super-admin@coor.test')->firstOrFail();
        if (! app(PatientConsentService::class)->hasCurrentGrant($patient, 'TELEHEALTH')) {
            app(PatientConsentService::class)->record($patient, 'TELEHEALTH', true, $superAdmin, 'staff');
        }
        $this->actingAs($superAdmin)->getJson("/api/v1/telehealth/{$session->id}/participants")
            ->assertOk();
        $this->actingAs($superAdmin)->postJson("/api/v1/telehealth/{$session->id}/participants", [
            'participant_type' => 'caregiver',
            'participant_id' => $patientUser->id,
        ])->assertCreated();
    }
}
