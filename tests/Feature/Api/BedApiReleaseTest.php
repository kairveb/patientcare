<?php

namespace Tests\Feature\Api;

use App\Models\Admission;
use App\Models\Bed;
use App\Models\BedAssignment;
use App\Models\Patient;
use App\Models\Role;
use App\Models\Room;
use App\Models\User;
use App\Models\Ward;
use Database\Seeders\HimsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BedApiReleaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_release_endpoint_rejects_an_admission_not_actively_assigned_to_the_bed(): void
    {
        $this->seed(HimsSeeder::class);

        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('name', 'super-admin')->firstOrFail()->id);

        $patient = Patient::create([
            'mrn' => 'MRN-API-RELEASE-' . $admin->id,
            'first_name' => 'Release',
            'last_name' => 'Patient',
            'date_of_birth' => '1990-01-01',
            'sex' => 'Female',
        ]);
        $requestedAdmission = Admission::create([
            'admission_number' => 'ADM-API-RELEASE-' . $admin->id,
            'patient_id' => $patient->id,
            'status' => Admission::STATUS_REQUESTED,
            'created_by' => $admin->id,
        ]);
        $assignedAdmission = Admission::create([
            'admission_number' => 'ADM-API-ASSIGNED-' . $admin->id,
            'patient_id' => $patient->id,
            'status' => Admission::STATUS_REQUESTED,
            'created_by' => $admin->id,
        ]);
        $ward = Ward::create([
            'code' => 'WARD-API-RELEASE-' . $admin->id,
            'name' => 'API Release Ward',
            'type' => 'GENERAL',
            'active' => true,
        ]);
        $room = Room::create([
            'ward_id' => $ward->id,
            'number' => 'ROOM-API-RELEASE-' . $admin->id,
            'type' => 'STANDARD',
        ]);
        $bed = Bed::create([
            'room_id' => $room->id,
            'number' => 'BED-API-RELEASE-' . $admin->id,
            'status' => Bed::STATUS_OCCUPIED,
        ]);
        $assignment = BedAssignment::create([
            'admission_id' => $assignedAdmission->id,
            'bed_id' => $bed->id,
            'assigned_at' => now(),
            'status' => BedAssignment::STATUS_ACTIVE,
        ]);

        $token = $admin->createToken('api-token')->plainTextToken;
        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/beds/{$bed->id}/release", [
                'admission_id' => $requestedAdmission->id,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('admission_id');
        $this->assertSame(Bed::STATUS_OCCUPIED, $bed->fresh()->status);
        $this->assertSame(BedAssignment::STATUS_ACTIVE, $assignment->fresh()->status);
        $this->assertNull($assignment->fresh()->released_at);
    }
}
