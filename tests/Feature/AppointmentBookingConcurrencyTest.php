<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\Patient;
use App\Models\Provider;
use App\Services\AuditLogService;
use App\Services\AppointmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class AppointmentBookingConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_overlapping_provider_bookings_cannot_both_succeed(): void
    {
        $provider = Provider::factory()->create();
        $firstPatient = Patient::factory()->create();
        $secondPatient = Patient::factory()->create();
        $firstStart = now()->addDays(2)->setTime(9, 0);
        $overlappingStart = $firstStart->copy()->addMinutes(15);
        $firstSlot = $this->slot($provider, $firstStart, 30);
        $overlappingSlot = $this->slot($provider, $overlappingStart, 30);
        $service = app(AppointmentService::class);

        $service->book($this->bookingData($firstPatient, $provider, $firstSlot), null);

        try {
            $service->book($this->bookingData($secondPatient, $provider, $overlappingSlot), null);
            $this->fail('An overlapping appointment should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('starts_at', $exception->errors());
        }

        $this->assertSame(1, Appointment::query()->count());
        $this->assertSame(AppointmentSlot::STATUS_AVAILABLE, $overlappingSlot->fresh()->status);
    }

    public function test_cancelled_appointment_slot_can_be_booked_again(): void
    {
        $provider = Provider::factory()->create();
        $firstPatient = Patient::factory()->create();
        $secondPatient = Patient::factory()->create();
        $startsAt = now()->addDays(3)->setTime(11, 0);
        $slot = $this->slot($provider, $startsAt, 30);
        $service = app(AppointmentService::class);

        $appointment = $service->book($this->bookingData($firstPatient, $provider, $slot), null);
        $service->cancel($appointment, null, 'Patient requested cancellation');

        $this->assertNull($appointment->fresh()->active_slot_key);
        $this->assertSame(AppointmentSlot::STATUS_AVAILABLE, $slot->fresh()->status);

        $rebooked = $service->book($this->bookingData($secondPatient, $provider, $slot), null);

        $this->assertNotSame($appointment->id, $rebooked->id);
        $this->assertSame(AppointmentSlot::STATUS_BOOKED, $slot->fresh()->status);
        $this->assertSame(2, Appointment::query()->count());
    }

    public function test_no_show_releases_its_appointment_slot(): void
    {
        $provider = Provider::factory()->create();
        $patient = Patient::factory()->create();
        $startsAt = now()->addDays(3)->setTime(12, 0);
        $slot = $this->slot($provider, $startsAt, 30);
        $service = app(AppointmentService::class);

        $appointment = $service->book($this->bookingData($patient, $provider, $slot), null);
        $appointment->update(['status' => Appointment::STATUS_CONFIRMED]);
        $service->markNoShow($appointment);

        $this->assertNull($appointment->fresh()->active_slot_key);
        $this->assertSame(AppointmentSlot::STATUS_AVAILABLE, $slot->fresh()->status);
    }

    public function test_slot_for_another_provider_is_rejected_without_claiming_it(): void
    {
        $provider = Provider::factory()->create();
        $otherProvider = Provider::factory()->create();
        $patient = Patient::factory()->create();
        $startsAt = now()->addDays(4)->setTime(13, 0);
        $slot = $this->slot($otherProvider, $startsAt, 30);

        try {
            app(AppointmentService::class)->book([
                'patient_id' => $patient->id,
                'provider_id' => $provider->id,
                'starts_at' => $startsAt->toDateTimeString(),
                'appointment_slot_id' => $slot->id,
            ]);
            $this->fail('A slot belonging to a different provider should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('appointment_slot_id', $exception->errors());
        }

        $this->assertSame(AppointmentSlot::STATUS_AVAILABLE, $slot->fresh()->status);
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_slot_with_a_different_start_time_is_rejected_without_claiming_it(): void
    {
        $provider = Provider::factory()->create();
        $patient = Patient::factory()->create();
        $slotStart = now()->addDays(4)->setTime(13, 0);
        $requestedStart = $slotStart->copy()->addMinutes(30);
        $slot = $this->slot($provider, $slotStart, 30);

        try {
            app(AppointmentService::class)->book([
                'patient_id' => $patient->id,
                'provider_id' => $provider->id,
                'starts_at' => $requestedStart->toDateTimeString(),
                'appointment_slot_id' => $slot->id,
            ]);
            $this->fail('A slot with a different start time should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('appointment_slot_id', $exception->errors());
        }

        $this->assertSame(AppointmentSlot::STATUS_AVAILABLE, $slot->fresh()->status);
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_failed_cancellation_rolls_back_appointment_and_slot_changes(): void
    {
        $provider = Provider::factory()->create();
        $patient = Patient::factory()->create();
        $startsAt = now()->addDays(5)->setTime(15, 0);
        $slot = $this->slot($provider, $startsAt, 30, AppointmentSlot::STATUS_BOOKED);
        $appointment = Appointment::factory()->create([
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes(30),
            'status' => Appointment::STATUS_CONFIRMED,
        ]);
        $audit = Mockery::mock(AuditLogService::class);
        $audit->shouldReceive('cancelAppointment')
            ->once()
            ->andThrow(new RuntimeException('Audit write failed.'));
        $this->app->instance(AuditLogService::class, $audit);

        try {
            app(AppointmentService::class)->cancel($appointment);
            $this->fail('A failed cancellation should roll back all database changes.');
        } catch (RuntimeException) {
            $this->assertSame(Appointment::STATUS_CONFIRMED, $appointment->fresh()->status);
            $this->assertSame(AppointmentSlot::STATUS_BOOKED, $slot->fresh()->status);
        }
    }

    private function slot(
        Provider $provider,
        Carbon $startsAt,
        int $duration,
        string $status = AppointmentSlot::STATUS_AVAILABLE
    ): AppointmentSlot {
        return AppointmentSlot::create([
            'provider_id' => $provider->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes($duration),
            'status' => $status,
        ]);
    }

    private function bookingData(Patient $patient, Provider $provider, AppointmentSlot $slot): array
    {
        return [
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
            'starts_at' => $slot->starts_at->toDateTimeString(),
            'appointment_slot_id' => $slot->id,
        ];
    }
}
