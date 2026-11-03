<?php

namespace App\Notifications;

use App\Models\CareRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class CareRequestDecisionNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly CareRequest $careRequest)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'care_request_decision',
            'care_request_id' => $this->careRequest->id,
            'status' => $this->careRequest->status,
            'approval_reference' => $this->careRequest->approval_reference,
            'title' => 'Outpatient request '.$this->careRequest->status,
            'message' => 'Your '.$this->careRequest->type.' request was '.$this->careRequest->status.'.',
            'url' => route('care-requests.show', $this->careRequest),
        ];
    }
}
