<?php

namespace Tests\Feature\Api;

use App\Models\Provider;
use App\Models\Patient;
use App\Models\ProviderSchedule;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\HimsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProviderScheduleApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(HimsSeeder::class);
    }

    public function test_registration_can_view_provider_schedules(): void
    {
        $user = User::where('email', 'registration@coor.test')->firstOrFail();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/provider-schedules');

        $response->assertStatus(200)
            ->assertJsonStructure(['success', 'message', 'data']);

        $provider = Provider::query()->firstOrFail();
        $date = now()->addDay();
        while ($date->dayOfWeek === 0) {
            $date->addDay();
        }

        $this->getJson("/api/v1/schedules/{$provider->id}/slots?date={$date->toDateString()}")
            ->assertOk();
    }

    public function test_registration_and_patient_can_view_but_not_mutate_provider_schedules(): void
    {
        $provider = Provider::query()->firstOrFail();
        $schedule = ProviderSchedule::query()->firstOrFail();

        foreach (['registration@coor.test', 'patient@coor.test'] as $email) {
            Sanctum::actingAs(User::where('email', $email)->firstOrFail());

            $this->getJson('/api/v1/provider-schedules')->assertOk();
            $this->postJson('/api/v1/provider-schedules', [
                'provider_id' => $provider->id,
                'day_of_week' => 4,
                'start_time' => '09:00',
                'end_time' => '12:00',
                'slot_duration' => 20,
            ])->assertForbidden();
            $this->patchJson("/api/v1/provider-schedules/{$schedule->id}", [
                'start_time' => '10:00',
            ])->assertForbidden();
            $this->postJson("/api/v1/provider-schedules/{$schedule->id}/generate-slots", [
                'from_date' => now()->toDateString(),
                'to_date' => now()->toDateString(),
            ])->assertForbidden();
            $this->deleteJson("/api/v1/provider-schedules/{$schedule->id}")->assertForbidden();
        }
    }

    public function test_super_admin_can_create_provider_schedules(): void
    {
        Sanctum::actingAs(User::where('email', 'super-admin@coor.test')->firstOrFail());
        $provider = Provider::query()->firstOrFail();

        $this->postJson('/api/v1/provider-schedules', [
            'provider_id' => $provider->id,
            'day_of_week' => 4,
            'start_time' => '09:00',
            'end_time' => '12:00',
            'slot_duration' => 20,
        ])->assertCreated()
            ->assertJsonPath('data.provider_id', $provider->id);
    }

    public function test_only_super_admin_has_provider_schedule_management_permission(): void
    {
        $this->assertTrue(Role::where('name', 'super-admin')->firstOrFail()
            ->permissions()->where('name', 'manage-provider-schedules')->exists());

        foreach (['registration', 'doctor', 'nurse', 'patient'] as $roleName) {
            $this->assertFalse(Role::where('name', $roleName)->firstOrFail()
                ->permissions()->where('name', 'manage-provider-schedules')->exists());
        }
    }

    public function test_doctor_can_only_view_their_own_provider_schedule_and_slots(): void
    {
        $doctor = User::where('email', 'doctor@coor.test')->firstOrFail();
        $ownProvider = $doctor->provider()->firstOrFail();
        $otherDoctor = User::factory()->create(['email' => 'other-doctor@coor.test']);
        $otherProvider = Provider::create([
            'user_id' => $otherDoctor->id,
            'department_id' => $ownProvider->department_id,
            'license_number' => 'LIC-OTHER-SCHEDULE',
            'display_name' => 'Other Provider',
            'active' => true,
        ]);
        $otherSchedule = ProviderSchedule::create([
            'provider_id' => $otherProvider->id,
            'day_of_week' => 1,
            'start_time' => '09:00',
            'end_time' => '12:00',
            'slot_duration' => 30,
        ]);
        Sanctum::actingAs($doctor);

        $this->getJson('/api/v1/provider-schedules')
            ->assertOk()
            ->assertJsonMissing(['provider_id' => $otherProvider->id]);
        $this->getJson("/api/v1/provider-schedules/{$otherSchedule->id}")->assertForbidden();
        $this->getJson("/api/v1/schedules/{$otherProvider->id}/slots?date=".now()->addDay()->toDateString())
            ->assertForbidden();
    }

    public function test_patient_and_registration_staff_can_still_book_appointments(): void
    {
        $patientUser = User::where('email', 'patient@coor.test')->firstOrFail();
        $registration = User::where('email', 'registration@coor.test')->firstOrFail();
        $provider = Provider::query()->firstOrFail();
        $appointmentTypeId = \App\Models\AppointmentType::query()->firstOrFail()->id;
        $bookingPatients = [
            [$patientUser, $patientUser->patient()->firstOrFail()],
            [$registration, Patient::whereNull('user_id')->firstOrFail()],
        ];

        foreach ($bookingPatients as $index => [$user, $patient]) {
            Sanctum::actingAs($user);
            $response = $this->postJson('/api/v1/appointments', [
                'patient_id' => $patient->id,
                'provider_id' => $provider->id,
                'department_id' => $provider->department_id,
                'appointment_type_id' => $appointmentTypeId,
                'starts_at' => now()->addDays(20 + $index)->setTime(10, 0)->toDateTimeString(),
                'duration' => 30,
            ]);

            $response->assertCreated()
                ->assertJsonPath('data.patient_id', $patient->id);
        }
    }
}
