<?php

namespace App\Services;

use App\Models\Appointment;
use App\Notifications\AppointmentReminderNotification;
use Throwable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

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
                ->whereHas('patient', fn ($query) => $query
                    ->whereNotNull('email')
                    ->where('email', '<>', ''))
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
        \Illuminate\Support\Carbon $windowStart,
        \Illuminate\Support\Carbon $dueAt
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
                || ! $appointment->starts_at->greaterThan($windowStart)
                || $appointment->starts_at->greaterThan($dueAt)) {
                return false;
            }

            try {
                Notification::route('mail', $appointment->patient->email)
                    ->notify(new AppointmentReminderNotification($appointment, $hoursBefore));
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
}
