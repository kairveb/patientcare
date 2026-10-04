<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\DemoRecord;
use App\Models\Patient;
use App\Notifications\AppointmentReminderNotification;
use App\Services\AppointmentService;
use Database\Seeders\HimsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class AppointmentReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(HimsSeeder::class);
    }

    public function test_scheduler_command_queues_24_hour_and_1_hour_reminders_once(): void
    {
        $now = now()->startOfMinute();
        $this->travelTo($now);
        $patient = Patient::factory()->create(['email' => 'reminder@example.test']);
        $oneDayAppointment = $this->confirmedAppointment($patient, $now->copy()->addHours(24));
        $oneHourAppointment = $this->confirmedAppointment($patient, $now->copy()->addHour());
        Queue::fake();

        $this->artisan('appointments:send-reminders')->assertSuccessful();

        Queue::assertPushed(SendQueuedNotifications::class, 2);
        Queue::assertPushed(SendQueuedNotifications::class, fn (SendQueuedNotifications $job): bool => $job->notification instanceof AppointmentReminderNotification
            && $job->notification->appointment->is($oneDayAppointment)
            && $job->notification->hoursBefore === 24
        );
        Queue::assertPushed(SendQueuedNotifications::class, fn (SendQueuedNotifications $job): bool => $job->notification instanceof AppointmentReminderNotification
            && $job->notification->appointment->is($oneHourAppointment)
            && $job->notification->hoursBefore === 1
        );
        $this->assertNotNull($oneDayAppointment->fresh()->reminder_24h_sent_at);
        $this->assertNotNull($oneHourAppointment->fresh()->reminder_1h_sent_at);

        $this->artisan('appointments:send-reminders')->assertSuccessful();
        Queue::assertPushed(SendQueuedNotifications::class, 2);
    }

    public function test_cancelled_appointments_and_patients_without_email_are_not_queued_or_marked(): void
    {
        $now = now()->startOfMinute();
        $this->travelTo($now);
        $patientWithoutEmail = Patient::factory()->create(['email' => null]);
        $appointmentWithoutEmail = $this->confirmedAppointment(
            $patientWithoutEmail,
            $now->copy()->addHours(24)
        );
        $cancelledAppointment = $this->confirmedAppointment(
            Patient::factory()->create(['email' => 'cancelled@example.test']),
            $now->copy()->addHour(),
            Appointment::STATUS_CANCELLED
        );
        Queue::fake();

        $this->artisan('appointments:send-reminders')->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertNull($appointmentWithoutEmail->fresh()->reminder_24h_sent_at);
        $this->assertNull($cancelledAppointment->fresh()->reminder_1h_sent_at);
    }

    public function test_demo_marked_appointments_and_example_com_patient_emails_are_not_queued(): void
    {
        $now = now()->startOfMinute();
        $this->travelTo($now);
        $markedAppointment = $this->confirmedAppointment(
            Patient::factory()->create(['email' => 'synthetic@invalid.test']),
            $now->copy()->addHours(24)
        );
        DemoRecord::mark($markedAppointment, 'test:marked-appointment');

        $exampleDomainAppointment = $this->confirmedAppointment(
            Patient::factory()->create(['email' => 'synthetic@example.com']),
            $now->copy()->addHour()
        );
        Queue::fake();

        $this->artisan('appointments:send-reminders')->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertNull($markedAppointment->fresh()->reminder_24h_sent_at);
        $this->assertNull($exampleDomainAppointment->fresh()->reminder_1h_sent_at);
    }

    public function test_rescheduling_resets_both_reminder_guards(): void
    {
        $appointment = Appointment::factory()->create([
            'reminder_24h_sent_at' => now(),
            'reminder_1h_sent_at' => now(),
        ]);

        app(AppointmentService::class)->reschedule($appointment, [
            'starts_at' => now()->addDays(2)->setTime(10, 0)->toDateTimeString(),
        ]);

        $this->assertNull($appointment->fresh()->reminder_24h_sent_at);
        $this->assertNull($appointment->fresh()->reminder_1h_sent_at);
    }

    private function confirmedAppointment(
        Patient $patient,
        Carbon $startsAt,
        string $status = Appointment::STATUS_CONFIRMED
    ): Appointment {
        return Appointment::factory()->create([
            'appointment_number' => 'APT-REM-'.Str::upper(Str::random(8)),
            'patient_id' => $patient->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes(30),
            'status' => $status,
        ]);
    }
}
