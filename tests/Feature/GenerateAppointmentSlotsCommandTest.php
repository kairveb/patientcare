<?php

namespace Tests\Feature;

use App\Models\AppointmentSlot;
use App\Models\Provider;
use App\Models\ProviderSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerateAppointmentSlotsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_is_idempotent_and_does_not_overwrite_booked_slots(): void
    {
        $user = User::factory()->create();
        $provider = Provider::create([
            'user_id' => $user->id,
            'department_id' => null,
            'display_name' => 'Dr. Demo Slot Test',
            'active' => true,
        ]);
        $firstDate = now()->addDay()->startOfDay();
        ProviderSchedule::create([
            'provider_id' => $provider->id,
            'day_of_week' => $firstDate->dayOfWeek,
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
            'slot_duration' => 30,
            'break_start' => '09:30:00',
            'break_end' => '10:00:00',
        ]);
        $booked = AppointmentSlot::create([
            'provider_id' => $provider->id,
            'starts_at' => $firstDate->copy()->setTime(9, 0),
            'ends_at' => $firstDate->copy()->setTime(9, 30),
            'status' => AppointmentSlot::STATUS_BOOKED,
        ]);

        $this->artisan('slots:generate', ['--days' => 1])
            ->expectsOutputToContain('Created 2 missing slots')
            ->assertSuccessful();

        $this->assertSame(AppointmentSlot::STATUS_BOOKED, $booked->fresh()->status);
        $this->assertDatabaseCount('appointment_slots', 3);
        $this->assertDatabaseMissing('appointment_slots', [
            'provider_id' => $provider->id,
            'starts_at' => $firstDate->copy()->setTime(9, 30)->toDateTimeString(),
        ]);

        $this->artisan('slots:generate', ['--days' => 1])
            ->expectsOutputToContain('Created 0 missing slots')
            ->assertSuccessful();

        $this->assertSame(AppointmentSlot::STATUS_BOOKED, $booked->fresh()->status);
        $this->assertDatabaseCount('appointment_slots', 3);
    }
}
