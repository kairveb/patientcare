<?php

namespace App\Policies;

use App\Models\TelehealthSession;
use App\Models\User;

class TelehealthSessionPolicy
{
    public function view(User $user, TelehealthSession $session): bool
    {
        return $this->isAuthorizedParticipant($user, $session);
    }

    public function addParticipant(User $user, TelehealthSession $session): bool
    {
        return $this->isAuthorizedParticipant($user, $session);
    }

    public function join(User $user, TelehealthSession $session): bool
    {
        return $this->isAuthorizedParticipant($user, $session);
    }

    private function isAuthorizedParticipant(User $user, TelehealthSession $session): bool
    {
        if ($user->hasRole('super-admin')) {
            return true;
        }

        $appointment = $session->appointment;
        if (! $appointment) {
            return false;
        }

        return (int) $appointment->patient?->user_id === (int) $user->id
            || (int) $appointment->provider?->user_id === (int) $user->id;
    }
}
