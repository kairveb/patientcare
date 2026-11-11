<?php

namespace App\Events;

use App\Support\NotificationMetadata;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class EmergencyQueueAlert implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $message,
        public readonly ?string $triageLevel = null,
        public readonly string $priority = 'High',
    ) {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('er-alerts')];
    }

    public function broadcastAs(): string
    {
        return 'emergency.queue.alert';
    }

    public function broadcastWith(): array
    {
        return [
            'message' => $this->message,
            'triage_level' => $this->triageLevel,
            'occurred_at' => now()->toIso8601String(),
        ] + NotificationMetadata::make($this->priority, 'EERTS');
    }
}
