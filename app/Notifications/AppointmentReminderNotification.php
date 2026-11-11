<?php

namespace App\Notifications;

use App\Models\Appointment;
use App\Support\NotificationMetadata;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;
use Throwable;

class AppointmentReminderNotification extends Notification implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Appointment $appointment,
        public int $hoursBefore
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $appointment = $this->appointment->loadMissing(['patient', 'provider', 'appointmentType']);
        $leadTime = $this->hoursBefore === 24 ? '24-hour' : '1-hour';
        $consultationType = $appointment->appointmentType?->name ?? 'consultation';

        return (new MailMessage())
            ->subject("{$leadTime} appointment reminder")
            ->greeting('Hello '.$appointment->patient->first_name)
            ->line('This is a reminder for your '.$consultationType.' appointment.')
            ->line('Scheduled for '.$appointment->starts_at->format('M j, Y \a\t g:i A').'.')
            ->line('Provider: '.$appointment->provider->display_name.'.')
            ->line('Appointment number: '.$appointment->appointment_number.'.')
            ->line('Please contact the hospital if you need to make a change.');
    }

    public function toDatabase(object $notifiable): array
    {
        $appointment = $this->appointment->loadMissing(['patient', 'provider', 'appointmentType']);
        $leadTime = $this->hoursBefore === 24 ? '24-hour' : '1-hour';

        return [
            'title' => ucfirst($leadTime).' appointment reminder',
            'message' => 'Your '.$appointment->appointmentType?->name.' appointment with '.$appointment->provider?->display_name
                .' is scheduled for '.$appointment->starts_at->format('M j, Y g:i A').'.',
            'appointment_id' => $appointment->id,
            'url' => route('appointments.show', $appointment),
        ] + NotificationMetadata::make($this->hoursBefore === 1 ? 'High' : 'Normal', 'ASS');
    }

    public function failed(Throwable $exception): void
    {
        $email = $this->appointment->patient?->email;
        app(\App\Services\MailFailureLogger::class)->recordFailure('appointment_reminder', $email, $exception);
    }
}
