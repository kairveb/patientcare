<?php

namespace App\Policies;

use App\Models\Provider;
use App\Models\User;

class ProviderPolicy
{
    public function updateProfile(User $user, Provider $provider): bool
    {
        return $user->hasAnyRole(['registration', 'nurse', 'admin', 'super-admin']);
    }
}
