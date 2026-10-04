<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateDemoStaffCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_creates_active_verified_accounts_for_every_role_without_patient_data_by_default(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $accounts = [];

        $command = $this->artisan('app:create-demo-staff');
        foreach ([
            'super-admin',
            'registration',
            'doctor',
            'nurse',
            'patient',
        ] as $role) {
            $name = 'Demo '.ucwords(str_replace('-', ' ', $role));
            $email = "{$role}@example.test";
            $accounts[$role] = compact('name', 'email');

            $command
                ->expectsQuestion("Name for {$role}", $name)
                ->expectsQuestion("Email for {$role}", $email);
        }

        $command
            ->expectsOutputToContain('Save these passwords now; they are only displayed once.')
            ->expectsOutputToContain('Created the patient-role account without a patient profile.')
            ->expectsOutputToContain('Created five active, verified demo accounts.')
            ->expectsOutputToContain('Generated password')
            ->expectsOutputToContain('super-admin@example.test')
            ->expectsOutputToContain('patient@example.test')
            ->assertSuccessful()
            ->run();

        $this->assertDatabaseCount('users', count($accounts));
        $this->assertDatabaseCount('patients', 0);

        foreach ($accounts as $roleName => $account) {
            $user = User::query()->where('email', $account['email'])->firstOrFail();
            $this->assertSame($account['name'], $user->name);
            $this->assertTrue($user->is_active);
            $this->assertNotNull($user->email_verified_at);
            $this->assertTrue($user->hasRole($roleName));
            $this->assertSame(1, $user->roles()->count());
            $this->assertStringStartsWith('$2y$', $user->getRawOriginal('password'));
        }
    }

    public function test_with_patient_flag_prompts_for_and_creates_only_the_explicit_patient_profile(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $command = $this->artisan('app:create-demo-staff', ['--with-patient' => true]);

        foreach ([
            'super-admin',
            'registration',
            'doctor',
            'nurse',
            'patient',
        ] as $role) {
            $command
                ->expectsQuestion("Name for {$role}", "Demo {$role}")
                ->expectsQuestion("Email for {$role}", "{$role}@example.test");
        }

        $command
            ->expectsQuestion('Patient profile first name', 'Demo')
            ->expectsQuestion('Patient profile last name', 'Patient')
            ->expectsQuestion('Patient profile date of birth (YYYY-MM-DD)', '1990-01-01')
            ->expectsQuestion('Patient profile sex', 'Not specified')
            ->expectsOutputToContain('Created five active, verified demo accounts.')
            ->expectsOutput('Created the patient profile using the details entered interactively.')
            ->assertSuccessful()
            ->run();

        $patientUser = User::query()->where('email', 'patient@example.test')->firstOrFail();
        $this->assertDatabaseCount('patients', 1);
        $this->assertSame($patientUser->id, Patient::query()->firstOrFail()->user_id);
        $this->assertSame('1990-01-01', Patient::query()->firstOrFail()->date_of_birth->toDateString());
    }

    public function test_production_requires_an_explicit_force_option_before_creating_demo_accounts(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('app:create-demo-staff')
            ->expectsOutput('Demo account creation in production requires the explicit --force option.')
            ->assertFailed()
            ->run();

        $this->assertDatabaseCount('users', 0);

        $command = $this->artisan('app:create-demo-staff', ['--force' => true]);
        foreach (['super-admin', 'registration', 'doctor', 'nurse', 'patient'] as $role) {
            $command
                ->expectsQuestion("Name for {$role}", "Demo {$role}")
                ->expectsQuestion("Email for {$role}", "{$role}@example.test");
        }

        $command
            ->expectsOutputToContain('Created five active, verified demo accounts.')
            ->assertSuccessful()
            ->run();

        $this->assertDatabaseCount('users', 5);
        $this->assertDatabaseCount('patients', 0);
    }
}
