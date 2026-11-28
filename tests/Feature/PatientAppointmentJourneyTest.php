<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\AppointmentType;
use App\Models\Department;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Provider;
use App\Models\ProviderUnavailability;
use App\Models\Role;
use App\Models\Specialty;
use App\Models\User;
use App\Models\Waitlist;
use App\Notifications\AppointmentJourneyNotification;
use App\Services\AppointmentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PatientAppointmentJourneyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        foreach (['patient', 'doctor', 'nurse', 'registration', 'admin', 'super-admin'] as $name) {
            Role::firstOrCreate(['name' => $name], ['label' => ucfirst($name)]);
        }
    }

    public function test_patient_can_filter_doctors_and_view_their_calendar(): void
    {
        $department = Department::create(['code' => 'CARD', 'name' => 'Cardiology']);
        $specialty = Specialty::create(['name' => 'Cardiology', 'department_id' => $department->id]);
        $provider = Provider::factory()->create(['department_id' => $department->id]);
        $provider->specialties()->attach($specialty->id);
        $slot = $this->slot($provider, now()->addDays(2)->setTime(10, 0));
        AppointmentType::query()->create([
            'name' => 'In-person consultation',
            'telehealth' => false,
            'active' => true,
            'default_duration' => 30,
        ]);
        [$patientUser] = $this->patient();

        $this->actingAs($patientUser)
            ->get(route('doctors.index', [
                'q' => $provider->display_name,
                'department_id' => $department->id,
                'specialty_id' => $specialty->id,
                'consultation_type' => 'in_person',
                'available_within' => 7,
            ]))
            ->assertOk()
            ->assertSee($provider->display_name);

        $days = $this->getJson(route('doctors.calendar', [
            'provider' => $provider,
            'month' => $slot->starts_at->format('Y-m'),
        ]))
            ->assertOk()
            ->json('days');
        $this->assertSame(
            'few_slots',
            collect($days)->firstWhere('date', $slot->starts_at->toDateString())['state']
        );
    }

    public function test_calendar_reports_available_fully_booked_and_unavailable_days(): void
    {
        Carbon::setTestNow('2026-11-16 12:00:00');

        try {
            $provider = Provider::factory()->create();
            [$patientUser] = $this->patient();
            $available = now()->addDays(2)->setTime(9, 0);
            $full = now()->addDays(3)->setTime(9, 0);
            $unavailable = now()->addDays(4)->setTime(9, 0);

            for ($index = 0; $index < 4; $index++) {
                $this->slot($provider, $available->copy()->addMinutes(30 * $index));
            }
            $this->slot($provider, $full, AppointmentSlot::STATUS_BOOKED);
            $blocked = $this->slot($provider, $unavailable);
            $blocked->update(['status' => AppointmentSlot::STATUS_BLOCKED]);
            ProviderUnavailability::create([
                'provider_id' => $provider->id,
                'starts_at' => $unavailable,
                'ends_at' => $unavailable->copy()->addMinutes(30),
                'reason' => 'emergency',
                'recorded_by' => $patientUser->id,
            ]);

            $days = $this->actingAs($patientUser)
                ->getJson(route('doctors.calendar', [
                    'provider' => $provider,
                    'month' => $available->format('Y-m'),
                ]))
                ->assertOk()
                ->json('days');

            $states = collect($days)->keyBy('date');
            $this->assertSame('available', $states->get($available->toDateString())['state']);
            $this->assertSame('fully_booked', $states->get($full->toDateString())['state']);
            $this->assertSame('unavailable', $states->get($unavailable->toDateString())['state']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_fully_booked_profile_offers_waitlist_and_same_specialty_alternative(): void
    {
        $department = Department::create(['code' => 'NEURO', 'name' => 'Neurology']);
        $specialty = Specialty::create(['name' => 'Neurology', 'department_id' => $department->id]);
        $fullProvider = Provider::factory()->create(['department_id' => $department->id]);
        $alternative = Provider::factory()->create(['department_id' => $department->id]);
        $fullProvider->specialties()->attach($specialty->id);
        $alternative->specialties()->attach($specialty->id);
        $slot = $this->slot($alternative, now()->addDays(2)->setTime(11, 0));
        [$patientUser, $patient] = $this->patient();
        $type = AppointmentType::query()->create([
            'name' => 'In-person consultation',
            'telehealth' => false,
            'active' => true,
            'default_duration' => 30,
        ]);

        $this->actingAs($patientUser)
            ->get(route('doctors.show', ['provider' => $fullProvider, 'consultation_type' => 'in_person']))
            ->assertOk()
            ->assertSee('Fully booked this week')
            ->assertSee($alternative->display_name)
            ->assertSee((string) $slot->id)
            ->assertSee('Join waitlist');

        $this->post(route('doctors.waitlist'), [
            'provider_id' => $fullProvider->id,
            'appointment_type_id' => $type->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('waitlists', [
            'patient_id' => $patient->id,
            'provider_id' => $fullProvider->id,
            'status' => Waitlist::STATUS_WAITING,
        ]);
    }

    public function test_releasing_a_cancelled_slot_notifies_matching_waitlisted_patients(): void
    {
        $provider = Provider::factory()->create();
        [$patientUser, $patient] = $this->patient();
        $slot = $this->slot($provider, now()->addDays(2)->setTime(10, 0));
        $appointment = app(AppointmentService::class)->book([
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
            'starts_at' => $slot->starts_at->toDateTimeString(),
            'appointment_slot_id' => $slot->id,
        ]);
        Waitlist::create([
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
            'appointment_type_id' => null,
            'preferred_date' => null,
            'status' => Waitlist::STATUS_WAITING,
        ]);

        app(AppointmentService::class)->cancel($appointment, null, 'Patient requested cancellation');

        $this->assertDatabaseHas('waitlists', [
            'patient_id' => $patient->id,
            'status' => Waitlist::STATUS_OFFERED,
        ]);
        Notification::assertSentTo($patientUser, AppointmentJourneyNotification::class);
        $notice = Notification::sent($patientUser, AppointmentJourneyNotification::class)->first();
        $this->assertSame(route('doctors.show', $provider), $notice->toDatabase($patientUser)['url']);
        $this->assertStringNotContainsString('contact registration', $notice->toDatabase($patientUser)['message']);
        Notification::assertSentTo($patientUser, AppointmentJourneyNotification::class);
    }

    public function test_nurse_can_create_walk_in_appointment_through_the_api(): void
    {
        config(['appointments.requires_approval' => true]);
        $nurse = $this->userWithPermission('nurse', 'view-appointments');
        $patient = Patient::factory()->create();
        $provider = Provider::factory()->create();
        $slot = $this->slot($provider, now()->addDays(3)->setTime(10, 0));

        $this->actingAs($nurse, 'sanctum')->postJson('/api/v1/appointments', [
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
            'starts_at' => $slot->starts_at->toDateTimeString(),
            'appointment_slot_id' => $slot->id,
            'booking_source' => 'walk_in',
        ])->assertCreated()
            ->assertJsonPath('data.booking_source', 'walk_in')
            ->assertJsonPath('data.status', Appointment::STATUS_CONFIRMED);
    }

    public function test_patient_booking_requires_approval_and_registration_can_confirm_or_reject(): void
    {
        config(['appointments.requires_approval' => true]);
        [$patientUser, $patient] = $this->patient();
        $provider = Provider::factory()->create();
        $firstSlot = $this->slot($provider, now()->addDays(3)->setTime(9, 0));
        $secondSlot = $this->slot($provider, now()->addDays(4)->setTime(9, 0));
        $first = $this->bookAsPatient($patientUser, $patient, $provider, $firstSlot);
        $second = $this->bookAsPatient($patientUser, $patient, $provider, $secondSlot);
        $registration = $this->userWithPermission('registration', 'view-appointments');

        $this->assertSame(Appointment::STATUS_PENDING, $first->status);
        $this->actingAs($registration)->post(route('appointments.decide', $first), [
            'decision' => 'confirm',
            'reason' => 'Eligibility verified.',
        ])->assertRedirect();
        $this->post(route('appointments.decide', $second), [
            'decision' => 'reject',
            'reason' => 'The requested service is not available.',
        ])->assertRedirect();

        $this->assertSame(Appointment::STATUS_CONFIRMED, $first->fresh()->status);
        $this->assertSame(Appointment::STATUS_REJECTED, $second->fresh()->status);
    }

    public function test_patient_with_current_privacy_consent_can_book_without_reconsenting(): void
    {
        config(['appointments.requires_approval' => true]);
        [$patientUser, $patient] = $this->patient();
        $provider = Provider::factory()->create();
        $slot = $this->slot($provider, now()->addDays(3)->setTime(9, 0));

        $this->actingAs($patientUser)
            ->get(route('appointments.create'))
            ->assertOk()
            ->assertDontSee('name="data_privacy_consent"', false);

        $this->post(route('appointments.store'), [
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
            'starts_at' => $slot->starts_at->toDateTimeString(),
            'appointment_slot_id' => $slot->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('appointments', [
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
            'starts_at' => $slot->starts_at->toDateTimeString(),
            'status' => Appointment::STATUS_PENDING,
        ]);
        $this->assertSame(AppointmentSlot::STATUS_BOOKED, $slot->fresh()->status);
    }

    public function test_disabling_approval_confirms_online_booking_and_staff_can_book_walk_in(): void
    {
        config(['appointments.requires_approval' => false]);
        [$patientUser, $patient] = $this->patient();
        $provider = Provider::factory()->create();
        $patientSlot = $this->slot($provider, now()->addDays(5)->setTime(9, 0));
        $online = $this->bookAsPatient($patientUser, $patient, $provider, $patientSlot);
        $nurse = $this->userWithPermission('nurse', 'view-appointments');
        $otherPatient = Patient::factory()->create();
        $walkInSlot = $this->slot($provider, now()->addDays(6)->setTime(9, 0));

        $this->actingAs($nurse)->post(route('appointments.store'), [
            'patient_id' => $otherPatient->id,
            'provider_id' => $provider->id,
            'starts_at' => $walkInSlot->starts_at->toDateTimeString(),
            'appointment_slot_id' => $walkInSlot->id,
            'booking_source' => 'walk_in',
        ])->assertRedirect();

        $this->assertSame(Appointment::STATUS_CONFIRMED, $online->fresh()->status);
        $this->assertDatabaseHas('appointments', [
            'patient_id' => $otherPatient->id,
            'booking_source' => 'walk_in',
            'status' => Appointment::STATUS_CONFIRMED,
        ]);
    }

    public function test_doctor_follow_up_recommendation_is_linked_to_the_patient_booking_journey(): void
    {
        $doctor = $this->userWithPermission('doctor', 'update-encounters');
        $provider = Provider::factory()->create(['user_id' => $doctor->id]);
        $patient = Patient::factory()->create();
        $appointment = Appointment::factory()->create([
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
        ]);
        $encounter = Encounter::factory()->create([
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
            'appointment_id' => $appointment->id,
        ]);
        $suggestedDate = now()->addDays(10)->toDateString();

        $this->actingAs($doctor)->post(route('encounters.follow-up', $encounter), [
            'follow_up_date' => $suggestedDate,
            'recommendation' => 'Review symptoms after treatment.',
        ])->assertRedirect();

        $this->assertSame($suggestedDate, $encounter->fresh()->follow_up_date->toDateString());

        $patientUser = $this->patientWithUser($patient);
        $this->actingAs($patientUser)
            ->get(route('patients.portal.appointments'))
            ->assertOk()
            ->assertSee('Book follow-up')
            ->assertSee($provider->display_name);

        $followUp = $this->bookAsPatient(
            $patientUser,
            $patient,
            $provider,
            $this->slot($provider, now()->addDays(12)->setTime(9, 0)),
            $appointment->id
        );
        $this->assertSame($appointment->id, $followUp->parent_appointment_id);
    }

    public function test_patient_cannot_open_another_patients_appointment(): void
    {
        [$patientUser] = $this->patient();
        $this->grant($patientUser, 'view-appointments');
        $appointment = Appointment::factory()->create();

        $this->actingAs($patientUser)
            ->get(route('appointments.show', $appointment))
            ->assertForbidden();
    }

    private function bookAsPatient(
        User $user,
        Patient $patient,
        Provider $provider,
        AppointmentSlot $slot,
        ?int $parentAppointmentId = null
    ): Appointment
    {
        $payload = [
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
            'starts_at' => $slot->starts_at->toDateTimeString(),
            'appointment_slot_id' => $slot->id,
        ];
        if ($parentAppointmentId) {
            $payload['parent_appointment_id'] = $parentAppointmentId;
        }
        $response = $this->actingAs($user)->post(route('appointments.store'), $payload);
        $response->assertRedirect();

        return Appointment::query()
            ->where('patient_id', $patient->id)
            ->where('provider_id', $provider->id)
            ->where('starts_at', $slot->starts_at)
            ->firstOrFail();
    }

    private function slot(Provider $provider, \Illuminate\Support\Carbon $startsAt, string $status = AppointmentSlot::STATUS_AVAILABLE): AppointmentSlot
    {
        return AppointmentSlot::create([
            'provider_id' => $provider->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes(30),
            'status' => $status,
        ]);
    }

    private function patient(): array
    {
        $user = $this->userWithRole('patient');
        $patient = Patient::factory()->linkedTo($user)->create();
        $this->acceptRequiredPrivacyDocuments($patient, $user);

        return [$user, $patient];
    }

    private function patientWithUser(Patient $patient): User
    {
        $user = $this->userWithRole('patient');
        $patient->update(['user_id' => $user->id]);
        $this->acceptRequiredPrivacyDocuments($patient, $user);

        return $user;
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => $roleName], ['label' => ucfirst($roleName)]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }

    private function userWithPermission(string $roleName, string $permissionName): User
    {
        $user = $this->userWithRole($roleName);
        $this->grant($user, $permissionName);

        return $user;
    }

    private function grant(User $user, string $permissionName): void
    {
        $permission = Permission::firstOrCreate(
            ['name' => $permissionName],
            ['label' => ucwords(str_replace('-', ' ', $permissionName))]
        );
        $user->roles()->firstOrFail()->permissions()->syncWithoutDetaching([$permission->id]);
    }
}
