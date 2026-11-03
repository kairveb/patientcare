<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

class HimsPreflightTest extends TestCase
{
    public function test_preflight_reports_configuration_without_printing_secret_values(): void
    {
        config([
            'app.env' => 'production',
            'app.debug' => false,
            'app.url' => 'https://hospital.example.org',
            'mail.default' => 'smtp',
            'security.otp_enabled' => true,
            'telehealth.provider' => 'zoom',
            'services.zoom.enabled' => true,
            'services.zoom.account_id' => 'secret-account-value',
            'services.zoom.client_id' => 'secret-client-value',
            'services.zoom.client_secret' => 'secret-client-secret',
            'privacy.dpo' => [
                'name' => 'DPO Name',
                'email' => 'dpo@example.org',
                'phone' => '0000000000',
            ],
            'services.ai_triage.provider' => 'gemini',
            'services.ai_triage.gemini.api_key' => 'secret-ai-key',
            'queue.default' => 'database',
        ]);

        $this->assertSame(0, Artisan::call('hims:preflight'));
        $output = Artisan::output();
        $this->assertStringContainsString('PASS APP_ENV / APP_DEBUG: production / false', $output);
        $this->assertStringContainsString('PASS MAIL_MAILER: configured', $output);
        $this->assertStringContainsString('PASS TELEHEALTH_PROVIDER: zoom', $output);
        $this->assertStringContainsString('PASS ZOOM credentials: all required credentials are set', $output);
        $this->assertStringContainsString('PASS DPO contact: name, email, and phone are set', $output);
        $this->assertStringContainsString('PASS AI provider: gemini; API key is set', $output);
        $this->assertStringContainsString('PASS Migrations: no pending migrations', $output);
        $this->assertStringContainsString('Scheduler and queue', $output);
        $this->assertStringNotContainsString('secret-account-value', $output);
        $this->assertStringNotContainsString('secret-client-secret', $output);
        $this->assertStringNotContainsString('secret-ai-key', $output);
    }

    public function test_preflight_fails_unsafe_production_debug_url_and_otp_mail_configuration(): void
    {
        config([
            'app.env' => 'production',
            'app.debug' => true,
            'app.url' => 'http://localhost',
            'mail.default' => 'log',
            'security.otp_enabled' => true,
            'telehealth.provider' => null,
            'services.zoom.enabled' => false,
            'services.zoom.account_id' => null,
            'services.zoom.client_id' => null,
            'services.zoom.client_secret' => null,
            'privacy.dpo' => ['name' => null, 'email' => null, 'phone' => null],
            'services.ai_triage.provider' => 'rules',
            'queue.default' => 'sync',
        ]);

        $this->assertSame(1, Artisan::call('hims:preflight'));
        $output = Artisan::output();
        $this->assertStringContainsString('FAIL APP_ENV / APP_DEBUG: production / true', $output);
        $this->assertStringContainsString('FAIL APP_URL: not production-safe', $output);
        $this->assertStringContainsString('FAIL MAIL_MAILER: not suitable while OTP is enabled', $output);
        $this->assertStringContainsString('WARN TELEHEALTH_PROVIDER: not explicitly set', $output);
        $this->assertStringContainsString('WARN DPO contact', $output);
        $this->assertStringContainsString('WARN QUEUE_CONNECTION: sync; reminders run synchronously', $output);
    }
}
