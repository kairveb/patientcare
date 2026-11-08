<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class BootstrapAdmin extends Command
{
    protected $signature = 'app:bootstrap-admin';

    protected $description = 'Create the initial Super Admin account from environment variables';

    public function handle(): int
    {
        $result = DB::transaction(function (): array {
            $role = Role::query()->where('name', 'super-admin')->lockForUpdate()->first();
            if (! $role) {
                return ['status' => 'missing_role'];
            }

            if (User::query()->whereHas('roles', fn ($query) => $query->where('name', 'super-admin'))->exists()) {
                return ['status' => 'already_exists'];
            }

            $name = $this->environmentValue('BOOTSTRAP_ADMIN_NAME');
            $email = $this->environmentValue('BOOTSTRAP_ADMIN_EMAIL');
            $password = $this->environmentValue('BOOTSTRAP_ADMIN_PASSWORD');
            $missing = [];

            foreach ([
                'BOOTSTRAP_ADMIN_NAME' => $name,
                'BOOTSTRAP_ADMIN_EMAIL' => $email,
                'BOOTSTRAP_ADMIN_PASSWORD' => $password,
            ] as $variable => $value) {
                if ($value === null || trim($value) === '') {
                    $missing[] = $variable;
                }
            }

            if ($missing !== []) {
                return ['status' => 'missing_environment', 'variables' => $missing];
            }

            $name = trim($name);
            $email = Str::lower(trim($email));
            $validation = Validator::make([
                'name' => $name,
                'email' => $email,
                'password' => $password,
            ], [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:255'],
                'password' => ['required', 'string', 'min:12', 'max:100'],
            ]);

            if ($validation->fails()) {
                return ['status' => 'invalid_input', 'errors' => $validation->errors()->all()];
            }

            if (User::query()->where('email', $email)->exists()) {
                return ['status' => 'email_exists'];
            }

            $user = new User;
            $user->forceFill([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
                'email_verified_at' => now(),
                'is_active' => true,
            ])->save();
            $user->roles()->attach($role->id);

            return ['status' => 'created', 'email' => $email];
        });

        if ($result['status'] === 'missing_role') {
            $this->error('The Super Admin role is missing. Run the production-safe reference/RBAC seeders first.');

            return self::FAILURE;
        }

        if ($result['status'] === 'missing_environment') {
            $this->error('Missing required environment variable(s): '.implode(', ', $result['variables']));

            return self::FAILURE;
        }

        if ($result['status'] === 'invalid_input') {
            foreach ($result['errors'] as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        if ($result['status'] === 'email_exists') {
            $this->error('The bootstrap email already belongs to a non-super-admin user. No account changes were made.');

            return self::FAILURE;
        }

        if ($result['status'] === 'already_exists') {
            $this->info('A Super Admin already exists. No account changes were made.');

            return self::SUCCESS;
        }

        $this->info("Super Admin account created for {$result['email']}.");

        return self::SUCCESS;
    }

    private function environmentValue(string $name): ?string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

        return is_string($value) ? $value : null;
    }
}
