<?php

namespace App\Policies;

use App\Models\CareRequest;
use App\Models\Encounter;
use App\Models\User;

class CareRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('view-care-requests')
            && $user->hasRole('doctor', 'nurse', 'registration', 'super-admin');
    }

    public function view(User $user, CareRequest $careRequest): bool
    {
        if (! $user->hasPermission('view-care-requests')) {
            return false;
        }

        if ($user->hasRole('patient')) {
            return $user->patient?->id === $careRequest->patient_id;
        }

        if ($user->hasRole('doctor')) {
            return $user->provider?->id === $careRequest->encounter?->provider_id;
        }

        return $user->hasRole('nurse', 'registration', 'super-admin');
    }

    public function create(User $user, Encounter $encounter): bool
    {
        return $user->hasRole('doctor')
            && $user->hasPermission('create-care-requests')
            && $user->provider?->id === $encounter->provider_id
            && $encounter->type === Encounter::TYPE_OUTPATIENT;
    }

    public function decide(User $user, CareRequest $careRequest): bool
    {
        return $user->hasPermission('approve-care-requests')
            && $user->hasRole('registration', 'super-admin');
    }

    public function cancel(User $user, CareRequest $careRequest): bool
    {
        return $user->hasRole('doctor')
            && $user->hasPermission('create-care-requests')
            && $careRequest->requested_by === $user->id
            && $user->provider?->id === $careRequest->encounter?->provider_id;
    }

    public function attachResult(User $user, CareRequest $careRequest): bool
    {
        return $user->hasRole('doctor')
            && $user->hasPermission('view-care-requests')
            && $careRequest->requested_by === $user->id
            && $user->provider?->id === $careRequest->encounter?->provider_id;
    }

    public function viewResult(User $user, CareRequest $careRequest): bool
    {
        return $this->view($user, $careRequest)
            && $careRequest->status === CareRequest::STATUS_APPROVED
            && $careRequest->result_text !== null;
    }

    public function viewSlip(User $user, CareRequest $careRequest): bool
    {
        return $this->view($user, $careRequest)
            && $careRequest->status === CareRequest::STATUS_APPROVED;
    }
}
