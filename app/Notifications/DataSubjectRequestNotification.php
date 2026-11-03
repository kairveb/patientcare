<?php

namespace App\Notifications;

use App\Models\DataSubjectRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DataSubjectRequestNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly DataSubjectRequest $dataSubjectRequest)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Privacy request updated',
            'message' => 'Your '.$this->dataSubjectRequest->typeLabel().' request is now '.$this->dataSubjectRequest->status.'.',
            'url' => route('privacy.requests.show', $this->dataSubjectRequest),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('Privacy request status update')
            ->line('Your '.$this->dataSubjectRequest->typeLabel().' request is now '.$this->dataSubjectRequest->status.'.')
            ->line('Sign in to your patient portal to view the response.');
    }
}
