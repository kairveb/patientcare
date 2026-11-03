<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'privacy_notice_accepted' => '1',
            'terms_accepted' => '1',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_registration_sends_verification_immediately_with_the_canonical_production_https_url(): void
    {
        config(['app.url' => 'https://patientaccess.djnrmhs.com']);
        $this->app->detectEnvironment(fn () => 'production');
        (new AppServiceProvider($this->app))->boot();
        $this->app->detectEnvironment(fn () => 'testing');
        Notification::fake();

        $this->withServerVariables([
            'HTTP_HOST' => 'patientaccess.djnrmhs.com',
            'HTTPS' => 'on',
            'SERVER_PORT' => '443',
        ])->post('/register', [
            'name' => 'Verified User',
            'email' => 'verified@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'privacy_notice_accepted' => '1',
            'terms_accepted' => '1',
        ])->assertRedirect(route('dashboard', absolute: false));

        $user = User::query()->where('email', 'verified@example.com')->firstOrFail();
        Notification::assertSentTo($user, VerifyEmail::class);

        $notification = Notification::sent($user, VerifyEmail::class)->first();
        $message = $notification->toMail($user);
        $this->assertStringStartsWith('https://patientaccess.djnrmhs.com/', $message->actionUrl);
        $this->assertFalse(is_subclass_of(VerifyEmail::class, ShouldQueue::class));

        $this->get($message->actionUrl)
            ->assertRedirect(route('dashboard', absolute: false).'?verified=1');
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }
}
