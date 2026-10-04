<?php

namespace Tests\Feature;

use App\Http\Middleware\AllowCors;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class CorsConfigurationTest extends TestCase
{
    public function test_configured_origin_is_allowed_without_wildcard_credentials(): void
    {
        config(['cors.allowed_origins' => ['https://portal.example.test']]);

        $response = app(AllowCors::class)->handle(
            Request::create('https://api.example.test/health', 'GET', server: [
                'HTTP_ORIGIN' => 'https://portal.example.test',
            ]),
            fn () => new Response('ok')
        );

        $this->assertSame('https://portal.example.test', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertSame('true', $response->headers->get('Access-Control-Allow-Credentials'));
        $this->assertNotSame('*', $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_disallowed_origin_receives_no_cors_permission(): void
    {
        config(['cors.allowed_origins' => ['https://portal.example.test']]);

        $response = app(AllowCors::class)->handle(
            Request::create('https://api.example.test/health', 'GET', server: [
                'HTTP_ORIGIN' => 'https://attacker.example.test',
            ]),
            fn () => new Response('ok')
        );

        $this->assertNull($response->headers->get('Access-Control-Allow-Origin'));
        $this->assertNull($response->headers->get('Access-Control-Allow-Credentials'));
    }

    public function test_preflight_allows_configured_origin_and_rejects_disallowed_origin(): void
    {
        config(['cors.allowed_origins' => ['https://portal.example.test']]);
        $middleware = app(AllowCors::class);
        $next = fn () => new Response('unexpected');

        $allowed = $middleware->handle(
            Request::create('https://api.example.test/health', 'OPTIONS', server: [
                'HTTP_ORIGIN' => 'https://portal.example.test',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
                'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type',
            ]),
            $next
        );
        $this->assertSame(204, $allowed->getStatusCode());
        $this->assertSame('https://portal.example.test', $allowed->headers->get('Access-Control-Allow-Origin'));
        $this->assertStringContainsString('POST', $allowed->headers->get('Access-Control-Allow-Methods'));
        $this->assertStringContainsString('Content-Type', $allowed->headers->get('Access-Control-Allow-Headers'));

        $disallowed = $middleware->handle(
            Request::create('https://api.example.test/health', 'OPTIONS', server: [
                'HTTP_ORIGIN' => 'https://attacker.example.test',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            ]),
            $next
        );
        $this->assertSame(403, $disallowed->getStatusCode());
        $this->assertNull($disallowed->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_empty_allowlist_does_not_grant_cross_origin_access(): void
    {
        config(['cors.allowed_origins' => []]);

        $response = app(AllowCors::class)->handle(
            Request::create('https://api.example.test/health', 'GET', server: [
                'HTTP_ORIGIN' => 'https://portal.example.test',
            ]),
            fn () => new Response('ok')
        );

        $this->assertNull($response->headers->get('Access-Control-Allow-Origin'));
    }
}
