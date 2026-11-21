<?php

namespace App\Policies;

use App\Models\IntegrationOutbox;
use App\Models\User;

class IntegrationAdminPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'super-admin'])
            && $user->hasPermission('view-integration-logs');
    }

    public function retry(User $user, IntegrationOutbox $outbox): bool
    {
        return $user->hasRole('super-admin')
            && $user->hasPermission('retry-integration-delivery')
            && in_array($outbox->status, ['FAILED', 'DEAD'], true);
    }
}
