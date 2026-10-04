<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacReconciliationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateDemoAccountsCommandTest extends TestCase
{
    use RefreshDatabase;

    private array $originalEnvironment = [];

    protected function tearDown(): void
    {
        foreach ($this->originalEnvironment as $name => $value) {
            if ($value === false) {
                putenv($name);
                unset($_ENV[$name], $_SERVER[$name]);

                continue;
            }

            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }

        Env::enablePutenv();

        parent::tearDown();
    }

    public function test_command_refuses_to_run_before_reference_roles_exist(): void
    {
        $this->artisan('hims:demo-accounts')
            ->expectsOutput('Required roles or reconciled permissions are missing. Run ReferenceDataSeeder and RbacReconciliationSeeder first.')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_legacy_demo_accounts_command_refuses_to_run_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('hims:demo-accounts')
            ->expectsOutput('Demo account provisioning through this legacy command is disabled in production. Use app:create-demo-staff instead.')
            ->assertFailed()
            ->run();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_command_refuses_to_run_until_rbac_reconciliation_is_complete(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->setDemoPasswords();

        $this->artisan('hims:demo-accounts')
            ->expectsOutput('Required roles or reconciled permissions are missing. Run ReferenceDataSeeder and RbacReconciliationSeeder first.')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_command_creates_five_role_accounts_and_is_idempotent_without_resetting_passwords(): void
    {
        $this->seed([
            ReferenceDataSeeder::class,
            RbacReconciliationSeeder::class,
        ]);
        $this->setDemoPasswords();

        $this->artisan('hims:demo-accounts')
            ->expectsOutput('Demo accounts created or reconciled. Existing passwords were left unchanged.')
            ->assertSuccessful();

        $this->assertStringNotContainsString('DemoAccount!234', Artisan::output());
        $accounts = [
            'super-admin@coor.test' => 'super-admin',
            'registration@coor.test' => 'registration',
            'doctor@coor.test' => 'doctor',
            'nurse@coor.test' => 'nurse',
            'patient@coor.test' => 'patient',
        ];

        $this->assertDatabaseCount('users', count($accounts));
        foreach ($accounts as $email => $roleName) {
            $user = User::query()->where('email', $email)->firstOrFail();
            $this->assertTrue($user->hasRole($roleName));
            $this->assertNotNull($user->email_verified_at);
            $this->assertNotEmpty($user->password);
            $this->assertTrue(Hash::check('DemoAccount!234', $user->password));
        }

        $superAdmin = User::query()->where('email', 'super-admin@coor.test')->firstOrFail();
        $originalPassword = 'KeepThis!123';
        $superAdmin->forceFill(['password' => Hash::make($originalPassword)])->save();

        $registration = User::query()->where('email', 'registration@coor.test')->firstOrFail();
        $registration->roles()->attach(Role::query()->where('name', 'patient')->value('id'));

        $this->setDemoPasswords('Changed!Password456');
        $output = $this->artisan('hims:demo-accounts')
            ->expectsOutput('Demo accounts created or reconciled. Existing passwords were left unchanged.')
            ->run();

        $this->assertSame(0, $output);
        $this->assertDatabaseCount('users', count($accounts));
        $this->assertTrue(Hash::check($originalPassword, $superAdmin->fresh()->password));
        $this->assertSame(2, $registration->fresh()->roles()->count());
        $this->assertSame(
            1,
            $registration->fresh()->roles()->where('roles.name', 'registration')->count()
        );
    }

    private function setDemoPasswords(string $password = 'DemoAccount!234'): void
    {
        foreach ([
            'DEMO_SUPER_ADMIN_PASSWORD',
            'DEMO_REGISTRATION_PASSWORD',
            'DEMO_DOCTOR_PASSWORD',
            'DEMO_NURSE_PASSWORD',
            'DEMO_PATIENT_PASSWORD',
        ] as $name) {
            if (! array_key_exists($name, $this->originalEnvironment)) {
                $this->originalEnvironment[$name] = getenv($name);
            }

            putenv("{$name}={$password}");
            $_ENV[$name] = $password;
            $_SERVER[$name] = $password;
        }

        Env::enablePutenv();
    }
}
