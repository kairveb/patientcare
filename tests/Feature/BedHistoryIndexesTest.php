<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Bed;
use App\Models\BedAssignment;
use App\Models\BedReservation;
use App\Models\Patient;
use App\Models\Room;
use App\Models\Ward;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BedHistoryIndexesTest extends TestCase
{
    use RefreshDatabase;

    public function test_bed_history_allows_multiple_released_assignments_and_cancelled_reservations(): void
    {
        $patient = Patient::create([
            'mrn' => 'MRN-HISTORY-000001',
            'first_name' => 'History',
            'last_name' => 'Patient',
            'date_of_birth' => '1990-01-01',
            'sex' => 'Female',
        ]);
        $ward = Ward::create([
            'code' => 'WARD-HISTORY',
            'name' => 'History Ward',
            'type' => 'GENERAL',
            'active' => true,
        ]);
        $room = Room::create([
            'ward_id' => $ward->id,
            'number' => 'ROOM-HISTORY',
            'type' => 'STANDARD',
        ]);
        $bed = Bed::create([
            'room_id' => $room->id,
            'number' => 'BED-HISTORY',
            'status' => Bed::STATUS_AVAILABLE,
        ]);

        $admissions = collect([1, 2])->map(fn (int $sequence) => Admission::create([
            'admission_number' => sprintf('ADM-HISTORY-%06d', $sequence),
            'patient_id' => $patient->id,
            'status' => Admission::STATUS_REQUESTED,
        ]));

        foreach ($admissions as $admission) {
            BedAssignment::create([
                'admission_id' => $admission->id,
                'bed_id' => $bed->id,
                'assigned_at' => now(),
                'released_at' => now(),
                'status' => BedAssignment::STATUS_RELEASED,
            ]);

            BedReservation::create([
                'admission_id' => $admission->id,
                'bed_id' => $bed->id,
                'status' => BedReservation::STATUS_CANCELLED,
            ]);
        }

        $this->assertSame(2, BedAssignment::where('bed_id', $bed->id)
            ->where('status', BedAssignment::STATUS_RELEASED)
            ->count());
        $this->assertSame(2, BedReservation::where('bed_id', $bed->id)
            ->where('status', BedReservation::STATUS_CANCELLED)
            ->count());
    }
}
