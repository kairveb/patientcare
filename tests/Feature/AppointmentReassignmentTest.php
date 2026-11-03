<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentReassignment;
use App\Models\AppointmentSlot;
use App\Models\AppointmentStatusHistory;
use App\Models\AppointmentType;
use App\Models\Department;
use App\Models\Patient;
use App\Models\Provider;
use App\Models\ProviderSchedule;
use App\Models\Role;
use App\Models\Specialty;
use App\Models\TelehealthSession;
use App\Models\User;
use App\Models\Waitlist;
use App\Models\ProviderUnavailability;
use App\Services\AppointmentReassignmentService;
use App\Services\SchedulingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AppointmentReassignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public function test_marking_provider_unavailable_blocks_generated_slots_and_slot_search(): void
    {
        $provider = Provider::factory()->create();
        $startsAt = now()->addDays(3)->setTime(9, 0);
        ProviderSchedule::create([
            'provider_id' => $provider->id,
            'day_of_week' => $startsAt->dayOfWeek,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'slot_duration' => 30,
        ]);
        $schedule = ProviderSchedule::query()->where('provider_id', $provider->id)->firstOrFail();
        app(SchedulingService::class)->generateSlots($schedule, $startsAt->toDateString(), $startsAt->toDateString());
        $slot = AppointmentSlot::query()->where('provider_id', $provider->id)->where('starts_at', $startsAt)->firstOrFail();

        app(AppointmentReassignmentService::class)->markUnavailable([
            'provider_id' => $provider->id,
            'starts_at' => $startsAt->toDateTimeString(),
            'ends_at' => $startsAt->copy()->addHour()->toDateTimeString(),
            'reason' => 'emergency',
        ], User::factory()->create()->id);

        $this->assertSame(AppointmentSlot::STATUS_BLOCKED, $slot->fresh()->status);
        $this->assertCount(0, app(SchedulingService::class)->availableSlots($provider->id, $startsAt->toDateString()));
    }

    public function test_unavailability_creates_a_case_for_each_affected_active_appointment(): void
    {
        [$original, $patient, $startsAt, $oldSlot] = $this->appointmentFixture();
        $replacement = $this->providerInDepartment($original->department_id);
        $newSlot = $this->slot($replacement, $startsAt);

        $this->assertSame(AppointmentSlot::STATUS_BOOKED, $oldSlot->status);
        app(AppointmentReassignmentService::class)->markUnavailable([
            'provider_id' => $original->id,
            'starts_at' => $startsAt->toDateTimeString(),
            'ends_at' => $startsAt->copy()->addMinutes(30)->toDateTimeString(),
            'reason' => 'sick_leave',
        ], User::factory()->create()->id);

        $case = AppointmentReassignment::query()->firstOrFail();
        $this->assertSame($patient->id, $case->appointment->patient_id);
        $this->assertSame(AppointmentReassignment::STATUS_PENDING_REVIEW, $case->status);
        $this->assertSame($replacement->id, $case->suggested_provider_id);
        $this->assertSame($newSlot->id, $case->suggested_slot_id);
        $this->assertDatabaseHas('appointment_reassignment_histories', [
            'appointment_reassignment_id' => $case->id,
            'to_status' => AppointmentReassignment::STATUS_PENDING_REVIEW,
        ]);
    }

    public function test_suggestions_prioritize_shared_specialty_then_light_load(): void
    {
        $department = Department::create(['code' => 'RANK', 'name' => 'Ranking Department']);
        $specialty = Specialty::create(['name' => 'Ranking Specialty', 'department_id' => $department->id]);
        $original = Provider::factory()->create(['department_id' => $department->id]);
        $original->specialties()->attach($specialty);
        $appointment = $this->appointmentFor($original, now()->addDays(4)->setTime(10, 0));

        $sameSpecialtyLight = Provider::factory()->create();
        $sameSpecialtyLight->specialties()->attach($specialty);
        $sameSpecialtyHeavy = Provider::factory()->create();
        $sameSpecialtyHeavy->specialties()->attach($specialty);
        $sameDepartmentOnly = Provider::factory()->create(['department_id' => $department->id]);
        $sameDepartmentOnly->specialties()->attach(Specialty::create(['name' => 'Different specialty']));

        foreach ([$sameSpecialtyLight, $sameSpecialtyHeavy, $sameDepartmentOnly] as $candidate) {
            $this->slot($candidate, $appointment->starts_at);
        }
        Appointment::factory()->create([
            'provider_id' => $sameSpecialtyHeavy->id,
            'starts_at' => $appointment->starts_at->copy()->addDays(2),
            'ends_at' => $appointment->starts_at->copy()->addDays(2)->addMinutes(30),
            'status' => Appointment::STATUS_CONFIRMED,
        ]);
        $this->slot($sameSpecialtyHeavy, $appointment->starts_at->copy()->addDays(2));

        $suggestions = app(AppointmentReassignmentService::class)->suggestionsFor($appointment);

        $this->assertSame($sameSpecialtyLight->id, $suggestions->first()['provider']->id);
        $this->assertStringContainsString('Same specialty', $suggestions->first()['explanation']);
        $this->assertGreaterThan(
            array_search($sameSpecialtyLight->id, $suggestions->pluck('provider.id')->all(), true),
            array_search($sameDepartmentOnly->id, $suggestions->pluck('provider.id')->all(), true)
        );
    }

    public function test_approved_reassignment_moves_slot_history_and_telehealth_session_atomically(): void
    {
        [$original, , $startsAt, $oldSlot, $appointment] = $this->appointmentFixture(telehealth: true);
        $replacement = $this->providerInDepartment($original->department_id);
        $replacementSlot = $this->slot($replacement, $startsAt->copy()->addHour());
        $session = TelehealthSession::create([
            'appointment_id' => $appointment->id,
            'start_time' => $startsAt,
            'duration' => 30,
            'status' => TelehealthSession::STATUS_SCHEDULED,
            'join_url' => url('/telehealth/99/join?token=old'),
            'host_start_url' => url('/telehealth/99/join?token=old'),
        ]);
        ProviderUnavailability::create([
            'provider_id' => $original->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes(30),
            'reason' => 'emergency',
        ]);
        $actor = User::factory()->create();
        $this->makeCase($appointment, $replacement, $replacementSlot);

        app(AppointmentReassignmentService::class)->decide(
            AppointmentReassignment::query()->firstOrFail(),
            'approve',
            $actor->id
        );

        $this->assertSame($replacement->id, $appointment->fresh()->provider_id);
        $this->assertTrue($appointment->fresh()->starts_at->equalTo($replacementSlot->starts_at));
        $this->assertSame(AppointmentSlot::STATUS_BLOCKED, $oldSlot->fresh()->status);
        $this->assertSame(AppointmentSlot::STATUS_BOOKED, $replacementSlot->fresh()->status);
        $this->assertSame(AppointmentReassignment::STATUS_APPROVED, AppointmentReassignment::query()->firstOrFail()->status);
        $this->assertSame(2, AppointmentStatusHistory::query()->where('appointment_id', $appointment->id)->count());
        $this->assertTrue($session->fresh()->start_time->equalTo($replacementSlot->starts_at));
        $this->assertNotSame('old', parse_url($session->fresh()->join_url, PHP_URL_QUERY));
    }

    public function test_conflicting_reassignment_does_not_double_book_or_release_original_slot(): void
    {
        [$original, , $startsAt, $oldSlot, $appointment] = $this->appointmentFixture();
        $replacement = $this->providerInDepartment($original->department_id);
        $replacementSlot = $this->slot($replacement, $startsAt->copy()->addHour());
        $case = $this->makeCase($appointment, $replacement, $replacementSlot);
        $conflictingStart = $replacementSlot->starts_at;
        Appointment::factory()->create([
            'provider_id' => $replacement->id,
            'starts_at' => $conflictingStart,
            'ends_at' => $conflictingStart->copy()->addMinutes(30),
            'status' => Appointment::STATUS_CONFIRMED,
        ]);

        try {
            app(AppointmentReassignmentService::class)->decide($case, 'approve', User::factory()->create()->id);
            $this->fail('A conflicting reassignment must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('starts_at', $exception->errors());
        }

        $this->assertSame($original->id, $appointment->fresh()->provider_id);
        $this->assertSame(AppointmentSlot::STATUS_BOOKED, $oldSlot->fresh()->status);
        $this->assertSame(AppointmentSlot::STATUS_AVAILABLE, $replacementSlot->fresh()->status);
        $this->assertSame(AppointmentReassignment::STATUS_PENDING_REVIEW, $case->fresh()->status);
    }

    public function test_patient_decline_keeps_case_open_for_staff_follow_up(): void
    {
        [$original, $patient, $startsAt, $oldSlot, $appointment] = $this->appointmentFixture();
        $replacement = $this->providerInDepartment($original->department_id);
        $slot = $this->slot($replacement, $startsAt->copy()->addHour());
        $case = $this->makeCase($appointment, $replacement, $slot);
        $patientUser = User::factory()->create();
        $patient->update(['user_id' => $patientUser->id]);
        $patientRole = Role::firstOrCreate(['name' => 'patient'], ['label' => 'Patient']);
        $patientUser->roles()->attach($patientRole);
        $this->acceptRequiredPrivacyDocuments($patient, $patientUser);

        $this->actingAs($patientUser)
            ->post(route('patient.reassignments.respond', $case), ['action' => 'decline'])
            ->assertRedirect(route('patient.reassignments.show', $case));

        $this->assertSame(AppointmentReassignment::STATUS_DECLINED_BY_PATIENT, $case->fresh()->status);
        $this->assertSame(Appointment::STATUS_CONFIRMED, $appointment->fresh()->status);
        $this->assertSame(AppointmentSlot::STATUS_BOOKED, $oldSlot->fresh()->status);
    }

    public function test_no_candidate_cancels_the_unavailable_visit_and_creates_waitlist_entry(): void
    {
        [$original, , $startsAt, $oldSlot, $appointment] = $this->appointmentFixture();
        $actor = User::factory()->create();

        app(AppointmentReassignmentService::class)->markUnavailable([
            'provider_id' => $original->id,
            'starts_at' => $startsAt->toDateTimeString(),
            'ends_at' => $startsAt->copy()->addMinutes(30)->toDateTimeString(),
            'reason' => 'emergency',
        ], $actor->id);

        $case = AppointmentReassignment::query()->firstOrFail();
        $this->assertSame(AppointmentReassignment::STATUS_WAITLISTED, $case->status);
        $this->assertSame(Appointment::STATUS_CANCELLED, $appointment->fresh()->status);
        $this->assertSame(AppointmentSlot::STATUS_BLOCKED, $oldSlot->fresh()->status);
        $this->assertSame(Waitlist::STATUS_WAITING, $case->waitlist->status);
    }

    public function test_policy_denies_other_patient_and_non_staff_access(): void
    {
        [$original, $patient, $startsAt, $oldSlot, $appointment] = $this->appointmentFixture();
        $replacement = $this->providerInDepartment($original->department_id);
        $slot = $this->slot($replacement, $startsAt->copy()->addHour());
        $case = $this->makeCase($appointment, $replacement, $slot);
        $otherPatient = Patient::factory()->create();
        $otherUser = User::factory()->create();
        $otherPatient->update(['user_id' => $otherUser->id]);
        $otherUser->roles()->attach(Role::firstOrCreate(['name' => 'patient'], ['label' => 'Patient']));
        $this->acceptRequiredPrivacyDocuments($otherPatient, $otherUser);

        $this->actingAs($otherUser)
            ->get(route('patient.reassignments.show', $case))
            ->assertForbidden();
        $this->get(route('appointment-reassignments.index'))->assertForbidden();
    }

    public function test_registration_can_view_the_case_queue_and_candidate_explanations(): void
    {
        [$original, , $startsAt, , $appointment] = $this->appointmentFixture();
        $replacement = $this->providerInDepartment($original->department_id);
        $slot = $this->slot($replacement, $startsAt);
        $this->makeCase($appointment, $replacement, $slot);
        $registration = User::factory()->create();
        $registration->roles()->attach(Role::firstOrCreate(['name' => 'registration'], ['label' => 'Registration']));

        $this->actingAs($registration)
            ->get(route('appointment-reassignments.index'))
            ->assertOk()
            ->assertSee($replacement->full_name)
            ->assertSee('Approve suggestion');
    }

    public function test_doctor_cannot_mark_another_providers_calendar_unavailable(): void
    {
        $ownProvider = Provider::factory()->create();
        $otherProvider = Provider::factory()->create();
        $doctor = $ownProvider->user;
        $doctor->roles()->attach(Role::firstOrCreate(['name' => 'doctor'], ['label' => 'Doctor']));

        $this->actingAs($doctor)
            ->post(route('provider-unavailabilities.store'), [
                'provider_id' => $otherProvider->id,
                'starts_at' => now()->addDay()->setTime(9, 0)->toDateTimeString(),
                'ends_at' => now()->addDay()->setTime(10, 0)->toDateTimeString(),
                'reason' => 'emergency',
            ])
            ->assertForbidden();
    }

    private function appointmentFixture(bool $telehealth = false): array
    {
        $department = Department::create(['code' => 'OPS'.random_int(100, 999), 'name' => 'Operations '.random_int(100, 999)]);
        $provider = Provider::factory()->create(['department_id' => $department->id]);
        $patient = Patient::factory()->create();
        $startsAt = now()->addDays(4)->setTime(10, 0);
        $type = $telehealth
            ? AppointmentType::create(['name' => 'Reassignment Virtual '.random_int(100, 999), 'default_duration' => 30, 'telehealth' => true])
            : null;
        $oldSlot = $this->slot($provider, $startsAt, AppointmentSlot::STATUS_BOOKED);
        $appointment = Appointment::factory()->create([
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
            'department_id' => $department->id,
            'appointment_type_id' => $type?->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes(30),
            'status' => Appointment::STATUS_CONFIRMED,
        ]);
        AppointmentStatusHistory::create([
            'appointment_id' => $appointment->id,
            'from_status' => null,
            'to_status' => Appointment::STATUS_CONFIRMED,
            'reason' => 'Booked',
        ]);

        return [$provider, $patient, $startsAt, $oldSlot, $appointment];
    }

    private function appointmentFor(Provider $provider, Carbon $startsAt): Appointment
    {
        return Appointment::factory()->create([
            'provider_id' => $provider->id,
            'department_id' => $provider->department_id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes(30),
            'status' => Appointment::STATUS_CONFIRMED,
        ]);
    }

    private function providerInDepartment(?int $departmentId): Provider
    {
        return Provider::factory()->create(['department_id' => $departmentId]);
    }

    private function slot(Provider $provider, Carbon $startsAt, string|int|null $status = null): AppointmentSlot
    {
        if (is_int($status) || $status === null) {
            $slotStatus = $status ?? AppointmentSlot::STATUS_AVAILABLE;
        } else {
            $slotStatus = $status;
        }

        return AppointmentSlot::create([
            'provider_id' => $provider->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes(30),
            'status' => $slotStatus,
        ]);
    }

    private function makeCase(Appointment $appointment, Provider $provider, AppointmentSlot $slot): AppointmentReassignment
    {
        return AppointmentReassignment::create([
            'appointment_id' => $appointment->id,
            'original_provider_id' => $appointment->provider_id,
            'suggested_provider_id' => $provider->id,
            'suggested_slot_id' => $slot->id,
            'suggested_starts_at' => $slot->starts_at,
            'reason' => 'emergency',
            'status' => AppointmentReassignment::STATUS_PENDING_REVIEW,
        ]);
    }
}
