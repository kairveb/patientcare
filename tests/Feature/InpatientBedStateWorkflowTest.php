<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Bed;
use App\Models\BedAssignment;
use App\Models\BedReservation;
use App\Models\ChargeRate;
use App\Models\ErVisit;
use App\Models\Patient;
use App\Models\Room;
use App\Models\User;
use App\Models\Ward;
use App\Services\AdmissionService;
use App\Services\BedManagementService;
use App\Services\BillingAggregationService;
use App\Services\DischargeSummaryService;
use Database\Seeders\HimsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InpatientBedStateWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(HimsSeeder::class);
    }

    public function test_reserved_bed_requires_a_matching_active_reservation(): void
    {
        [$admission, , $bed] = $this->fixture();
        $bed->update(['status' => Bed::STATUS_RESERVED]);

        $this->assertValidationError(
            fn () => app(BedManagementService::class)->assignBed($admission, $bed->id),
            'reservation_id'
        );

        [$otherAdmission] = $this->fixture();
        BedReservation::create([
            'bed_id' => $bed->id,
            'admission_id' => $otherAdmission->id,
            'status' => BedReservation::STATUS_ACTIVE,
        ]);
        $this->assertValidationError(
            fn () => app(BedManagementService::class)->assignBed($admission, $bed->id, BedReservation::query()->first()->id),
            'reservation_id'
        );
        $this->assertSame(Bed::STATUS_RESERVED, $bed->fresh()->status);
    }

    public function test_expired_reservation_is_rejected_and_marked_expired(): void
    {
        [$admission, , $bed] = $this->fixture();
        $bed->update(['status' => Bed::STATUS_RESERVED]);
        $reservation = BedReservation::create([
            'bed_id' => $bed->id,
            'admission_id' => $admission->id,
            'expires_at' => now()->subMinute(),
            'status' => BedReservation::STATUS_ACTIVE,
        ]);

        try {
            app(BedManagementService::class)->assignBed($admission, $bed->id, $reservation->id);
            $this->fail('An expired bed reservation must not be converted.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('expired', strtolower($exception->errors()['reservation_id'][0]));
        }

        $this->assertSame(BedReservation::STATUS_EXPIRED, $reservation->fresh()->status);
        $this->assertSame(Bed::STATUS_AVAILABLE, $bed->fresh()->status);
    }

    public function test_expiring_reservation_makes_bed_available_for_another_admission(): void
    {
        [$admission, , $bed] = $this->fixture();
        $bed->update(['status' => Bed::STATUS_RESERVED]);
        $reservation = BedReservation::create([
            'bed_id' => $bed->id,
            'admission_id' => $admission->id,
            'expires_at' => now()->subMinute(),
            'status' => BedReservation::STATUS_ACTIVE,
        ]);
        [$otherAdmission] = $this->fixture();

        $this->assertValidationError(
            fn () => app(BedManagementService::class)->assignBed($admission, $bed->id, $reservation->id),
            'reservation_id'
        );

        $this->assertSame(Bed::STATUS_AVAILABLE, $bed->fresh()->status);
        $this->assertTrue(app(BedManagementService::class)->availableBeds()->contains('id', $bed->id));
        $this->assertTrue(app(BedManagementService::class)->assignableBeds($otherAdmission)->contains('id', $bed->id));
        $newAssignment = app(BedManagementService::class)->assignBed($otherAdmission, $bed->id);
        $this->assertSame($bed->id, $newAssignment->bed_id);
    }

    public function test_valid_reservation_is_converted_when_assigning_a_reserved_bed(): void
    {
        [$admission, , $bed] = $this->fixture();
        $bed->update(['status' => Bed::STATUS_RESERVED]);
        $reservation = BedReservation::create([
            'bed_id' => $bed->id,
            'admission_id' => $admission->id,
            'expires_at' => now()->addMinutes(10),
            'status' => BedReservation::STATUS_ACTIVE,
        ]);

        $assignment = app(BedManagementService::class)->assignBed($admission, $bed->id, $reservation->id);

        $this->assertSame(BedAssignment::STATUS_ACTIVE, $assignment->status);
        $this->assertSame(Bed::STATUS_OCCUPIED, $bed->fresh()->status);
        $this->assertSame(BedReservation::STATUS_CONVERTED, $reservation->fresh()->status);
    }

    public function test_reservations_always_expire_and_replacing_one_releases_its_old_bed(): void
    {
        [$admission, $ward, $oldBed] = $this->fixture();
        $service = app(BedManagementService::class);
        $newBed = $this->createBed($ward);
        config(['hims.reservation_default_minutes' => 45]);

        $startedAt = now();
        $oldReservation = $service->reserveBed($admission, $oldBed->id);
        $this->assertEqualsWithDelta($startedAt->copy()->addMinutes(45)->timestamp, $oldReservation->expires_at->timestamp, 2);

        $newReservation = $service->reserveBed($admission, $newBed->id, 15);
        $this->assertSame(BedReservation::STATUS_CANCELLED, $oldReservation->fresh()->status);
        $this->assertSame(Bed::STATUS_AVAILABLE, $oldBed->fresh()->status);
        $this->assertSame(BedReservation::STATUS_ACTIVE, $newReservation->status);
        $this->assertSame(Bed::STATUS_RESERVED, $newBed->fresh()->status);
        $this->assertGreaterThan(now(), $newReservation->expires_at);
    }

    public function test_cancel_reservation_releases_bed_through_web_and_api_actions(): void
    {
        [$admission, , $bed] = $this->fixture();
        $service = app(BedManagementService::class);
        $reservation = $service->reserveBed($admission, $bed->id);
        $registration = $this->registration();

        $this->actingAs($registration)->get(route('admissions.show', $admission))
            ->assertOk()
            ->assertSee('Cancel reservation');
        $this->actingAs($registration)
            ->post(route('admissions.reservations.cancel', [$admission, $reservation]))
            ->assertRedirect()
            ->assertSessionHas('success');
        $this->assertSame(BedReservation::STATUS_CANCELLED, $reservation->fresh()->status);
        $this->assertSame(Bed::STATUS_AVAILABLE, $bed->fresh()->status);

        [$apiAdmission, , $apiBed] = $this->fixture();
        $apiReservation = $service->reserveBed($apiAdmission, $apiBed->id);
        $token = $registration->createToken('reservation-cancel-tests')->plainTextToken;
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/beds/{$apiBed->id}/reservations/{$apiReservation->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', BedReservation::STATUS_CANCELLED);
        $this->assertSame(Bed::STATUS_AVAILABLE, $apiBed->fresh()->status);
    }

    public function test_reassigning_an_admission_marks_the_previous_bed_cleaning(): void
    {
        [$admission, , $oldBed] = $this->fixture();
        $newBed = $this->createBed($oldBed->room->ward);
        $oldBed->update(['status' => Bed::STATUS_OCCUPIED]);
        BedAssignment::create([
            'admission_id' => $admission->id,
            'bed_id' => $oldBed->id,
            'assigned_at' => now()->subHour(),
            'status' => BedAssignment::STATUS_ACTIVE,
        ]);

        app(BedManagementService::class)->assignBed($admission, $newBed->id);

        $this->assertSame(Bed::STATUS_CLEANING, $oldBed->fresh()->status);
        $this->assertSame(Bed::STATUS_OCCUPIED, $newBed->fresh()->status);
    }

    public function test_bed_status_cannot_be_available_while_an_assignment_or_reservation_is_active(): void
    {
        [$admission, , $bed] = $this->fixture();
        $bed->update(['status' => Bed::STATUS_OCCUPIED]);
        BedAssignment::create([
            'admission_id' => $admission->id,
            'bed_id' => $bed->id,
            'assigned_at' => now(),
            'status' => BedAssignment::STATUS_ACTIVE,
        ]);

        $this->actingAs($this->nurse())->post(route('beds.status', $bed), ['status' => Bed::STATUS_AVAILABLE])
            ->assertSessionHasErrors('status');
        $this->patchJson("/api/v1/beds/{$bed->id}/status", ['status' => Bed::STATUS_AVAILABLE])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
        $this->assertSame(Bed::STATUS_OCCUPIED, $bed->fresh()->status);

        [$reservedAdmission, , $reservedBed] = $this->fixture();
        $reservedBed->update(['status' => Bed::STATUS_RESERVED]);
        BedReservation::create([
            'bed_id' => $reservedBed->id,
            'admission_id' => $reservedAdmission->id,
            'expires_at' => now()->addHour(),
            'status' => BedReservation::STATUS_ACTIVE,
        ]);

        $this->actingAs($this->nurse())->post(route('beds.status', $reservedBed), ['status' => Bed::STATUS_AVAILABLE])
            ->assertSessionHasErrors('status');
        $this->patchJson("/api/v1/beds/{$reservedBed->id}/status", [
            'status' => Bed::STATUS_AVAILABLE,
        ])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame(Bed::STATUS_RESERVED, $reservedBed->fresh()->status);
    }

    public function test_admission_approval_and_admission_require_the_previous_lifecycle_status(): void
    {
        [$admission, , $bed] = $this->fixture(Admission::STATUS_APPROVED);
        $service = app(AdmissionService::class);
        $this->assertValidationError(fn () => $service->approve($admission), 'status');

        [$requestedAdmission, , $requestedBed] = $this->fixture();
        $this->assertValidationError(fn () => $service->admit($requestedAdmission, $requestedBed->id), 'status');
        $this->assertSame(Admission::STATUS_REQUESTED, $requestedAdmission->fresh()->status);
        $this->assertSame(Bed::STATUS_AVAILABLE, $requestedBed->fresh()->status);

        $service->approve($requestedAdmission->fresh());
        $this->assertSame(Admission::STATUS_APPROVED, $requestedAdmission->fresh()->status);
        $service->admit($requestedAdmission->fresh(), $requestedBed->id);
        $this->assertSame(Admission::STATUS_ADMITTED, $requestedAdmission->fresh()->status);
        $this->assertSame(Bed::STATUS_OCCUPIED, $requestedBed->fresh()->status);
    }

    public function test_bed_assignment_api_enforces_and_advances_admission_lifecycle(): void
    {
        [$admission, , $bed] = $this->fixture();
        $registration = $this->registration();
        $token = $registration->createToken('bed-admit-tests')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/beds/{$bed->id}/assign", ['admission_id' => $admission->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
        $this->assertSame(Bed::STATUS_AVAILABLE, $bed->fresh()->status);

        app(AdmissionService::class)->approve($admission);
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/beds/{$bed->id}/assign", ['admission_id' => $admission->id])
            ->assertCreated()
            ->assertJsonPath('data.status', BedAssignment::STATUS_ACTIVE);

        $this->assertSame(Admission::STATUS_ADMITTED, $admission->fresh()->status);
        $this->assertSame(Bed::STATUS_OCCUPIED, $bed->fresh()->status);
    }

    public function test_existing_admission_without_active_bed_can_be_assigned_without_changing_lifecycle(): void
    {
        [$admission, , $bed] = $this->fixture(Admission::STATUS_ADMITTED);
        $originalAdmittedAt = $admission->admitted_at;
        $registration = $this->registration();

        $this->actingAs($registration)->get(route('admissions.show', $admission))
            ->assertOk()
            ->assertSee('No bed assigned to this admission.')
            ->assertSee('Assign Missing Bed');
        $this->actingAs($registration)->get(route('admissions.index'))
            ->assertOk()
            ->assertSee('No bed assigned');
        $this->actingAs($registration)->post(route('admissions.admit', $admission), ['bed_id' => $bed->id])
            ->assertRedirect()
            ->assertSessionHas('success', 'Bed assigned to the existing admission.');

        $this->assertSame(Admission::STATUS_ADMITTED, $admission->fresh()->status);
        $this->assertEquals($originalAdmittedAt, $admission->fresh()->admitted_at);
        $this->assertSame(Bed::STATUS_OCCUPIED, $bed->fresh()->status);
        $this->assertDatabaseHas('bed_assignments', [
            'admission_id' => $admission->id,
            'bed_id' => $bed->id,
            'status' => BedAssignment::STATUS_ACTIVE,
        ]);

        [$apiAdmission, , $apiBed] = $this->fixture(Admission::STATUS_TRANSFERRED);
        $token = $registration->createToken('existing-admission-bed-assignment')->plainTextToken;
        $this->withToken($token)->getJson("/api/v1/admissions/{$apiAdmission->id}")
            ->assertOk()
            ->assertJsonPath('data.bed_assignments', []);
        $this->withToken($token)->postJson("/api/v1/beds/{$apiBed->id}/assign", [
            'admission_id' => $apiAdmission->id,
        ])->assertCreated()
            ->assertJsonPath('data.status', BedAssignment::STATUS_ACTIVE);
        $this->assertSame(Admission::STATUS_TRANSFERRED, $apiAdmission->fresh()->status);
        $this->assertSame(Bed::STATUS_OCCUPIED, $apiBed->fresh()->status);
    }

    public function test_direct_and_er_admission_creation_require_an_attending_provider(): void
    {
        $doctor = User::where('email', 'doctor@coor.test')->firstOrFail();
        $patient = Patient::query()->firstOrFail();

        $this->actingAs($doctor)->post('/admissions', ['patient_id' => $patient->id])
            ->assertSessionHasErrors('attending_provider_id');

        $token = $doctor->createToken('inpatient-tests')->plainTextToken;
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/admissions', ['patient_id' => $patient->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('attending_provider_id');

        $visit = ErVisit::create([
            'visit_number' => 'ER-ATTENDING-'.uniqid(),
            'patient_id' => $patient->id,
            'chief_complaint' => 'Admission test',
            'arrived_at' => now(),
            'status' => ErVisit::STATUS_ARRIVED,
        ]);
        $this->actingAs($doctor)->post(route('emergency.admission.store', $visit), [])
            ->assertSessionHasErrors('attending_provider_id');
        $this->assertDatabaseMissing('admissions', ['er_visit_id' => $visit->id]);

        $this->withHeader('Authorization', 'Bearer '.$doctor->createToken('er-attending-tests')->plainTextToken)
            ->postJson('/api/v1/admissions', [
                'patient_id' => $patient->id,
                'er_visit_id' => $visit->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('attending_provider_id');

        try {
            app(AdmissionService::class)->create(['patient_id' => $patient->id]);
            $this->fail('The admission service must require the attending provider.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('attending_provider_id', $exception->errors());
        }

        try {
            app(AdmissionService::class)->createFromErVisit($visit, []);
            $this->fail('ER-origin admission creation must require the attending provider.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('attending_provider_id', $exception->errors());
        }
    }

    public function test_admissions_list_discharge_action_is_rendered_only_when_readiness_checks_pass(): void
    {
        [$admission] = $this->fixture(Admission::STATUS_ADMITTED);
        $nurse = $this->nurse();
        $doctor = User::where('email', 'doctor@coor.test')->firstOrFail();
        $this->actingAs($nurse)->get('/admissions')
            ->assertOk()
            ->assertSee('View');
        $this->actingAs($nurse)->get(route('admissions.show', $admission))
            ->assertOk()
            ->assertDontSee('Confirm Discharge')
            ->assertSee('Discharge is unavailable');

        $summary = app(DischargeSummaryService::class)->saveAdmission($admission, $doctor, [
            'final_diagnosis' => 'Diagnosis',
            'clinical_course' => 'Course',
            'discharge_instructions' => 'Instructions',
        ]);
        app(DischargeSummaryService::class)->approve($summary, $doctor);
        ChargeRate::create([
            'charge_code' => 'bed:default',
            'category' => 'BED_DAILY',
            'description' => 'Daily bed rate',
            'unit_amount' => 800,
            'currency' => 'PHP',
            'active' => true,
        ]);
        $billing = app(BillingAggregationService::class);
        $bill = $billing->generateForAdmission($admission, $nurse);
        $billing->markPaid($bill, 'PAID-'.uniqid(), $bill->total);

        $this->actingAs($nurse)->get(route('admissions.show', $admission))
            ->assertOk()
            ->assertSee('Confirm Discharge');
    }

    public function test_missing_attending_report_lists_only_admission_identifiers(): void
    {
        $patient = Patient::query()->firstOrFail();
        $admission = Admission::create([
            'admission_number' => 'ADM-MISSING-ATTENDING',
            'patient_id' => $patient->id,
            'status' => Admission::STATUS_REQUESTED,
        ]);

        $this->artisan('hims:admissions:missing-attending')
            ->expectsTable(
                ['Admission ID'],
                [[$admission->id]]
            )
            ->assertExitCode(0);
    }

    public function test_discharge_rejects_requested_approved_and_already_discharged_admissions_with_validation_errors(): void
    {
        $this->actingAs($this->nurse());
        $service = app(AdmissionService::class);
        [$requested] = $this->fixture(Admission::STATUS_REQUESTED);
        [$approved] = $this->fixture(Admission::STATUS_APPROVED);
        foreach ([$requested, $approved] as $admission) {
            $this->assertValidationError(fn () => $service->discharge($admission, []), 'status');
        }

        [$alreadyDischarged] = $this->fixture(Admission::STATUS_ADMITTED);
        \App\Models\Discharge::create([
            'admission_id' => $alreadyDischarged->id,
            'authorized_by' => $this->nurse()->id,
            'discharged_at' => now(),
        ]);
        $this->assertValidationError(fn () => $service->discharge($alreadyDischarged, []), 'discharge');
    }

    public function test_bed_board_and_admission_assignment_list_filter_by_ward(): void
    {
        [$admission, $wardOne, $bedOne] = $this->fixture(Admission::STATUS_APPROVED);
        $wardTwo = Ward::create([
            'code' => 'WARD-FILTER-2',
            'name' => 'Filter Ward Two',
            'type' => 'GENERAL',
            'active' => true,
        ]);
        $bedTwo = $this->createBed($wardTwo);

        $this->actingAs($this->nurse())->get('/beds?ward_id='.$wardOne->id)
            ->assertOk()
            ->assertSee('Filter Ward One')
            ->assertSee($bedOne->number)
            ->assertDontSee($bedTwo->number);

        $this->actingAs($this->registration())->get(route('admissions.show', [
            'admission' => $admission,
            'ward_id' => $wardOne->id,
        ]))
            ->assertOk()
            ->assertSee('Filter Ward One')
            ->assertSee($bedOne->number)
            ->assertDontSee($bedTwo->number);
    }

    private function fixture(string $status = Admission::STATUS_REQUESTED): array
    {
        $nurse = $this->nurse();
        $patient = Patient::query()->firstOrFail();
        $admission = Admission::create([
            'admission_number' => 'ADM-BED-'.uniqid(),
            'patient_id' => $patient->id,
            'attending_provider_id' => User::where('email', 'doctor@coor.test')->firstOrFail()->provider->id,
            'status' => $status,
            'admitted_at' => $status === Admission::STATUS_ADMITTED ? now() : null,
            'created_by' => $nurse->id,
        ]);
        $ward = Ward::create([
            'code' => 'WARD-FILTER-'.uniqid(),
            'name' => 'Filter Ward One '.uniqid(),
            'type' => 'GENERAL',
            'active' => true,
        ]);

        return [$admission, $ward, $this->createBed($ward)];
    }

    private function createBed(Ward $ward): Bed
    {
        $room = Room::create([
            'ward_id' => $ward->id,
            'number' => 'ROOM-'.uniqid(),
            'type' => 'STANDARD',
        ]);

        return Bed::create([
            'room_id' => $room->id,
            'number' => 'BED-'.uniqid(),
            'status' => Bed::STATUS_AVAILABLE,
        ]);
    }

    private function nurse(): User
    {
        return User::where('email', 'nurse@coor.test')->firstOrFail();
    }

    private function registration(): User
    {
        return User::where('email', 'registration@coor.test')->firstOrFail();
    }

    private function assertValidationError(callable $callback, string $key): void
    {
        try {
            $callback();
            $this->fail("Expected a validation error for {$key}.");
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($key, $exception->errors());
        }
    }
}
