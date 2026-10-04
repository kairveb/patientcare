<?php

namespace App\Events;

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
        public readonly ?string $priority = null,
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
            'priority' => $this->priority,
            'occurred_at' => now()->toIso8601String(),
        ];
    }
}
