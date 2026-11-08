<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BootstrapAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var array<string, array{environment: string|false, env_exists: bool, env: mixed, server_exists: bool, server: mixed}>
     */
    private array $originalVariables = [];

    protected function tearDown(): void
    {
        foreach ($this->originalVariables as $name => $original) {
            $original['environment'] === false
                ? putenv($name)
                : putenv($name.'='.$original['environment']);

            if ($original['env_exists']) {
                $_ENV[$name] = $original['env'];
            } else {
                unset($_ENV[$name]);
            }

            if ($original['server_exists']) {
                $_SERVER[$name] = $original['server'];
            } else {
                unset($_SERVER[$name]);
            }
        }

        parent::tearDown();
    }

    public function test_command_creates_an_active_verified_super_admin_and_is_safe_to_rerun(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->setBootstrapEnvironment('Initial Administrator', 'admin@example.test', 'InitialAdmin!123');

        $this->assertSame(0, Artisan::call('app:bootstrap-admin'));
        $output = Artisan::output();
        $this->assertStringContainsString('Super Admin account created for admin@example.test.', $output);
        $this->assertStringNotContainsString('InitialAdmin!123', $output);

        $user = User::query()->where('email', 'admin@example.test')->firstOrFail();
        $this->assertTrue($user->hasRole('super-admin'));
        $this->assertTrue($user->is_active);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('InitialAdmin!123', $user->password));

        $this->setBootstrapEnvironment('', '', '');

        $this->assertSame(0, Artisan::call('app:bootstrap-admin'));
        $this->assertStringContainsString('A Super Admin already exists. No account changes were made.', Artisan::output());
        $this->assertDatabaseCount('users', 1);
        $user->refresh();
        $this->assertSame('Initial Administrator', $user->name);
        $this->assertSame(['super-admin'], $user->roles()->pluck('name')->all());
        $this->assertTrue(Hash::check('InitialAdmin!123', $user->password));
    }

    public function test_command_reports_missing_environment_variables_without_creating_an_account(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->setBootstrapEnvironment('', '', '');

        $this->assertSame(1, Artisan::call('app:bootstrap-admin'));
        $this->assertStringContainsString(
            'Missing required environment variable(s): BOOTSTRAP_ADMIN_NAME, BOOTSTRAP_ADMIN_EMAIL, BOOTSTRAP_ADMIN_PASSWORD',
            Artisan::output()
        );
        $this->assertDatabaseCount('users', 0);
    }

    public function test_command_does_not_change_an_existing_non_admin_user_with_the_bootstrap_email(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $user = User::factory()->create([
            'name' => 'Existing User',
            'email' => 'admin@example.test',
            'password' => 'ExistingPassword!123',
        ]);
        $this->setBootstrapEnvironment('Bootstrap Admin', 'admin@example.test', 'BootstrapPassword!123');

        $this->assertSame(1, Artisan::call('app:bootstrap-admin'));
        $this->assertStringContainsString(
            'The bootstrap email already belongs to a non-super-admin user. No account changes were made.',
            Artisan::output()
        );

        $user->refresh();
        $this->assertSame('Existing User', $user->name);
        $this->assertTrue(Hash::check('ExistingPassword!123', $user->password));
        $this->assertFalse($user->hasRole('super-admin'));
    }

    private function setBootstrapEnvironment(string $name, string $email, string $password): void
    {
        foreach ([
            'BOOTSTRAP_ADMIN_NAME' => $name,
            'BOOTSTRAP_ADMIN_EMAIL' => $email,
            'BOOTSTRAP_ADMIN_PASSWORD' => $password,
        ] as $variable => $value) {
            if (! array_key_exists($variable, $this->originalVariables)) {
                $this->originalVariables[$variable] = [
                    'environment' => getenv($variable),
                    'env_exists' => array_key_exists($variable, $_ENV),
                    'env' => $_ENV[$variable] ?? null,
                    'server_exists' => array_key_exists($variable, $_SERVER),
                    'server' => $_SERVER[$variable] ?? null,
                ];
            }

            putenv($variable.'='.$value);
            $_ENV[$variable] = $value;
            $_SERVER[$variable] = $value;
        }
    }
}
