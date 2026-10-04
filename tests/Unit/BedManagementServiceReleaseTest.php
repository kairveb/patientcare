<?php

namespace Tests\Unit;

use App\Models\Admission;
use App\Models\Bed;
use App\Models\BedAssignment;
use App\Models\Patient;
use App\Models\Room;
use App\Models\User;
use App\Models\Ward;
use App\Services\AuditLogService;
use App\Services\BedManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class BedManagementServiceReleaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_release_bed_rejects_an_admission_without_a_matching_active_assignment(): void
    {
        $user = User::factory()->create();
        $patient = Patient::create([
            'mrn' => 'MRN-RELEASE-' . $user->id,
            'first_name' => 'Release',
            'last_name' => 'Patient',
            'date_of_birth' => '1990-01-01',
            'sex' => 'Female',
        ]);
        $requestedAdmission = Admission::create([
            'admission_number' => 'ADM-RELEASE-' . $user->id,
            'patient_id' => $patient->id,
            'status' => Admission::STATUS_REQUESTED,
            'created_by' => $user->id,
        ]);
        $assignedAdmission = Admission::create([
            'admission_number' => 'ADM-ASSIGNED-' . $user->id,
            'patient_id' => $patient->id,
            'status' => Admission::STATUS_REQUESTED,
            'created_by' => $user->id,
        ]);
        $ward = Ward::create([
            'code' => 'WARD-RELEASE-' . $user->id,
            'name' => 'Release Ward',
            'type' => 'GENERAL',
            'active' => true,
        ]);
        $room = Room::create([
            'ward_id' => $ward->id,
            'number' => 'ROOM-RELEASE-' . $user->id,
            'type' => 'STANDARD',
        ]);
        $bed = Bed::create([
            'room_id' => $room->id,
            'number' => 'BED-RELEASE-' . $user->id,
            'status' => Bed::STATUS_OCCUPIED,
        ]);
        $assignment = BedAssignment::create([
            'admission_id' => $assignedAdmission->id,
            'bed_id' => $bed->id,
            'assigned_at' => now(),
            'status' => BedAssignment::STATUS_ACTIVE,
        ]);

        $audit = Mockery::mock(AuditLogService::class);
        $audit->shouldNotReceive('log');
        $service = new BedManagementService($audit);

        try {
            $service->releaseBed($requestedAdmission, $bed->id);
            $this->fail('Expected release to reject an admission without a matching active assignment.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('admission_id', $exception->errors());
        }

        $this->assertSame(Bed::STATUS_OCCUPIED, $bed->fresh()->status);
        $this->assertSame(BedAssignment::STATUS_ACTIVE, $assignment->fresh()->status);
        $this->assertNull($assignment->fresh()->released_at);
    }
}
