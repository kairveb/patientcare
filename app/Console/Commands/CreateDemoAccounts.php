<?php

namespace App\Console\Commands;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CreateDemoAccounts extends Command
{
    protected $signature = 'hims:demo-accounts';

    protected $description = 'Create or reconcile the five demo role accounts';

    private const ACCOUNTS = [
        [
            'role' => 'super-admin',
            'name' => 'Super Admin',
            'email' => 'super-admin@coor.test',
            'password_env' => 'DEMO_SUPER_ADMIN_PASSWORD',
        ],
        [
            'role' => 'registration',
            'name' => 'Registration Staff',
            'email' => 'registration@coor.test',
            'password_env' => 'DEMO_REGISTRATION_PASSWORD',
        ],
        [
            'role' => 'doctor',
            'name' => 'Dr. Elena Santos',
            'email' => 'doctor@coor.test',
            'password_env' => 'DEMO_DOCTOR_PASSWORD',
        ],
        [
            'role' => 'nurse',
            'name' => 'Nurse Ana Reyes',
            'email' => 'nurse@coor.test',
            'password_env' => 'DEMO_NURSE_PASSWORD',
        ],
        [
            'role' => 'patient',
            'name' => 'Maria Santos',
            'email' => 'patient@coor.test',
            'password_env' => 'DEMO_PATIENT_PASSWORD',
        ],
    ];

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error(
                'Demo account provisioning through this legacy command is disabled in production. Use app:create-demo-staff instead.'
            );

            return self::FAILURE;
        }

        $roles = Role::query()
            ->whereIn('name', array_column(self::ACCOUNTS, 'role'))
            ->get()
            ->keyBy('name');

        $reconciliationPermission = Permission::query()
            ->where('name', 'view-opd-queue')
            ->first();
        $superAdmin = $roles->get('super-admin');

        if (
            $roles->count() !== count(self::ACCOUNTS)
            || ! $reconciliationPermission
            || ! $superAdmin?->permissions()->whereKey($reconciliationPermission->id)->exists()
        ) {
            $this->error(
                'Required roles or reconciled permissions are missing. Run ReferenceDataSeeder and RbacReconciliationSeeder first.'
            );

            return self::FAILURE;
        }

        $accountsToCreate = [];

        foreach (self::ACCOUNTS as $account) {
            if (User::query()->where('email', $account['email'])->exists()) {
                continue;
            }

            $password = Env::get($account['password_env']);
            if (! is_string($password) || $password === '') {
                if (! $this->input->isInteractive()) {
                    $this->error(
                        "Set {$account['password_env']} or run this command interactively to enter the missing account password."
                    );

                    return self::FAILURE;
                }

                $password = $this->secret("Password for {$account['email']}");
            }

            $validation = Validator::make(
                ['password' => $password],
                ['password' => User::PASSWORD_RULE]
            );

            if ($validation->fails()) {
                $this->error(
                    "The password for {$account['email']} does not meet the account password requirements."
                );

                return self::FAILURE;
            }

            $accountsToCreate[$account['email']] = $password;
        }

        DB::transaction(function () use ($roles, $accountsToCreate): void {
            foreach (self::ACCOUNTS as $account) {
                $user = User::query()->where('email', $account['email'])->first();

                if (! $user) {
                    $password = $accountsToCreate[$account['email']];
                    $user = User::create([
                        'name' => $account['name'],
                        'email' => $account['email'],
                        'password' => Hash::make($password),
                    ]);
                    $user->forceFill([
                        'email_verified_at' => now(),
                        'last_activity_at' => now(),
                    ])->save();
                }

                $user->roles()->syncWithoutDetaching([$roles->get($account['role'])->id]);
            }
        });

        $this->info('Demo accounts created or reconciled. Existing passwords were left unchanged.');

        return self::SUCCESS;
    }
}
