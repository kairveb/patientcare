<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_creates_and_then_updates_the_initial_active_super_admin(): void
    {
        $this->seed(ReferenceDataSeeder::class);

        $this->artisan('app:create-admin')
            ->expectsQuestion('Name', 'Initial Administrator')
            ->expectsQuestion('Email', 'admin@example.test')
            ->expectsQuestion('Password (minimum 12 characters)', 'InitialAdmin!123')
            ->expectsOutput('Super Admin account ready for admin@example.test.')
            ->assertSuccessful();

        $user = User::query()->where('email', 'admin@example.test')->firstOrFail();
        $this->assertTrue($user->hasRole('super-admin'));
        $this->assertTrue($user->is_active);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('InitialAdmin!123', $user->password));
        $this->assertDatabaseCount('users', 1);

        $this->artisan('app:create-admin')
            ->expectsQuestion('Name', 'Updated Administrator')
            ->expectsQuestion('Email', 'admin@example.test')
            ->expectsQuestion('Password (minimum 12 characters)', 'ChangedAdmin!456')
            ->expectsOutput('Super Admin account ready for admin@example.test.')
            ->assertSuccessful();

        $user->refresh();
        $this->assertSame('Updated Administrator', $user->name);
        $this->assertTrue(Hash::check('ChangedAdmin!456', $user->password));
        $this->assertDatabaseCount('users', 1);
    }

    public function test_command_rejects_passwords_shorter_than_twelve_characters(): void
    {
        $this->seed(ReferenceDataSeeder::class);

        $this->artisan('app:create-admin')
            ->expectsQuestion('Name', 'Initial Administrator')
            ->expectsQuestion('Email', 'admin@example.test')
            ->expectsQuestion('Password (minimum 12 characters)', 'ShortPass1!')
            ->expectsOutput('The password field must be at least 12 characters.')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }
}
