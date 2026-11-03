<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AppointmentReassignmentNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly array $details)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        return $this->details;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Update about your HIMS appointment')
            ->greeting('Appointment update')
            ->line($this->details['message'])
            ->action('Review appointment options', $this->details['url'])
            ->line('Please contact the hospital registration desk if you need help.');
    }
}
