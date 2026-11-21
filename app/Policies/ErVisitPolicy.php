<?php

namespace App\Policies;

use App\Models\ErVisit;
use App\Models\User;

class ErVisitPolicy
{
    public function create(User $user): bool
    {
        return $user->hasAnyRole(['nurse', 'registration', 'super-admin'])
            && $user->hasPermission('create-er-visits');
    }

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['super-admin', 'nurse', 'doctor']);
    }

    public function view(User $user, ErVisit $visit): bool
    {
        return ($user->hasRole('registration') && $user->hasPermission('view-er'))
            || $this->viewAny($user);
    }

    public function triage(User $user, ErVisit $visit): bool
    {
        // Clinical priority must remain under qualified healthcare staff (nurse/doctor)
        return $user->hasAnyRole(['super-admin', 'nurse', 'doctor']);
    }

    public function update(User $user, ErVisit $visit): bool
    {
        return $user->hasAnyRole(['super-admin', 'nurse']);
    }
}
