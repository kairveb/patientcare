<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Services\PatientService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CreateDemoStaff extends Command
{
    protected $signature = 'app:create-demo-staff {--with-patient : Prompt for and create one synthetic patient profile} {--force : Confirm provisioning demo accounts in production}';

    protected $description = 'Create one active, verified demo account for each application role';

    private const ROLES = [
        'super-admin',
        'registration',
        'doctor',
        'nurse',
        'patient',
    ];

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Run this command interactively to enter account names and email addresses.');

            return self::FAILURE;
        }

        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('Demo account creation in production requires the explicit --force option.');

            return self::FAILURE;
        }

        $roles = Role::query()->whereIn('name', self::ROLES)->get()->keyBy('name');

        if ($roles->count() !== count(self::ROLES)) {
            $this->error('One or more required roles are missing. Run ReferenceDataSeeder first.');

            return self::FAILURE;
        }

        $accounts = [];

        foreach (self::ROLES as $roleName) {
            $name = trim((string) $this->ask("Name for {$roleName}"));
            $email = Str::lower(trim((string) $this->ask("Email for {$roleName}")));
            $validation = Validator::make(
                ['name' => $name, 'email' => $email],
                [
                    'name' => ['required', 'string', 'max:255'],
                    'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
                ]
            );

            if ($validation->fails()) {
                foreach ($validation->errors()->all() as $error) {
                    $this->error($error);
                }

                return self::FAILURE;
            }

            if (in_array($email, array_column($accounts, 'email'), true)) {
                $this->error('Each demo account must use a different email address.');

                return self::FAILURE;
            }

            $accounts[] = [
                'role' => $roleName,
                'name' => $name,
                'email' => $email,
                'password' => Str::password(32).'aA1!',
            ];
        }

        $patientProfile = null;
        if ($this->option('with-patient')) {
            $patientProfile = [
                'first_name' => trim((string) $this->ask('Patient profile first name')),
                'last_name' => trim((string) $this->ask('Patient profile last name')),
                'date_of_birth' => trim((string) $this->ask('Patient profile date of birth (YYYY-MM-DD)')),
                'sex' => trim((string) $this->ask('Patient profile sex')),
            ];
            $validation = Validator::make($patientProfile, [
                'first_name' => ['required', 'string', 'max:255'],
                'last_name' => ['required', 'string', 'max:255'],
                'date_of_birth' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
                'sex' => ['required', 'string', 'max:20'],
            ]);

            if ($validation->fails()) {
                foreach ($validation->errors()->all() as $error) {
                    $this->error($error);
                }

                return self::FAILURE;
            }
        }

        DB::transaction(function () use ($accounts, $roles, $patientProfile): void {
            foreach ($accounts as $account) {
                $user = User::create([
                    'name' => $account['name'],
                    'email' => $account['email'],
                    'password' => $account['password'],
                ]);
                $user->forceFill([
                    'email_verified_at' => now(),
                    'is_active' => true,
                ])->save();
                $user->roles()->sync([$roles->get($account['role'])->id]);

                if ($account['role'] === 'patient' && $patientProfile !== null) {
                    app(PatientService::class)->register([
                        ...$patientProfile,
                        'user_id' => $user->id,
                        'email' => $user->email,
                        'lookup_code' => Str::upper(Str::random(8)),
                        'pre_registration_status' => 'not_started',
                        'verified' => false,
                    ], $user->id);
                }
            }
        });

        $this->info('Created five active, verified demo accounts.');
        $this->table(
            ['Role', 'Name', 'Email', 'Generated password'],
            array_map(
                fn (array $account): array => [
                    $account['role'],
                    $account['name'],
                    $account['email'],
                    $account['password'],
                ],
                $accounts
            )
        );
        $this->warn('Save these passwords now; they are only displayed once.');

        if ($patientProfile !== null) {
            $this->info('Created the patient profile using the details entered interactively.');
        } else {
            $this->info('Created the patient-role account without a patient profile. Use --with-patient to add one.');
        }

        return self::SUCCESS;
    }
}
