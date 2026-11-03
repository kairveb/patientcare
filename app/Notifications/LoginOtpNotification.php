<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LoginOtpNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $code,
        public readonly int $ttlMinutes,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your HIMS sign-in verification code')
            ->greeting('HIMS sign-in verification')
            ->line('Use this one-time code to finish signing in:')
            ->line($this->code)
            ->line('This code expires in '.$this->ttlMinutes.' minutes and can only be used once.')
            ->line('If you did not try to sign in, please contact your hospital administrator.');
    }
}
