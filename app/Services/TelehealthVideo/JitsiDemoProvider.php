<?php

namespace App\Services\TelehealthVideo;

use App\Contracts\TelehealthVideoProvider;
use App\Models\Appointment;
use App\Models\TelehealthSession;
use App\Models\User;

class JitsiDemoProvider implements TelehealthVideoProvider
{
    public function name(): string
    {
        return 'jitsi_demo';
    }

    public function provision(TelehealthSession $session, Appointment $appointment): TelehealthSession
    {
        $session->forceFill([
            'status' => TelehealthSession::STATUS_SCHEDULED,
            'start_time' => $appointment->starts_at,
            'duration' => max(5, (int) $appointment->starts_at->diffInMinutes($appointment->ends_at)),
        ])->save();

        return $session->fresh();
    }

    public function joinDetails(TelehealthSession $session, User $user): array
    {
        $payload = implode('|', [
            'hims-telehealth-demo',
            $session->id,
            $session->appointment_id,
            $session->created_at?->timestamp,
        ]);
        $roomName = 'HIMS-'.strtoupper(substr(hash_hmac('sha256', $payload, (string) config('app.key')), 0, 28));

        return [
            'provider' => $this->name(),
            'join_url' => null,
            'passcode' => null,
            'room_name' => $roomName,
        ];
    }
}
