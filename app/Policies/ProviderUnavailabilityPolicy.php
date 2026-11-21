<?php

namespace App\Policies;

use App\Models\User;

class ProviderUnavailabilityPolicy
{
    public function create(User $user, int $providerId): bool
    {
        if ($user->hasRole('doctor')) {
            return (int) $user->provider?->id === $providerId;
        }

        return $user->hasAnyRole(['admin', 'registration', 'super-admin'])
            && ($user->isSuperAdmin()
                || $user->hasRole('registration')
                || $user->hasPermission('manage-provider-schedules'));
    }
}
