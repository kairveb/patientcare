<?php

namespace Database\Seeders;

use App\Models\Bed;
use App\Models\Room;
use App\Models\Ward;
use Illuminate\Database\Seeder;

class DemoWardsRoomsBedsSeeder extends Seeder
{
    private const WARDS = [
        ['code' => 'GEN', 'name' => 'General Ward', 'type' => 'General Medicine', 'room_prefix' => 'GEN', 'room_start' => 101],
        ['code' => 'PED', 'name' => 'Pediatrics Ward', 'type' => 'Pediatrics', 'room_prefix' => 'PED', 'room_start' => 201],
        ['code' => 'ICU', 'name' => 'Intensive Care Unit', 'type' => 'Critical Care', 'room_prefix' => 'ICU', 'room_start' => 301],
        ['code' => 'PRV', 'name' => 'Private Rooms', 'type' => 'Private', 'room_prefix' => 'PRV', 'room_start' => 401],
    ];

    private const NON_AVAILABLE_BEDS = [
        'GEN-101/C' => Bed::STATUS_OCCUPIED,
        'PED-201/C' => Bed::STATUS_RESERVED,
        'ICU-301/B' => Bed::STATUS_CLEANING,
        'ICU-302/C' => Bed::STATUS_MAINTENANCE,
        'PRV-401/C' => Bed::STATUS_BLOCKED,
    ];

    public function run(): void
    {
        foreach (self::WARDS as $wardData) {
            $ward = Ward::firstOrCreate(
                ['code' => $wardData['code']],
                [
                    'name' => $wardData['name'],
                    'type' => $wardData['type'],
                    'active' => true,
                ]
            );

            foreach (range(0, 2) as $roomOffset) {
                $roomNumber = $wardData['room_prefix'].'-'.($wardData['room_start'] + $roomOffset);
                $room = Room::firstOrCreate(
                    ['ward_id' => $ward->id, 'number' => $roomNumber],
                    ['type' => $wardData['type']]
                );

                foreach (['A', 'B', 'C'] as $bedNumber) {
                    $key = $roomNumber.'/'.$bedNumber;
                    $status = self::NON_AVAILABLE_BEDS[$key] ?? Bed::STATUS_AVAILABLE;

                    Bed::firstOrCreate(
                        ['room_id' => $room->id, 'number' => $bedNumber],
                        [
                            'status' => $status,
                            'status_updated_at' => $status === Bed::STATUS_AVAILABLE ? null : now(),
                        ]
                    );
                }
            }
        }
    }
}
