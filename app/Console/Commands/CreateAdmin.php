<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CreateAdmin extends Command
{
    protected $signature = 'app:create-admin';

    protected $description = 'Create or update the initial Super Admin account';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Run this command interactively so credentials can be entered securely.');

            return self::FAILURE;
        }

        $superAdminRole = Role::query()->where('name', 'super-admin')->first();

        if (! $superAdminRole) {
            $this->error('The Super Admin role is missing. Run the ReferenceDataSeeder before creating an admin.');

            return self::FAILURE;
        }

        $existingAdmin = User::query()
            ->whereHas('roles', fn ($query) => $query->where('name', 'super-admin'))
            ->orderBy('id')
            ->first();

        $name = trim((string) $this->ask('Name'));
        $email = Str::lower(trim((string) $this->ask('Email')));
        $password = (string) $this->secret('Password (minimum 12 characters)');
        $target = $existingAdmin ?? User::query()->where('email', $email)->first();

        $validation = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($target?->id),
            ],
            'password' => ['required', 'string', 'min:12', 'max:100'],
        ]);

        if ($validation->fails()) {
            foreach ($validation->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $action = $target ? "update user #{$target->id}" : 'create a new user';
        if (
            app()->environment('production')
            && ! $this->confirm("This will {$action} and grant the Super Admin role. Continue?", false)
        ) {
            $this->warn('No account changes were made.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($target, $superAdminRole, $name, $email, $password): void {
            $user = $target
                ? User::query()->lockForUpdate()->findOrFail($target->id)
                : new User;

            $user->forceFill([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
                'email_verified_at' => now(),
                'last_activity_at' => now(),
                'is_active' => true,
            ])->save();

            $user->roles()->sync([$superAdminRole->id]);
        });

        $this->info("Super Admin account ready for {$email}.");

        return self::SUCCESS;
    }
}
