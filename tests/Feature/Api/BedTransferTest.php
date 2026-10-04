<?php

namespace Tests\Feature\Api;

use App\Models\Admission;
use App\Models\AuditLog;
use App\Models\Bed;
use App\Models\BedAssignment;
use App\Models\Patient;
use App\Models\PatientTransfer;
use App\Models\Role;
use App\Models\Room;
use App\Models\User;
use App\Models\Ward;
use App\Services\AuditLogService;
use App\Services\BedManagementService;
use Database\Seeders\HimsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class BedTransferTest extends TestCase
{
    use RefreshDatabase;

    public function test_nurse_can_transfer_admission_and_records_bed_and_audit_history(): void
    {
        $this->seed(HimsSeeder::class);
        [$nurse, $admission, $sourceBed, $destinationBed, $sourceAssignment] = $this->createTransferFixture('nurse');

        $token = $nurse->createToken('api-token')->plainTextToken;
        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/admissions/{$admission->id}/transfer", [
                'admission_id' => $admission->id,
                'destination_bed_id' => $destinationBed->id,
                'reason' => 'Higher level of observation',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.admission_id', $admission->id)
            ->assertJsonPath('data.from_bed_id', $sourceBed->id)
            ->assertJsonPath('data.to_bed_id', $destinationBed->id)
            ->assertJsonPath('data.reason', 'Higher level of observation');

        $this->assertSame(Bed::STATUS_CLEANING, $sourceBed->fresh()->status);
        $this->assertSame(Bed::STATUS_OCCUPIED, $destinationBed->fresh()->status);
        $this->assertSame(BedAssignment::STATUS_RELEASED, $sourceAssignment->fresh()->status);
        $this->assertNotNull($sourceAssignment->fresh()->released_at);

        $newAssignment = BedAssignment::where('admission_id', $admission->id)
            ->where('bed_id', $destinationBed->id)
            ->where('status', BedAssignment::STATUS_ACTIVE)
            ->firstOrFail();
        $this->assertSame($nurse->id, $newAssignment->assigned_by);

        $transfer = PatientTransfer::where('admission_id', $admission->id)->firstOrFail();
        $this->assertSame($sourceBed->id, $transfer->from_bed_id);
        $this->assertSame($destinationBed->id, $transfer->to_bed_id);
        $this->assertSame($nurse->id, $transfer->transferred_by);
        $this->assertNotNull($transfer->transferred_at);
        $this->assertSame('Higher level of observation', $transfer->reason);

        $auditLog = AuditLog::where('action', 'TRANSFER_PATIENT')
            ->where('resource_id', $transfer->id)
            ->firstOrFail();
        $this->assertSame($nurse->id, $auditLog->user_id);
        $this->assertSame([
            'admission_id' => $admission->id,
            'from_bed_id' => $sourceBed->id,
            'to_bed_id' => $destinationBed->id,
            'has_reason' => true,
        ], $auditLog->metadata);
        $this->assertStringNotContainsString('Higher level of observation', json_encode($auditLog->metadata));
    }

    public function test_transfer_rejects_missing_or_unavailable_destination_without_mutation(): void
    {
        $this->seed(HimsSeeder::class);
        [$nurse, $admission, $sourceBed, $destinationBed, $sourceAssignment] = $this->createTransferFixture('nurse');
        $token = $nurse->createToken('api-token')->plainTextToken;

        $missingBedResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/admissions/{$admission->id}/transfer", [
                'destination_bed_id' => 999999,
            ]);
        $missingBedResponse->assertUnprocessable()
            ->assertJsonValidationErrors('destination_bed_id');

        $nullBedResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/admissions/{$admission->id}/transfer", [
                'destination_bed_id' => null,
            ]);
        $nullBedResponse->assertUnprocessable()
            ->assertJsonValidationErrors('destination_bed_id');

        $unavailableBedResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/admissions/{$admission->id}/transfer", [
                'destination_bed_id' => $sourceBed->id,
            ]);
        $unavailableBedResponse->assertUnprocessable()
            ->assertJsonValidationErrors('destination_bed_id');

        $this->assertSame(Bed::STATUS_OCCUPIED, $sourceBed->fresh()->status);
        $this->assertSame(Bed::STATUS_AVAILABLE, $destinationBed->fresh()->status);
        $this->assertSame(BedAssignment::STATUS_ACTIVE, $sourceAssignment->fresh()->status);
        $this->assertDatabaseCount('patient_transfers', 0);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'TRANSFER_PATIENT']);
    }

    public function test_transfer_route_rejects_roles_other_than_nurse_and_super_admin(): void
    {
        $this->seed(HimsSeeder::class);
        [$doctor, $admission, , $destinationBed] = $this->createTransferFixture('doctor');

        $token = $doctor->createToken('api-token')->plainTextToken;
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/admissions/{$admission->id}/transfer", [
                'destination_bed_id' => $destinationBed->id,
            ])
            ->assertForbidden();
    }

    public function test_transfer_rolls_back_all_changes_when_audit_logging_fails(): void
    {
        $this->seed(HimsSeeder::class);
        [, $admission, $sourceBed, $destinationBed, $sourceAssignment] = $this->createTransferFixture('nurse');

        $audit = Mockery::mock(AuditLogService::class);
        $audit->shouldReceive('transferPatient')->once()->andThrow(new RuntimeException('Audit write failed.'));
        $service = new BedManagementService($audit);

        try {
            $service->transfer($admission, $destinationBed->id, 'Rollback test');
            $this->fail('Expected audit failure to abort and roll back the transfer.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit write failed.', $exception->getMessage());
        }

        $this->assertSame(Bed::STATUS_OCCUPIED, $sourceBed->fresh()->status);
        $this->assertSame(Bed::STATUS_AVAILABLE, $destinationBed->fresh()->status);
        $this->assertSame(BedAssignment::STATUS_ACTIVE, $sourceAssignment->fresh()->status);
        $this->assertDatabaseCount('patient_transfers', 0);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'TRANSFER_PATIENT']);
    }

    private function createTransferFixture(string $roleName): array
    {
        $user = User::factory()->create();
        $role = Role::where('name', $roleName)->firstOrFail();
        $user->roles()->attach($role);

        $patient = Patient::create([
            'mrn' => 'MRN-TRANSFER-' . $user->id,
            'first_name' => 'Transfer',
            'last_name' => 'Patient',
            'date_of_birth' => '1990-01-01',
            'sex' => 'Female',
        ]);
        $admission = Admission::create([
            'admission_number' => 'ADM-TRANSFER-' . $user->id,
            'patient_id' => $patient->id,
            'status' => Admission::STATUS_ADMITTED,
            'created_by' => $user->id,
        ]);
        $ward = Ward::create([
            'code' => 'WARD-TRANSFER-' . $user->id,
            'name' => 'Transfer Ward ' . $user->id,
            'type' => 'GENERAL',
            'active' => true,
        ]);
        $room = Room::create([
            'ward_id' => $ward->id,
            'number' => 'ROOM-TRANSFER-' . $user->id,
            'type' => 'STANDARD',
        ]);
        $sourceBed = Bed::create([
            'room_id' => $room->id,
            'number' => 'SOURCE-' . $user->id,
            'status' => Bed::STATUS_OCCUPIED,
        ]);
        $destinationBed = Bed::create([
            'room_id' => $room->id,
            'number' => 'DESTINATION-' . $user->id,
            'status' => Bed::STATUS_AVAILABLE,
        ]);
        $sourceAssignment = BedAssignment::create([
            'admission_id' => $admission->id,
            'bed_id' => $sourceBed->id,
            'assigned_by' => $user->id,
            'assigned_at' => now(),
            'status' => BedAssignment::STATUS_ACTIVE,
        ]);

        return [$user, $admission, $sourceBed, $destinationBed, $sourceAssignment];
    }
}
