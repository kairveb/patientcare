<?php

namespace App\Policies;

use App\Models\Patient;
use App\Models\User;
use App\Models\Waitlist;
use Illuminate\Database\Eloquent\Builder;

class WaitlistPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('view-appointments')
            && ($user->isSuperAdmin() || $user->hasRole('registration', 'patient'));
    }

    public function scopeQuery(User $user, Builder $query): Builder
    {
        if ($user->isPatient()) {
            return $query->where('patient_id', $user->patient?->id ?? 0);
        }

        return $query;
    }

    public function create(User $user, Patient $patient): bool
    {
        if ($user->isSuperAdmin() || $user->hasRole('registration')) {
            return $user->hasPermission('create-appointments');
        }

        return $user->isPatient()
            && $user->hasPermission('create-appointments')
            && $user->patient?->is($patient);
    }

    public function update(User $user, Waitlist $waitlist): bool
    {
        if ($user->isSuperAdmin() || $user->hasRole('registration')) {
            return $user->hasPermission('update-appointments');
        }

        return $user->isPatient()
            && $user->hasPermission('update-appointments')
            && $user->patient?->id === $waitlist->patient_id;
    }
}
