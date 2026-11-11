<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Bed;
use App\Models\Room;
use App\Models\Ward;
use Database\Seeders\DemoWardsRoomsBedsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoWardsRoomsBedsSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_ward_room_bed_seeder_is_idempotent_and_preserves_existing_wards(): void
    {
        $existingWard = Ward::query()->create([
            'code' => 'TEST-1111',
            'name' => 'test',
            'type' => 'Demo',
            'active' => true,
        ]);

        $this->seed(DemoWardsRoomsBedsSeeder::class);
        $this->seed(DemoWardsRoomsBedsSeeder::class);

        $this->assertSame(5, Ward::query()->count());
        $this->assertSame(12, Room::query()->count());
        $this->assertSame(36, Bed::query()->count());
        $this->assertDatabaseHas('wards', ['id' => $existingWard->id, 'name' => 'test']);
        $this->assertSame(0, Admission::query()->count());

        foreach ([
            Bed::STATUS_AVAILABLE,
            Bed::STATUS_OCCUPIED,
            Bed::STATUS_RESERVED,
            Bed::STATUS_CLEANING,
            Bed::STATUS_MAINTENANCE,
            Bed::STATUS_BLOCKED,
        ] as $status) {
            $this->assertGreaterThan(0, Bed::query()->where('status', $status)->count());
        }

        $this->assertGreaterThan(
            Bed::query()->where('status', '!=', Bed::STATUS_AVAILABLE)->count(),
            Bed::query()->where('status', Bed::STATUS_AVAILABLE)->count()
        );
    }
}
