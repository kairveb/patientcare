<?php

namespace App\Policies;

use App\Models\Appointment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class AppointmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('view-appointments')
            && (! $user->isPatient() || $user->patient()->exists());
    }

    public function view(User $user, Appointment $appointment): bool
    {
        if ($user->isPatient()) {
            return $user->patient()->whereKey($appointment->patient_id)->exists();
        }

        if ($user->hasRole('doctor')) {
            return $user->provider?->id === $appointment->provider_id
                && $user->hasPermission('view-appointments');
        }

        if ($user->hasRole('nurse')) {
            return $user->hasPermission('view-appointments')
                && $this->scopeQuery($user, Appointment::query())
                    ->whereKey($appointment->getKey())
                    ->exists();
        }

        if ($user->hasPermission('view-appointments')) {
            return true;
        }

        return false;
    }

    public function scopeQuery(User $user, Builder $query): Builder
    {
        if ($user->isSuperAdmin() || $user->hasRole('registration')
            || ($user->hasRole('admin') && $user->hasPermission('view-appointments'))) {
            return $query;
        }

        if ($user->isPatient()) {
            return $query->where('patient_id', $user->patient?->id ?? 0);
        }

        if ($user->hasRole('doctor')) {
            return $query->where('provider_id', $user->provider?->id ?? 0);
        }

        if ($user->hasRole('nurse')) {
            return $query->whereIn('status', [
                Appointment::STATUS_CHECKED_IN,
                Appointment::STATUS_IN_CONSULTATION,
                Appointment::STATUS_CONFIRMED,
            ])
                ->whereDate('starts_at', today())
                ->where(function (Builder $departmentQuery) use ($user) {
                    $departmentQuery->whereHas('department.users', fn (Builder $userQuery) => $userQuery->whereKey($user->id))
                        ->orWhere(function (Builder $unassignedDepartmentQuery) use ($user) {
                            $unassignedDepartmentQuery->whereNull('department_id')
                                ->whereHas('provider.department.users', fn (Builder $userQuery) => $userQuery->whereKey($user->id));
                        });
                });
        }

        return $query->whereRaw('0 = 1');
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin()
            || ($user->hasRole('admin') && $user->hasPermission('create-appointments'))
            || $user->hasRole('registration')
            || ($user->hasRole('nurse') && $user->hasPermission('view-appointments'))
            || ($user->isPatient() && $user->patient()->exists());
    }

    public function book(User $user): bool
    {
        return $this->create($user);
    }

    public function decide(User $user, Appointment $appointment): bool
    {
        return $appointment->status === Appointment::STATUS_PENDING
            && $user->hasAnyRole(['registration', 'nurse', 'admin', 'super-admin']);
    }

    public function update(User $user, Appointment $appointment): bool
    {
        return $this->manageLifecycle($user, $appointment);
    }

    public function cancel(User $user, Appointment $appointment): bool
    {
        return $this->manageLifecycle($user, $appointment)
            || ($user->isPatient() && $user->patient()->whereKey($appointment->patient_id)->exists());
    }

    public function reschedule(User $user, Appointment $appointment): bool
    {
        return $this->manageLifecycle($user, $appointment)
            || ($user->isPatient() && $user->patient()->whereKey($appointment->patient_id)->exists());
    }

    public function manageLifecycle(User $user, Appointment $appointment): bool
    {
        return $user->isSuperAdmin()
            || $user->hasRole('registration')
            || ($user->hasRole('admin') && $user->hasPermission('cancel-appointments'));
    }

    public function delete(User $user, Appointment $appointment): bool
    {
        return $user->hasPermission('delete-appointments');
    }
}
