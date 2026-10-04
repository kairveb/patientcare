<?php

namespace Tests\Feature;

use App\Models\User;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

class TrustedProxyHttpsTest extends TestCase
{
    public function test_trusted_https_proxy_prevents_redirect_loop_and_sets_secure_urls_and_cookie(): void
    {
        config([
            'trustedproxy.proxies' => '127.0.0.1',
            'session.secure' => true,
        ]);
        $this->app->detectEnvironment(fn () => 'production');

        $response = $this->withServerVariables([
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_HOST' => 'app.internal.test',
            'SERVER_PORT' => '80',
            'HTTPS' => 'off',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'demo.example.test',
        ])->get('/login');

        $response->assertOk();
        $response->assertHeader('Set-Cookie');
        $this->assertStringStartsWith('https://demo.example.test/', url('/patient/dashboard'));
        $cookies = collect($response->headers->getCookies());
        $sessionCookie = $cookies->first(fn (Cookie $cookie) => $cookie->getName() === config('session.cookie'));

        $this->assertNotNull($sessionCookie);
        $this->assertTrue($sessionCookie->isSecure());
        $this->assertTrue($cookies->contains(fn (Cookie $cookie) => $cookie->getName() === 'XSRF-TOKEN'));
    }

    public function test_trusted_https_proxy_keeps_login_session_through_dashboard_redirect(): void
    {
        config([
            'trustedproxy.proxies' => '127.0.0.1',
            'session.secure' => false,
        ]);
        $this->app->detectEnvironment(fn () => 'production');

        $user = User::factory()->create();
        $proxyHeaders = [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_HOST' => 'app.internal.test',
            'SERVER_PORT' => '80',
            'HTTPS' => 'off',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'demo.example.test',
        ];

        $this->withServerVariables($proxyHeaders)->get('/login')->assertOk();
        $csrfToken = session()->token();

        $login = $this->withServerVariables($proxyHeaders)->post('/login', [
            '_token' => $csrfToken,
            'email' => $user->email,
            'password' => 'password',
        ]);

        $login->assertRedirect(route('dashboard', absolute: false));
        $dashboard = $this->withServerVariables($proxyHeaders)->get('/dashboard');

        $dashboard->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    public function test_untrusted_source_cannot_spoof_forwarded_scheme_or_host(): void
    {
        config(['trustedproxy.proxies' => '127.0.0.1']);
        $this->app->detectEnvironment(fn () => 'production');

        $response = $this->withServerVariables([
            'REMOTE_ADDR' => '10.0.0.25',
            'HTTP_HOST' => 'app.internal.test',
            'SERVER_PORT' => '80',
            'HTTPS' => 'off',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'spoofed.example.test',
        ])->get('/login');

        $response->assertStatus(308);
        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('https://', $location);
        $this->assertStringNotContainsString('spoofed.example.test', $location);
    }

    public function test_health_route_returns_ok_without_https_redirect(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $response = $this->withServerVariables([
            'SERVER_PORT' => '80',
            'HTTPS' => 'off',
        ])->get('/up');

        $response->assertOk();
        $this->assertNull($response->headers->get('Location'));
    }
}
