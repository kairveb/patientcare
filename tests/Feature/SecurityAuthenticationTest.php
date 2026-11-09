<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Notifications\LoginOtpNotification;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SecurityAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['security.otp_enabled' => true]);
        Notification::fake();
    }

    public function test_user_can_sign_in_using_username_and_receive_a_hashed_email_otp(): void
    {
        $user = User::factory()->create([
            'username' => 'patient.one',
            'email' => 'patient.one@example.test',
        ]);

        $this->post(route('login'), [
            'identifier' => $user->username,
            'password' => 'password',
        ])->assertRedirect(route('mfa.challenge'));

        $this->assertGuest();
        $this->assertNotNull($user->fresh()->login_otp_hash);
        $this->assertNotSame($user->fresh()->login_otp_hash, Notification::sent($user, LoginOtpNotification::class)->first()->code);
        $this->assertTrue(Hash::check(Notification::sent($user, LoginOtpNotification::class)->first()->code, $user->fresh()->login_otp_hash));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'OTP_SENT',
            'resource_id' => $user->id,
        ]);
    }

    public function test_user_can_sign_in_using_email_and_verify_the_code(): void
    {
        $user = User::factory()->create();
        $this->post(route('login'), [
            'identifier' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('mfa.challenge'));

        $code = Notification::sent($user, LoginOtpNotification::class)->first()->code;
        $this->post(route('mfa.verify'), ['code' => $code])
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
        $this->assertNull($user->fresh()->login_otp_hash);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'LOGIN_SUCCESS',
            'resource_id' => $user->id,
        ]);
    }

    public function test_email_otp_verifies_an_unverified_user_before_loading_the_dashboard(): void
    {
        $user = User::factory()->unverified()->create();
        Event::fake([Verified::class]);

        $this->post(route('login'), [
            'identifier' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('mfa.challenge'));

        $code = Notification::sent($user, LoginOtpNotification::class)->first()->code;
        $this->post(route('mfa.verify'), ['code' => $code])
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        Event::assertDispatched(Verified::class, fn (Verified $event): bool => $event->user->is($user));

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertViewIs('dashboard');
    }

    public function test_registration_lists_password_requirements_and_reports_confirmation_mismatch(): void
    {
        $this->get(route('register'))
            ->assertOk()
            ->assertSee('At least 8 characters')
            ->assertSee('A lowercase letter')
            ->assertSee('A symbol, punctuation mark, or separator');

        $this->post(route('register'), [
            'name' => 'New Patient',
            'email' => 'new.patient@example.test',
            'password' => 'ValidPass1!',
            'password_confirmation' => 'DifferentPass1!',
            'privacy_notice_accepted' => '1',
            'terms_accepted' => '1',
        ])
            ->assertSessionHasErrors('password_confirmation')
            ->assertSessionDoesntHaveErrors('password');
    }

    public function test_expired_otp_is_rejected_and_cleared(): void
    {
        $user = $this->startEmailOtpLogin();
        $code = Notification::sent($user, LoginOtpNotification::class)->first()->code;

        $this->travel(6)->minutes();
        $this->post(route('mfa.verify'), ['code' => $code])
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertNull($user->fresh()->login_otp_hash);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'OTP_FAILED',
            'resource_id' => $user->id,
        ]);
    }

    public function test_otp_is_single_use(): void
    {
        $user = $this->startEmailOtpLogin();
        $code = Notification::sent($user, LoginOtpNotification::class)->first()->code;

        $this->post(route('mfa.verify'), ['code' => $code])->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($user);
        $this->assertNull($user->fresh()->login_otp_hash);

        $this->post(route('logout'));
        $this->post(route('mfa.verify'), ['code' => $code])->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_wrong_otp_attempt_limit_ends_the_challenge(): void
    {
        config(['security.otp_max_attempts' => 3]);
        $user = $this->startEmailOtpLogin();
        $code = Notification::sent($user, LoginOtpNotification::class)->first()->code;
        $wrongCode = $code === '000000' ? '000001' : '000000';

        $this->post(route('mfa.verify'), ['code' => $wrongCode])->assertRedirect();
        $this->post(route('mfa.verify'), ['code' => $wrongCode])->assertRedirect();
        $this->post(route('mfa.verify'), ['code' => $wrongCode])->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertNull($user->fresh()->login_otp_hash);
        $this->assertSame(3, AuditLog::where('action', 'OTP_FAILED')->count());
    }

    public function test_otp_resend_obeys_cooldown_and_a_per_challenge_limit(): void
    {
        config(['security.otp_max_resends' => 1]);
        $user = $this->startEmailOtpLogin();

        $this->post(route('mfa.resend'))->assertSessionHasErrors('code');
        $this->travel(31)->seconds();
        $this->post(route('mfa.resend'))->assertRedirect();
        $this->assertSame(1, (int) $user->fresh()->login_otp_resends);
        $this->assertCount(2, Notification::sent($user, LoginOtpNotification::class));

        $this->travel(31)->seconds();
        $this->post(route('mfa.resend'))->assertRedirect(route('login'));
    }

    public function test_api_login_requires_email_otp_before_issuing_a_sanctum_token(): void
    {
        $user = User::factory()->create(['username' => 'api.user']);

        $challenge = $this->postJson('/api/v1/auth/login', [
            'identifier' => $user->username,
            'password' => 'password',
        ])->assertStatus(202)
            ->assertJsonMissingPath('data.token')
            ->json('data.challenge_token');

        $code = Notification::sent($user, LoginOtpNotification::class)->first()->code;
        $this->postJson('/api/v1/auth/verify-otp', [
            'challenge_token' => $challenge,
            'code' => $code,
        ])->assertOk()
            ->assertJsonPath('data.user.username', $user->username)
            ->assertJsonStructure(['data' => ['token']]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'LOGIN_SUCCESS',
            'resource_id' => $user->id,
        ]);
        $this->assertNull($user->fresh()->login_otp_hash);
    }

    public function test_login_lockout_expires_after_the_configured_period(): void
    {
        config([
            'security.otp_enabled' => false,
            'security.login_max_attempts' => 2,
            'security.login_lockout_minutes' => 1,
        ]);
        $user = User::factory()->create(['username' => 'locked.user']);

        $this->post(route('login'), ['identifier' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->post(route('login'), ['identifier' => $user->username, 'password' => 'wrong'])
            ->assertSessionHasErrors('email');
        $this->assertStringContainsString('Too many failed sign-in attempts', session('errors')->first('email'));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'LOGIN_LOCKOUT',
            'resource_id' => $user->id,
        ]);

        $this->post(route('login'), ['identifier' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->travel(61)->seconds();
        $this->post(route('login'), ['identifier' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($user);
    }

    public function test_idle_timeout_logs_out_redirects_and_audits(): void
    {
        $user = User::factory()->create([
            'last_activity_at' => now()->subMinutes(6),
        ]);

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'SESSION_TIMEOUT',
            'resource_id' => $user->id,
        ]);
    }

    public function test_disabling_otp_logs_a_warning_and_allows_password_sign_in(): void
    {
        config(['security.otp_enabled' => false]);
        Log::spy();
        $user = User::factory()->create();

        $this->post(route('login'), [
            'identifier' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
        Notification::assertNothingSent();
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_admin_cannot_list_or_modify_super_admin_accounts(): void
    {
        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Admin']);
        $manageUsers = Permission::firstOrCreate(['name' => 'manage-users'], ['label' => 'Manage Users']);
        $adminRole->permissions()->syncWithoutDetaching([$manageUsers->id]);
        $admin = User::factory()->create();
        $admin->roles()->attach($adminRole);
        $superAdmin = $this->userWithRole('super-admin');

        $this->actingAs($admin)->get(route('admin.users.index'))
            ->assertOk()
            ->assertDontSee($superAdmin->email);
        $this->patch(route('admin.users.role', $superAdmin), ['role' => 'doctor'])
            ->assertForbidden();
        $this->patch(route('admin.users.status', $superAdmin), ['is_active' => false])
            ->assertForbidden();

        $target = $this->userWithRole('nurse');
        $this->patch(route('admin.users.role', $target), ['role' => 'super-admin'])
            ->assertForbidden();
    }

    public function test_staff_roles_display_as_staff_without_changing_role_names(): void
    {
        $nurse = Role::firstOrCreate(['name' => 'nurse'], ['label' => 'Nurse']);
        $registration = Role::firstOrCreate(['name' => 'registration'], ['label' => 'Registration / Front Desk']);

        $this->assertSame('Staff', $nurse->display_name);
        $this->assertSame('Staff', $registration->display_name);
        $this->assertSame('nurse', $nurse->name);
        $this->assertSame('registration', $registration->name);
    }

    public function test_otp_configuration_defaults_are_secure_and_configurable(): void
    {
        $this->assertTrue(config('security.otp_enabled'));
        $this->assertSame(5, config('security.otp_ttl_minutes'));
        $this->assertSame(5, config('security.login_max_attempts'));
        $this->assertSame(2, config('security.login_lockout_minutes'));
        $this->assertSame(5, config('security.session_idle_minutes'));
    }

    private function startEmailOtpLogin(): User
    {
        $user = User::factory()->create();
        $this->post(route('login'), [
            'identifier' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('mfa.challenge'));

        return $user;
    }

    private function userWithRole(string $name): User
    {
        $role = Role::firstOrCreate(['name' => $name], ['label' => ucfirst($name)]);
        $user = User::factory()->create();
        $user->roles()->attach($role);

        return $user;
    }
}
