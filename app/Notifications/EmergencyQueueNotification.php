<?php

namespace App\Notifications;

use App\Support\NotificationMetadata;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class EmergencyQueueNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $message,
        private readonly ?string $triageLevel = null,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Critical emergency alert',
            'message' => $this->message,
            'triage_level' => $this->triageLevel,
            'url' => route('emergency.index'),
        ] + NotificationMetadata::make('Critical', 'EERTS');
    }
}
