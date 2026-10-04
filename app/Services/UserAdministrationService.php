<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UserAdministrationService
{
    public function __construct(
        protected PatientService $patients,
        protected AuditLogService $audit,
    ) {
    }

    public function create(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            $role = $this->role($data['role']);
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);
            $user->roles()->sync([$role->id]);

            if ($role->name === 'patient') {
                $this->patients->register([
                    'user_id' => $user->id,
                    'first_name' => $data['first_name'],
                    'middle_name' => $data['middle_name'] ?? null,
                    'last_name' => $data['last_name'],
                    'date_of_birth' => $data['date_of_birth'],
                    'sex' => $data['sex'],
                    'email' => $user->email,
                    'lookup_code' => Str::upper(Str::random(8)),
                    'pre_registration_status' => 'not_started',
                    'verified' => false,
                ], $user->id);
            }

            $this->audit->log('ADMIN_CREATE_USER', 'user', $user->id, 'SUCCESS', [
                'role' => $role->name,
            ]);

            return $user;
        });
    }

    public function assignRole(User $user, array $data, User $actor): User
    {
        if ($user->is($actor)) {
            throw ValidationException::withMessages([
                'role' => ['You cannot change your own role.'],
            ]);
        }

        return DB::transaction(function () use ($user, $data, $actor): User {
            $activeAdministrators = $this->lockActiveSuperAdmins();
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $role = $this->role($data['role']);
            $previousRoles = $lockedUser->roles()->pluck('name')->all();

            if (in_array('super-admin', $previousRoles, true) && $lockedUser->is_active && $data['role'] !== 'super-admin') {
                if ($activeAdministrators->count() <= 1) {
                    throw ValidationException::withMessages([
                        'role' => ['At least one active Super Admin must remain.'],
                    ]);
                }
            }

            if ($role->name === 'patient' && ! $lockedUser->patient()->exists()) {
                $this->patients->register([
                    'user_id' => $lockedUser->id,
                    'first_name' => $data['first_name'],
                    'middle_name' => $data['middle_name'] ?? null,
                    'last_name' => $data['last_name'],
                    'date_of_birth' => $data['date_of_birth'],
                    'sex' => $data['sex'],
                    'email' => $lockedUser->email,
                    'lookup_code' => Str::upper(Str::random(8)),
                    'pre_registration_status' => 'not_started',
                    'verified' => false,
                ], $lockedUser->id);
            }

            $lockedUser->roles()->sync([$role->id]);

            $this->audit->log('ADMIN_ASSIGN_USER_ROLE', 'user', $lockedUser->id, 'SUCCESS', [
                'previous_roles' => $previousRoles,
                'role' => $role->name,
            ], $actor->id);

            return $lockedUser->load('roles');
        });
    }

    public function setActive(User $user, bool $active, User $actor): User
    {
        if ($user->is($actor) && ! $active) {
            throw ValidationException::withMessages([
                'is_active' => ['You cannot deactivate your own account.'],
            ]);
        }

        return DB::transaction(function () use ($user, $active, $actor): User {
            $activeAdministrators = $this->lockActiveSuperAdmins();
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $isSuperAdmin = $lockedUser->hasRole('super-admin');

            if (! $active && $lockedUser->is_active && $isSuperAdmin) {
                if ($activeAdministrators->count() <= 1) {
                    throw ValidationException::withMessages([
                        'is_active' => ['At least one active Super Admin must remain.'],
                    ]);
                }
            }

            $lockedUser->forceFill(['is_active' => $active])->save();
            if (! $active) {
                $lockedUser->tokens()->delete();
            }

            $this->audit->log($active ? 'ADMIN_ACTIVATE_USER' : 'ADMIN_DEACTIVATE_USER', 'user', $lockedUser->id, 'SUCCESS', [
                'is_active' => $active,
            ], $actor->id);

            return $lockedUser;
        });
    }

    private function role(string $name): Role
    {
        return Role::query()->whereIn('name', ['super-admin', 'patient', 'nurse', 'doctor', 'registration'])
            ->where('name', $name)
            ->firstOrFail();
    }

    private function lockActiveSuperAdmins()
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->where('name', 'super-admin'))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }
}
