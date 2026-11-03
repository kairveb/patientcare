<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\AppointmentType;
use App\Models\EncounterBill;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Provider;
use App\Models\Room;
use App\Models\User;
use App\Models\Waitlist;
use App\Models\Ward;
use Database\Seeders\HimsSeeder;
use Database\Seeders\RbacReconciliationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RbacReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconciliation_revokes_doctor_appointment_update_permission(): void
    {
        $this->seed(HimsSeeder::class);

        $doctor = User::where('email', 'doctor@coor.test')->firstOrFail();
        $updatePermission = Permission::where('name', 'update-appointments')->firstOrFail();
        $doctor->roles()->each(fn ($role) => $role->permissions()->syncWithoutDetaching([$updatePermission->id]));

        $this->seed(RbacReconciliationSeeder::class);
        $this->assertFalse($doctor->fresh()->hasPermission('update-appointments'));

        $waitlist = Waitlist::create([
            'patient_id' => Patient::firstOrFail()->id,
            'provider_id' => Provider::firstOrFail()->id,
            'status' => Waitlist::STATUS_WAITING,
        ]);

        $this->actingAs($doctor)->patchJson("/api/v1/waitlists/{$waitlist->id}", [
            'status' => Waitlist::STATUS_REMOVED,
        ])->assertForbidden();
        $this->assertSame(Waitlist::STATUS_WAITING, $waitlist->fresh()->status);
    }

    public function test_patient_can_book_and_manage_only_their_own_appointments(): void
    {
        $this->seed(HimsSeeder::class);

        $patientUser = User::where('email', 'patient@coor.test')->firstOrFail();
        $ownPatient = $patientUser->patient()->firstOrFail();
        $otherPatient = Patient::whereNull('user_id')->firstOrFail();
        $provider = Provider::query()->firstOrFail();
        $this->acceptRequiredPrivacyDocuments($ownPatient, $patientUser);
        $appointmentType = AppointmentType::query()->firstOrFail();
        $bookingStartsAt = now()->addDays(3)->setTime(9, 0);
        $bookingSlot = AppointmentSlot::create([
            'provider_id' => $provider->id,
            'appointment_type_id' => $appointmentType->id,
            'starts_at' => $bookingStartsAt,
            'ends_at' => $bookingStartsAt->copy()->addMinutes(30),
            'status' => AppointmentSlot::STATUS_AVAILABLE,
        ]);
        $token = $patientUser->createToken('patient-appointments')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/appointments', [
                'patient_id' => 999999,
                'provider_id' => $provider->id,
                'starts_at' => now()->addDays(3)->setTime(9, 0)->toDateTimeString(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('patient_id');

        $booking = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/appointments', [
                'patient_id' => $otherPatient->id,
                'provider_id' => $provider->id,
                'department_id' => $provider->department_id,
                'appointment_type_id' => $appointmentType->id,
                'starts_at' => $bookingStartsAt->toDateTimeString(),
                'appointment_slot_id' => $bookingSlot->id,
                'duration' => 30,
            ])
            ->assertCreated()
            ->assertJsonPath('data.patient_id', $ownPatient->id);

        $ownAppointment = Appointment::findOrFail($booking->json('data.id'));
        $foreignAppointment = Appointment::create([
            'appointment_number' => 'APT-RBAC-FOREIGN',
            'patient_id' => $otherPatient->id,
            'provider_id' => $provider->id,
            'starts_at' => now()->addDays(5)->setTime(9, 0),
            'ends_at' => now()->addDays(5)->setTime(9, 30),
            'status' => Appointment::STATUS_PENDING,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/appointments')
            ->assertOk()
            ->assertJsonFragment(['id' => $ownAppointment->id])
            ->assertJsonMissing(['id' => $foreignAppointment->id]);

        $rescheduleStartsAt = now()->addDays(4)->setTime(10, 0);
        $rescheduleSlot = AppointmentSlot::create([
            'provider_id' => $provider->id,
            'starts_at' => $rescheduleStartsAt,
            'ends_at' => $rescheduleStartsAt->copy()->addMinutes(30),
            'status' => AppointmentSlot::STATUS_AVAILABLE,
        ]);
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/appointments/{$ownAppointment->id}/reschedule", [
                'starts_at' => $rescheduleStartsAt->toDateTimeString(),
                'appointment_slot_id' => $rescheduleSlot->id,
            ])->assertOk();
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/appointments/{$ownAppointment->id}/cancel")
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/appointments/{$foreignAppointment->id}/cancel")
            ->assertForbidden();
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/appointments/{$foreignAppointment->id}/reschedule", [
                'starts_at' => now()->addDays(6)->setTime(10, 0)->toDateTimeString(),
            ])->assertForbidden();

        $this->assertSame(Appointment::STATUS_CANCELLED, $ownAppointment->fresh()->status);
        $this->assertSame(Appointment::STATUS_PENDING, $foreignAppointment->fresh()->status);
    }

    public function test_nurse_can_update_bed_status_but_cannot_assign_beds_or_read_financial_details(): void
    {
        $this->seed(HimsSeeder::class);

        $nurse = User::where('email', 'nurse@coor.test')->firstOrFail();
        $patient = Patient::query()->firstOrFail();
        $bed = $this->createAvailableBed('NURSE-RBAC');
        $admission = Admission::create([
            'admission_number' => 'ADM-RBAC-NURSE',
            'patient_id' => $patient->id,
            'status' => Admission::STATUS_ADMITTED,
        ]);
        EncounterBill::create([
            'statement_number' => 'STM-RBAC-NURSE',
            'patient_id' => $patient->id,
            'admission_id' => $admission->id,
            'status' => EncounterBill::STATUS_PAID,
            'currency' => 'PHP',
            'subtotal' => 100,
            'total' => 100,
            'source_fingerprint' => str_repeat('a', 64),
            'generated_at' => now(),
            'paid_at' => now(),
        ]);
        $token = $nurse->createToken('nurse-rbac')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson("/api/v1/patients/{$patient->id}", ['phone' => '09170000000'])
            ->assertForbidden();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson("/api/v1/beds/{$bed->id}/status", ['status' => 'CLEANING'])
            ->assertOk();
        $this->assertSame('CLEANING', $bed->fresh()->status);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/beds/{$bed->id}/assign", ['admission_id' => $admission->id])
            ->assertForbidden();
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/admissions/{$admission->id}/billing-summary")
            ->assertForbidden();

        $paymentStatus = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/admissions/{$admission->id}/payment-status")
            ->assertOk()
            ->assertJsonPath('data.status', EncounterBill::STATUS_PAID);
        $paymentStatus->assertJsonMissingPath('data.total');
        $paymentStatus->assertJsonMissingPath('data.amount_paid');
    }

    public function test_registration_staff_can_view_admissions_and_assign_a_bed(): void
    {
        $this->seed(HimsSeeder::class);

        $registration = User::where('email', 'registration@coor.test')->firstOrFail();
        $patient = Patient::query()->firstOrFail();
        $bed = $this->createAvailableBed('REGISTRATION-RBAC');
        $admission = Admission::create([
            'admission_number' => 'ADM-RBAC-REG',
            'patient_id' => $patient->id,
            'status' => Admission::STATUS_APPROVED,
        ]);
        $token = $registration->createToken('registration-rbac')->plainTextToken;

        $this->actingAs($registration)->get('/beds')->assertOk();
        $this->actingAs($registration)->get('/admissions')->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/beds/{$bed->id}/assign", ['admission_id' => $admission->id])
            ->assertCreated();

        $this->assertDatabaseHas('admissions', [
            'id' => $admission->id,
            'status' => Admission::STATUS_ADMITTED,
        ]);
    }

    public function test_reconciliation_preserves_unrelated_role_grants_and_applies_required_revocations(): void
    {
        $this->seed(HimsSeeder::class);

        $nurse = \App\Models\Role::where('name', 'nurse')->firstOrFail();
        $customPermission = Permission::firstOrCreate(['name' => 'custom-clinical-view'], ['label' => 'Custom Clinical View']);
        $nurse->permissions()->syncWithoutDetaching([$customPermission->id]);

        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RbacReconciliationSeeder::class);

        $nurse->refresh();
        $this->assertTrue($nurse->permissions()->where('name', 'custom-clinical-view')->exists());
        $this->assertFalse($nurse->permissions()->where('name', 'manage-beds')->exists());
        $this->assertFalse($nurse->permissions()->where('name', 'view-billing')->exists());
        $this->assertTrue($nurse->permissions()->where('name', 'update-bed-status')->exists());
        $this->assertTrue($nurse->permissions()->where('name', 'view-reports')->exists());

        $doctor = \App\Models\Role::where('name', 'doctor')->firstOrFail();
        $this->assertFalse($doctor->permissions()->where('name', 'view-billing')->exists());
        $this->assertTrue($doctor->permissions()->where('name', 'view-reports')->exists());
        $this->assertTrue($doctor->permissions()->where('name', 'view-payment-status')->exists());

        $patient = \App\Models\Role::where('name', 'patient')->firstOrFail();
        $this->assertFalse($patient->permissions()->where('name', 'view-billing')->exists());
        $this->assertFalse($patient->permissions()->where('name', 'view-reports')->exists());
    }

    private function createAvailableBed(string $suffix): \App\Models\Bed
    {
        $ward = Ward::create([
            'code' => 'WARD-'.$suffix,
            'name' => $suffix.' Ward',
            'type' => 'GENERAL',
            'active' => true,
        ]);
        $room = Room::create([
            'ward_id' => $ward->id,
            'number' => 'ROOM-'.$suffix,
            'type' => 'STANDARD',
        ]);

        return \App\Models\Bed::create([
            'room_id' => $room->id,
            'number' => 'BED-'.$suffix,
            'status' => \App\Models\Bed::STATUS_AVAILABLE,
        ]);
    }
}
