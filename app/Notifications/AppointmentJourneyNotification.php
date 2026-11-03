<?php

namespace App\Notifications;

use App\Models\Appointment;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AppointmentJourneyNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $subject,
        private readonly string $message,
        private readonly ?Appointment $appointment = null,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => $this->subject,
            'message' => $this->message,
            'appointment_id' => $this->appointment?->id,
            'url' => $this->appointment ? route('appointments.show', $this->appointment) : route('patients.portal.appointments'),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage())->subject($this->subject)->line($this->message);

        if ($this->appointment) {
            $mail->line('Appointment: '.$this->appointment->appointment_number)
                ->action('View appointment', route('appointments.show', $this->appointment));
        }

        return $mail;
    }
}
