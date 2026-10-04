<?php

namespace App\Policies;

use App\Models\Encounter;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class EncounterPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin()
            || ($user->hasRole('doctor', 'nurse') && $user->hasPermission('view-encounters'))
            || ($user->isPatient() && $user->hasPermission('view-own-medical-history') && $user->patient()->exists());
    }

    public function view(User $user, Encounter $encounter): bool
    {
        return $this->viewAny($user)
            && $this->scopeQuery($user, Encounter::query())
                ->whereKey($encounter->getKey())
                ->exists();
    }

    public function scopeQuery(User $user, Builder $query): Builder
    {
        if ($user->isSuperAdmin()) {
            return $query;
        }

        if ($user->isPatient()) {
            return $query->where('patient_id', $user->patient?->id ?? 0);
        }

        if ($user->hasRole('doctor')) {
            return $query->where('provider_id', $user->provider?->id ?? 0);
        }

        if ($user->hasRole('nurse')) {
            return $query->where('type', Encounter::TYPE_EMERGENCY);
        }

        return $query->whereRaw('0 = 1');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('doctor')
            && $user->hasPermission('create-encounters')
            && $user->provider !== null;
    }

    public function update(User $user, Encounter $encounter): bool
    {
        if (! $user->hasPermission('update-encounters')) {
            return false;
        }

        if ($user->hasRole('super-admin')) {
            return true;
        }

        return $user->hasRole('doctor')
            && $user->provider?->id === $encounter->provider_id;
    }

    public function complete(User $user, Encounter $encounter): bool
    {
        return $user->hasRole('doctor')
            && $user->hasPermission('update-encounters')
            && $user->provider?->id === $encounter->provider_id;
    }

    public function delete(User $user, Encounter $encounter): bool
    {
        return $user->hasPermission('delete-patients');
    }
}
