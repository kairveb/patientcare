<?php

namespace Tests\Feature\Api;

use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Provider;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\HimsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppointmentLifecycleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_patient_can_only_cancel_or_reschedule_owned_appointments(): void
    {
        $this->seed(HimsSeeder::class);

        $patient = Patient::query()->firstOrFail();
        $provider = \App\Models\Provider::query()->firstOrFail();
        $appointment = Appointment::create([
            'appointment_number' => 'APT-AUTH-000001',
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
            'starts_at' => now()->addDay()->setTime(9, 0),
            'ends_at' => now()->addDay()->setTime(9, 30),
            'status' => Appointment::STATUS_PENDING,
        ]);

        $doctor = User::whereHas('roles', fn ($query) => $query->where('name', 'doctor'))->firstOrFail();
        $nurse = User::whereHas('roles', fn ($query) => $query->where('name', 'nurse'))->firstOrFail();
        $patientUser = User::whereHas('roles', fn ($query) => $query->where('name', 'patient'))->firstOrFail();

        $lifecyclePermissions = Permission::whereIn('name', ['cancel-appointments'])->pluck('id');
        foreach ([$doctor, $nurse, $patientUser] as $user) {
            $user->roles()->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching($lifecyclePermissions));
        }
        $this->acceptRequiredPrivacyDocuments($patient, $patientUser);

        foreach ([$doctor, $nurse] as $user) {
            $token = $user->createToken('appointment-lifecycle-test')->plainTextToken;
            $this->withHeader('Authorization', 'Bearer '.$token)
                ->patchJson("/api/v1/appointments/{$appointment->id}", ['status' => Appointment::STATUS_CONFIRMED])
                ->assertForbidden();
            $this->withHeader('Authorization', 'Bearer '.$token)
                ->postJson("/api/v1/appointments/{$appointment->id}/check-in")
                ->assertForbidden();
            $this->withHeader('Authorization', 'Bearer '.$token)
                ->postJson("/api/v1/appointments/{$appointment->id}/cancel")
                ->assertForbidden();
            $this->withHeader('Authorization', 'Bearer '.$token)
                ->postJson("/api/v1/appointments/{$appointment->id}/reschedule", [
                    'starts_at' => now()->addDays(2)->setTime(10, 0)->toDateTimeString(),
                ])
                ->assertForbidden();
        }

        $this->actingAs($patientUser, 'sanctum');
        $this->assertTrue($patientUser->hasPermission('cancel-appointments'));
        $this->assertSame($patient->id, $patientUser->patient?->id);
        $this->assertTrue($patientUser->can('reschedule', $appointment));
        $this->patchJson("/api/v1/appointments/{$appointment->id}", ['status' => Appointment::STATUS_CONFIRMED])
            ->assertForbidden();
        $this->postJson("/api/v1/appointments/{$appointment->id}/check-in")
            ->assertForbidden();
        $rescheduleStartsAt = now()->addDays(2)->setTime(10, 0);
        $rescheduleSlot = AppointmentSlot::create([
            'provider_id' => $provider->id,
            'starts_at' => $rescheduleStartsAt,
            'ends_at' => $rescheduleStartsAt->copy()->addMinutes(30),
            'status' => AppointmentSlot::STATUS_AVAILABLE,
        ]);
        $this->postJson("/api/v1/appointments/{$appointment->id}/reschedule", [
            'starts_at' => $rescheduleStartsAt->toDateTimeString(),
            'appointment_slot_id' => $rescheduleSlot->id,
        ])->assertOk();
        $this->postJson("/api/v1/appointments/{$appointment->id}/cancel")
            ->assertOk();

        $foreignAppointment = Appointment::create([
            'appointment_number' => 'APT-AUTH-000003',
            'patient_id' => Patient::whereNull('user_id')->firstOrFail()->id,
            'provider_id' => $provider->id,
            'starts_at' => now()->addDays(4)->setTime(9, 0),
            'ends_at' => now()->addDays(4)->setTime(9, 30),
            'status' => Appointment::STATUS_PENDING,
        ]);
        $this->postJson("/api/v1/appointments/{$foreignAppointment->id}/cancel")
            ->assertForbidden();
        $this->postJson("/api/v1/appointments/{$foreignAppointment->id}/reschedule", [
            'starts_at' => now()->addDays(5)->setTime(10, 0)->toDateTimeString(),
        ])
            ->assertForbidden();

        $this->assertSame(Appointment::STATUS_CANCELLED, $appointment->fresh()->status);
        $this->assertSame(Appointment::STATUS_PENDING, $foreignAppointment->fresh()->status);
    }

    public function test_registration_and_super_admin_can_mutate_appointment_lifecycle(): void
    {
        $this->seed(HimsSeeder::class);

        $patient = Patient::query()->firstOrFail();
        $provider = \App\Models\Provider::query()->firstOrFail();
        $appointment = Appointment::create([
            'appointment_number' => 'APT-AUTH-000002',
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
            'starts_at' => now()->addDay()->setTime(9, 0),
            'ends_at' => now()->addDay()->setTime(9, 30),
            'status' => Appointment::STATUS_PENDING,
        ]);

        $registration = User::whereHas('roles', fn ($query) => $query->where('name', 'registration'))->firstOrFail();
        $token = $registration->createToken('appointment-lifecycle-test')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson("/api/v1/appointments/{$appointment->id}", [
                'status' => Appointment::STATUS_CONFIRMED,
                'reason' => 'Registration verified the booking.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('decision');
        $this->assertSame(Appointment::STATUS_PENDING, $appointment->fresh()->status);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/appointments/{$appointment->id}/decision", [
                'decision' => 'confirm',
                'reason' => 'Registration verified the booking.',
            ])
            ->assertOk();

        $this->assertSame(Appointment::STATUS_CONFIRMED, $appointment->fresh()->status);

        $superAdmin = User::whereHas('roles', fn ($query) => $query->where('name', 'super-admin'))->firstOrFail();
        $adminToken = $superAdmin->createToken('appointment-lifecycle-test')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->postJson("/api/v1/appointments/{$appointment->id}/check-in")
            ->assertOk();

        $this->assertSame(Appointment::STATUS_CHECKED_IN, $appointment->fresh()->status);
    }

    public function test_api_no_show_uses_authorized_service_transition(): void
    {
        $this->seed(HimsSeeder::class);
        $patient = Patient::query()->firstOrFail();
        $provider = Provider::query()->firstOrFail();
        $appointment = Appointment::create([
            'appointment_number' => 'APT-NOSHOW-'.uniqid(),
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->subMinutes(30),
            'status' => Appointment::STATUS_CONFIRMED,
        ]);

        $doctor = User::whereHas('roles', fn ($query) => $query->where('name', 'doctor'))->firstOrFail();
        $this->actingAs($doctor, 'sanctum')
            ->postJson("/api/v1/appointments/{$appointment->id}/no-show")
            ->assertForbidden();
        $this->assertSame(Appointment::STATUS_CONFIRMED, $appointment->fresh()->status);

        $registration = User::whereHas('roles', fn ($query) => $query->where('name', 'registration'))->firstOrFail();
        $this->actingAs($registration, 'sanctum')
            ->postJson("/api/v1/appointments/{$appointment->id}/no-show")
            ->assertOk()
            ->assertJsonPath('data.status', Appointment::STATUS_NO_SHOW);
        $this->assertSame(Appointment::STATUS_NO_SHOW, $appointment->fresh()->status);
    }
}
