<?php

namespace App\Policies;

use App\Models\TransportRequest;
use App\Models\User;

class TransportRequestPolicy
{
    private const VIEW_ROLES = ['doctor', 'nurse', 'registration', 'super-admin'];

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(self::VIEW_ROLES)
            && ($user->hasPermission('view-er') || $user->hasPermission('view-admissions'));
    }

    public function view(User $user, TransportRequest $request): bool
    {
        return $user->hasAnyRole(self::VIEW_ROLES)
            && $user->hasPermission($request->source_type === 'er_visit' ? 'view-er' : 'view-admissions');
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['nurse', 'registration', 'super-admin'])
            && $user->hasPermission('request-patient-transport');
    }

    public function cancel(User $user, TransportRequest $request): bool
    {
        return $this->create($user) && $this->view($user, $request);
    }
}
