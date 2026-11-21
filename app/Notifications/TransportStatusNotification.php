<?php

namespace App\Notifications;

use App\Support\NotificationMetadata;
use Illuminate\Notifications\Notification;

class TransportStatusNotification extends Notification
{
    public function __construct(
        private readonly string $status,
        private readonly bool $emergency = false,
        private readonly string $module = 'EERTS'
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Transport dispatch update',
            'message' => 'A patient transport request is now '.$this->status.'.',
            'url' => route('notifications.index'),
        ] + NotificationMetadata::make($this->emergency ? 'Critical' : 'High', $this->module);
    }
}
