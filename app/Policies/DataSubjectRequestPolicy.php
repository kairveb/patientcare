<?php

namespace App\Policies;

use App\Models\DataSubjectRequest;
use App\Models\User;

class DataSubjectRequestPolicy
{
    private const STAFF_ROLES = ['registration', 'admin', 'super-admin'];

    public function viewAny(User $user): bool
    {
        return $user->isPatient()
            ? $user->patient()->exists()
            : $user->hasAnyRole(self::STAFF_ROLES);
    }

    public function create(User $user): bool
    {
        return $user->isPatient() && $user->patient()->exists();
    }

    public function view(User $user, DataSubjectRequest $dataSubjectRequest): bool
    {
        if ($user->isPatient()) {
            return $user->patient()->whereKey($dataSubjectRequest->patient_id)->exists();
        }

        return $user->hasAnyRole(self::STAFF_ROLES);
    }

    public function review(User $user, DataSubjectRequest $dataSubjectRequest): bool
    {
        return $user->hasAnyRole(self::STAFF_ROLES)
            && in_array($dataSubjectRequest->status, [
                DataSubjectRequest::STATUS_SUBMITTED,
                DataSubjectRequest::STATUS_IN_REVIEW,
            ], true);
    }
}
