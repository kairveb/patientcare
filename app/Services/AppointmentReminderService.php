<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\DemoRecord;
use App\Models\Patient;
use App\Notifications\AppointmentReminderNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

class AppointmentReminderService
{
    private const REMINDER_TIMES = [
        24 => 'reminder_24h_sent_at',
        1 => 'reminder_1h_sent_at',
    ];

    public function queueDueReminders(): int
    {
        $queued = 0;

        foreach (self::REMINDER_TIMES as $hoursBefore => $sentAtColumn) {
            $dueAt = now()->addHours($hoursBefore);
            $windowStart = $dueAt->copy()->subMinute();

            Appointment::query()
                ->where('status', Appointment::STATUS_CONFIRMED)
                ->whereNull($sentAtColumn)
                ->where('starts_at', '>', $windowStart)
                ->where('starts_at', '<=', $dueAt)
                ->whereNotExists(fn ($query) => $query
                    ->selectRaw('1')
                    ->from('demo_records')
                    ->whereColumn('demo_records.model_id', 'appointments.id')
                    ->where('demo_records.model_type', (new Appointment)->getMorphClass()))
                ->whereHas('patient', fn ($query) => $query
                    ->whereNotNull('email')
                    ->where('email', '<>', '')
                    ->whereRaw('LOWER(email) NOT LIKE ?', ['%@example.com'])
                    ->whereNotExists(fn ($demoPatients) => $demoPatients
                        ->selectRaw('1')
                        ->from('demo_records')
                        ->whereColumn('demo_records.model_id', 'patients.id')
                        ->where('demo_records.model_type', (new Patient)->getMorphClass())))
                ->with(['patient', 'provider', 'appointmentType'])
                ->chunkById(100, function ($appointments) use ($hoursBefore, $sentAtColumn, $windowStart, $dueAt, &$queued): void {
                    foreach ($appointments as $candidate) {
                        if ($this->queueReminder(
                            $candidate->id,
                            $hoursBefore,
                            $sentAtColumn,
                            $windowStart,
                            $dueAt
                        )) {
                            $queued++;
                        }
                    }
                });
        }

        return $queued;
    }

    private function queueReminder(
        int $appointmentId,
        int $hoursBefore,
        string $sentAtColumn,
        Carbon $windowStart,
        Carbon $dueAt
    ): bool {
        return DB::transaction(function () use ($appointmentId, $hoursBefore, $sentAtColumn, $windowStart, $dueAt): bool {
            $appointment = Appointment::query()
                ->with(['patient', 'provider', 'appointmentType'])
                ->lockForUpdate()
                ->find($appointmentId);

            if (! $appointment
                || $appointment->status !== Appointment::STATUS_CONFIRMED
                || $appointment->{$sentAtColumn} !== null
                || blank($appointment->patient?->email)
                || $this->isDemoReminderTarget($appointment)
                || ! $appointment->starts_at->greaterThan($windowStart)
                || $appointment->starts_at->greaterThan($dueAt)) {
                return false;
            }

            try {
                $notification = new AppointmentReminderNotification($appointment, $hoursBefore);
                Notification::route('mail', $appointment->patient->email)->notify($notification);
                if ($appointment->patient->user) {
                    Notification::sendNow($appointment->patient->user, $notification, ['database']);
                }
            } catch (Throwable $exception) {
                app(MailFailureLogger::class)->recordFailure(
                    'appointment_reminder',
                    $appointment->patient->email,
                    $exception
                );

                return false;
            }

            $appointment->forceFill([$sentAtColumn => now()])->save();

            return true;
        });
    }

    private function isDemoReminderTarget(Appointment $appointment): bool
    {
        $patient = $appointment->patient;
        $email = strtolower(trim((string) $patient?->email));

        return DemoRecord::isMarked($appointment)
            || ($patient !== null && DemoRecord::isMarked($patient))
            || str_ends_with($email, '@example.com');
    }
}
