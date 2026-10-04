<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

class MfaQrCodeTest extends TestCase
{
    public function test_mfa_setup_qr_is_generated_locally(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $response = $this->actingAs($user)->get(route('mfa.setup'));

        $response->assertOk();
        $response->assertDontSee('api.qrserver.com');

        $secret = session('mfa_pending_secret');
        $this->assertIsString($secret);
        $provisioningUri = app(\App\Services\MfaService::class)->provisioningUri($secret, $user->email);
        $qrImage = app(\App\Services\PatientQrCodeService::class)->dataUriFor($provisioningUri);
        $response->assertSee($qrImage, false);

        $svg = base64_decode(substr($qrImage, strlen('data:image/svg+xml;base64,')), true);
        $this->assertIsString($svg);
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringNotContainsString(session('mfa_pending_secret'), $svg);
    }
}
