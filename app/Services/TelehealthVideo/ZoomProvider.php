<?php

namespace App\Services\TelehealthVideo;

use App\Contracts\TelehealthVideoProvider;
use App\Models\Appointment;
use App\Models\TelehealthSession;
use App\Models\User;
use App\Services\ZoomService;
use RuntimeException;

class ZoomProvider implements TelehealthVideoProvider
{
    public function __construct(private readonly ZoomService $zoom)
    {
    }

    public function name(): string
    {
        return 'zoom';
    }

    public function provision(TelehealthSession $session, Appointment $appointment): TelehealthSession
    {
        if (filled($session->zoom_meeting_id) && filled($session->join_url)) {
            return $session;
        }

        $meeting = $this->zoom->createMeeting([
            'topic' => 'Private Telehealth Consultation',
            'start_time' => $appointment->starts_at->toIso8601String(),
            'duration' => max(5, (int) $appointment->starts_at->diffInMinutes($appointment->ends_at)),
        ]);
        if (! $meeting) {
            throw new RuntimeException('The Zoom meeting could not be created. Please retry or select the demo provider explicitly.');
        }

        $session->forceFill([
            'zoom_meeting_id' => $meeting['meeting_id'],
            'join_url' => $meeting['join_url'],
            'host_start_url' => $meeting['start_url'],
            'meeting_passcode' => $meeting['password'],
            'status' => TelehealthSession::STATUS_SCHEDULED,
            'start_time' => $appointment->starts_at,
            'duration' => max(5, (int) $appointment->starts_at->diffInMinutes($appointment->ends_at)),
        ])->save();

        return $session->fresh();
    }

    public function joinDetails(TelehealthSession $session, User $user): array
    {
        return [
            'provider' => $this->name(),
            'join_url' => $session->join_url,
            'passcode' => $session->meeting_passcode,
            'room_name' => null,
        ];
    }
}
