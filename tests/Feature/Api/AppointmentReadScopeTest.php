<?php

namespace Tests\Feature\Api;

use App\Models\Appointment;
use App\Models\Department;
use App\Models\Patient;
use App\Models\Provider;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\HimsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AppointmentReadScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(HimsSeeder::class);
    }

    public function test_doctor_list_and_detail_are_scoped_to_own_provider(): void
    {
        $doctor = User::where('email', 'doctor@coor.test')->firstOrFail();
        $ownProvider = $doctor->provider()->firstOrFail();
        $otherDoctor = User::factory()->create();
        $otherDoctor->roles()->attach(Role::where('name', 'doctor')->firstOrFail());
        $otherProvider = Provider::create([
            'user_id' => $otherDoctor->id,
            'department_id' => $ownProvider->department_id,
            'display_name' => 'Dr. Other Appointment Scope',
            'active' => true,
        ]);
        $patient = Patient::factory()->create();
        $own = $this->createAppointment($patient, $ownProvider, 'DOCTOR-OWN');
        $foreign = $this->createAppointment($patient, $otherProvider, 'DOCTOR-FOREIGN');

        Sanctum::actingAs($doctor);

        $this->getJson('/api/v1/appointments')
            ->assertOk()
            ->assertJsonFragment(['id' => $own->id])
            ->assertJsonMissing(['id' => $foreign->id]);
        $this->getJson("/api/v1/appointments/{$own->id}")->assertOk();
        $this->getJson("/api/v1/appointments/{$foreign->id}")->assertForbidden();
    }

    public function test_nurse_list_and_detail_are_scoped_to_assigned_department_and_current_opd_status(): void
    {
        $nurse = User::where('email', 'nurse@coor.test')->firstOrFail();
        $department = Department::firstOrFail();
        $nurse->departments()->syncWithoutDetaching([$department->id]);

        $otherDepartment = Department::create([
            'code' => 'APPT-SCOPE-OTHER',
            'name' => 'Appointment Scope Other',
        ]);
        $doctor = User::where('email', 'doctor@coor.test')->firstOrFail();
        $ownProvider = $doctor->provider()->firstOrFail();
        $otherDoctor = User::factory()->create();
        $otherDoctor->roles()->attach(Role::where('name', 'doctor')->firstOrFail());
        $otherProvider = Provider::create([
            'user_id' => $otherDoctor->id,
            'department_id' => $otherDepartment->id,
            'display_name' => 'Dr. Other Nurse Scope',
            'active' => true,
        ]);

        $patient = Patient::factory()->create();
        $own = $this->createAppointment($patient, $ownProvider, 'NURSE-OWN', $department->id);
        $foreign = $this->createAppointment($patient, $otherProvider, 'NURSE-FOREIGN', $otherDepartment->id);
        $future = $this->createAppointment(
            $patient,
            $ownProvider,
            'NURSE-FUTURE',
            $department->id,
            now()->addDay()
        );

        Sanctum::actingAs($nurse);

        $this->getJson('/api/v1/appointments')
            ->assertOk()
            ->assertJsonFragment(['id' => $own->id])
            ->assertJsonMissing(['id' => $foreign->id])
            ->assertJsonMissing(['id' => $future->id]);
        $this->getJson("/api/v1/appointments/{$own->id}")->assertOk();
        $this->getJson("/api/v1/appointments/{$foreign->id}")->assertForbidden();
        $this->getJson("/api/v1/appointments/{$future->id}")->assertForbidden();
    }

    public function test_patient_list_and_detail_are_scoped_to_own_patient(): void
    {
        $patientUser = User::factory()->create();
        $patientUser->roles()->attach(Role::where('name', 'patient')->firstOrFail());
        $ownPatient = Patient::factory()->linkedTo($patientUser)->create();
        $otherPatient = Patient::factory()->create();
        $provider = Provider::firstOrFail();
        $own = $this->createAppointment($ownPatient, $provider, 'PATIENT-OWN');
        $foreign = $this->createAppointment($otherPatient, $provider, 'PATIENT-FOREIGN');

        Sanctum::actingAs($patientUser);

        $this->getJson('/api/v1/appointments')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own->id);
        $this->getJson("/api/v1/appointments/{$own->id}")->assertOk();
        $this->getJson("/api/v1/appointments/{$foreign->id}")->assertForbidden();
    }

    public function test_registration_and_super_admin_can_read_all_appointments_but_unpermitted_role_cannot_list(): void
    {
        $provider = Provider::firstOrFail();
        $appointment = $this->createAppointment(Patient::factory()->create(), $provider, 'STAFF');

        foreach (['registration', 'super-admin'] as $roleName) {
            $user = User::whereHas('roles', fn ($query) => $query->where('name', $roleName))->firstOrFail();
            Sanctum::actingAs($user);
            $this->getJson('/api/v1/appointments')
                ->assertOk()
                ->assertJsonFragment(['id' => $appointment->id]);
            $this->getJson("/api/v1/appointments/{$appointment->id}")->assertOk();
        }

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/appointments')->assertForbidden();
    }

    private function createAppointment(
        Patient $patient,
        Provider $provider,
        string $number,
        ?int $departmentId = null,
        mixed $startsAt = null
    ): Appointment {
        $startsAt ??= today()->setTime(8, 0)->addMinutes(30 * Appointment::count());

        return Appointment::create([
            'appointment_number' => 'APT-SCOPE-'.$number,
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
            'department_id' => $departmentId ?? $provider->department_id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes(30),
            'status' => Appointment::STATUS_CONFIRMED,
        ]);
    }
}
