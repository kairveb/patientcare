<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Appointment;
use App\Models\Bed;
use App\Models\BedAssignment;
use App\Models\BedReservation;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Provider;
use App\Models\Role;
use App\Models\Room;
use App\Models\User;
use App\Models\Ward;
use Database\Seeders\HimsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowControlsVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_appointment_controls_are_only_rendered_for_registration_and_super_admin(): void
    {
        config(['security.otp_enabled' => false]);
        $this->seed(HimsSeeder::class);

        $patient = Patient::query()->firstOrFail();
        $doctor = User::whereHas('roles', fn ($query) => $query->where('name', 'doctor'))->firstOrFail();
        $provider = $doctor->provider()->firstOrFail();
        $nurse = User::whereHas('roles', fn ($query) => $query->where('name', 'nurse'))->firstOrFail();
        if ($provider->department_id) {
            $nurse->departments()->syncWithoutDetaching([$provider->department_id]);
        }
        $appointment = Appointment::create([
            'appointment_number' => 'APT-UI-AUTH-000001',
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
            'starts_at' => now()->addHour(),
            'ends_at' => now()->addHour()->addMinutes(30),
            'status' => Appointment::STATUS_CONFIRMED,
        ]);

        foreach ([$doctor, $nurse] as $user) {
            $this->actingAs($user)->get('/appointments')
                ->assertOk()
                ->assertDontSee('Book Appointment')
                ->assertDontSee('id="appointmentModal"');

            $this->actingAs($user)->get("/appointments/{$appointment->id}")
                ->assertOk()
                ->assertDontSee('Check In')
                ->assertDontSee('Cancel')
                ->assertDontSee('Reschedule')
                ->assertDontSee('Mark No-Show');
        }

        $patientUser = User::whereHas('roles', fn ($query) => $query->where('name', 'patient'))->firstOrFail();
        $this->acceptRequiredPrivacyDocuments($patientUser->patient, $patientUser);
        $this->actingAs($patientUser)->get('/patient-portal/appointments')
            ->assertOk()
            ->assertSee('Find a doctor')
            ->assertDontSee('Check In')
            ->assertSee('Cancel appointment')
            ->assertSee('Reschedule');

        foreach (['registration', 'super-admin'] as $role) {
            $user = User::whereHas('roles', fn ($query) => $query->where('name', $role))->firstOrFail();

            $this->actingAs($user)->get('/appointments')
                ->assertOk()
                ->assertSee('Book Appointment');

            $this->actingAs($user)->get("/appointments/{$appointment->id}")
                ->assertOk()
                ->assertSee('Check In')
                ->assertSee('Cancel')
                ->assertSee('Reschedule')
                ->assertSee('Mark No-Show');
        }
    }

    public function test_patient_directory_does_not_render_booking_controls_for_doctors(): void
    {
        $this->seed(HimsSeeder::class);

        $doctor = User::whereHas('roles', fn ($query) => $query->where('name', 'doctor'))->firstOrFail();
        $this->actingAs($doctor)->get('/patients')
            ->assertOk()
            ->assertDontSee('Book Appointment')
            ->assertDontSee('id="patientBookingModal"');

        $patient = Patient::query()->firstOrFail();
        $this->actingAs($doctor)->get("/patients/{$patient->id}")
            ->assertOk()
            ->assertDontSee('Book Appointment');

        foreach (['registration', 'super-admin'] as $role) {
            $user = User::whereHas('roles', fn ($query) => $query->where('name', $role))->firstOrFail();

            $this->actingAs($user)->get("/patients/{$patient->id}")
                ->assertOk()
                ->assertSee(route('appointments.create', ['patient_id' => $patient->id]), false)
                ->assertSee('Book Appointment');

            $this->actingAs($user)->get(route('appointments.create', ['patient_id' => $patient->id]))
                ->assertOk()
                ->assertSee('value="'.$patient->id.'" selected', false);
        }
    }

    public function test_bed_board_only_shows_actionable_status_controls_and_accurate_bed_state(): void
    {
        $this->seed(HimsSeeder::class);

        $nurse = User::whereHas('roles', fn ($query) => $query->where('name', 'nurse'))->firstOrFail();
        $patient = Patient::query()->firstOrFail();
        $ward = Ward::create([
            'code' => 'WARD-UI-001',
            'name' => 'UI Test Ward',
            'type' => 'GENERAL',
            'active' => true,
        ]);
        $room = Room::create([
            'ward_id' => $ward->id,
            'number' => 'UI-ROOM-001',
            'type' => 'STANDARD',
        ]);

        $availableBed = Bed::create([
            'room_id' => $room->id,
            'number' => 'UI-AVAILABLE',
            'status' => Bed::STATUS_AVAILABLE,
        ]);
        $occupiedBed = Bed::create([
            'room_id' => $room->id,
            'number' => 'UI-OCCUPIED',
            'status' => Bed::STATUS_OCCUPIED,
        ]);
        $reservedBed = Bed::create([
            'room_id' => $room->id,
            'number' => 'UI-RESERVED',
            'status' => Bed::STATUS_RESERVED,
        ]);
        $cleaningBed = Bed::create([
            'room_id' => $room->id,
            'number' => 'UI-CLEANING',
            'status' => Bed::STATUS_CLEANING,
        ]);

        $occupiedAdmission = Admission::create([
            'admission_number' => 'ADM-UI-OCCUPIED-001',
            'patient_id' => $patient->id,
            'status' => Admission::STATUS_ADMITTED,
        ]);
        BedAssignment::create([
            'admission_id' => $occupiedAdmission->id,
            'bed_id' => $occupiedBed->id,
            'assigned_at' => now(),
            'status' => BedAssignment::STATUS_ACTIVE,
        ]);

        $reservedAdmission = Admission::create([
            'admission_number' => 'ADM-UI-RESERVED-001',
            'patient_id' => $patient->id,
            'status' => Admission::STATUS_APPROVED,
        ]);
        BedReservation::create([
            'admission_id' => $reservedAdmission->id,
            'bed_id' => $reservedBed->id,
            'status' => BedReservation::STATUS_ACTIVE,
        ]);

        $response = $this->actingAs($nurse)->get('/beds')->assertOk();
        $response->assertSee('Ready for assignment')
            ->assertSee('Reserved for admission')
            ->assertSee('Cleaning in progress')
            ->assertSee('bedStatusModal-'.$availableBed->id)
            ->assertSee('bedStatusModal-'.$cleaningBed->id)
            ->assertDontSee('bedStatusModal-'.$occupiedBed->id)
            ->assertDontSee('bedStatusModal-'.$reservedBed->id);
    }

    public function test_admission_manage_action_is_not_rendered_for_discharged_admissions(): void
    {
        $this->seed(HimsSeeder::class);

        $nurse = User::whereHas('roles', fn ($query) => $query->where('name', 'nurse'))->firstOrFail();
        $patient = Patient::query()->firstOrFail();
        Admission::create([
            'admission_number' => 'ADM-UI-DISCHARGED-001',
            'patient_id' => $patient->id,
            'status' => Admission::STATUS_DISCHARGED,
        ]);
        Admission::create([
            'admission_number' => 'ADM-UI-REQUESTED-001',
            'patient_id' => $patient->id,
            'status' => Admission::STATUS_REQUESTED,
        ]);

        $response = $this->actingAs($nurse)->get('/admissions')->assertOk();
        $response->assertDontSee('New Admission Order')
            ->assertSee('ADM-UI-REQUESTED-001')
            ->assertSee('View')
            ->assertDontSee('admissionStatusModal-');
    }

    public function test_bed_and_admission_controls_are_hidden_when_the_role_lacks_the_required_permission(): void
    {
        $this->seed(HimsSeeder::class);

        $nurseRole = Role::where('name', 'nurse')->firstOrFail();
        $restrictedPermissions = Permission::whereIn('name', [
            'update-bed-status',
            'assign-beds',
            'execute-discharges',
        ])->pluck('id');
        $nurseRole->permissions()->detach($restrictedPermissions);

        $nurse = User::whereHas('roles', fn ($query) => $query->where('name', 'nurse'))->firstOrFail();
        $patient = Patient::query()->firstOrFail();
        $ward = Ward::create([
            'code' => 'WARD-UI-002',
            'name' => 'Restricted UI Ward',
            'type' => 'GENERAL',
            'active' => true,
        ]);
        $room = Room::create([
            'ward_id' => $ward->id,
            'number' => 'UI-ROOM-002',
            'type' => 'STANDARD',
        ]);
        Bed::create([
            'room_id' => $room->id,
            'number' => 'UI-RESTRICTED',
            'status' => Bed::STATUS_AVAILABLE,
        ]);
        Admission::create([
            'admission_number' => 'ADM-UI-RESTRICTED-001',
            'patient_id' => $patient->id,
            'status' => Admission::STATUS_REQUESTED,
        ]);

        $this->actingAs($nurse)->get('/beds')
            ->assertOk()
            ->assertDontSee('Update status')
            ->assertDontSee('bedStatusModal-');

        $this->actingAs($nurse)->get('/admissions')
            ->assertOk()
            ->assertDontSee('New Admission')
            ->assertDontSee('admissionStatusModal-')
            ->assertDontSee('id="admissionModal"');
    }

    public function test_web_appointment_lifecycle_routes_require_registration_or_super_admin_role(): void
    {
        $this->seed(HimsSeeder::class);

        $appointment = Appointment::create([
            'appointment_number' => 'APT-UI-AUTH-000002',
            'patient_id' => Patient::query()->firstOrFail()->id,
            'provider_id' => Provider::query()->firstOrFail()->id,
            'starts_at' => now()->addDay()->setTime(9, 0),
            'ends_at' => now()->addDay()->setTime(9, 30),
            'status' => Appointment::STATUS_CONFIRMED,
        ]);
        $doctor = User::whereHas('roles', fn ($query) => $query->where('name', 'doctor'))->firstOrFail();
        $doctorRole = Role::where('name', 'doctor')->firstOrFail();
        $doctorRole->permissions()->syncWithoutDetaching(
            Permission::where('name', 'cancel-appointments')->pluck('id')
        );

        $this->actingAs($doctor)->post("/appointments/{$appointment->id}/cancel")->assertForbidden();
        $this->actingAs($doctor)->post("/appointments/{$appointment->id}/reschedule", [
            'starts_at' => now()->addDays(2)->setTime(10, 0)->toDateTimeString(),
        ])->assertForbidden();
        $this->actingAs($doctor)->post("/appointments/{$appointment->id}/no-show")->assertForbidden();
    }
}
