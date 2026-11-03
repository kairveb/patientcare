<?php

namespace App\Policies;

use App\Models\AppointmentReassignment;
use App\Models\User;

class AppointmentReassignmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['registration', 'admin', 'super-admin']);
    }

    public function view(User $user, AppointmentReassignment $reassignment): bool
    {
        if ($user->isPatient()) {
            return $user->patient?->id === $reassignment->appointment?->patient_id;
        }

        if ($user->hasRole('doctor')) {
            return (int) $user->provider?->id === (int) $reassignment->original_provider_id;
        }

        return $this->viewAny($user);
    }

    public function decide(User $user, AppointmentReassignment $reassignment): bool
    {
        return $this->viewAny($user);
    }

    public function respond(User $user, AppointmentReassignment $reassignment): bool
    {
        return $user->isPatient()
            && $user->patient?->id === $reassignment->appointment?->patient_id;
    }
}
