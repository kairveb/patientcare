<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HimsPreflight extends Command
{
    protected $signature = 'hims:preflight';

    protected $description = 'Check deployment configuration without changing application state';

    public function handle(): int
    {
        $failures = 0;
        $report = function (string $status, string $check, string $detail) use (&$failures): void {
            if ($status === 'FAIL') {
                $failures++;
            }
            $this->line("{$status} {$check}: {$detail}");
        };

        $environment = (string) config('app.env');
        $production = $environment === 'production';
        $debug = (bool) config('app.debug');
        $report(
            $production && $debug ? 'FAIL' : ($debug ? 'WARN' : 'PASS'),
            'APP_ENV / APP_DEBUG',
            "{$environment} / ".($debug ? 'true' : 'false')
        );

        $appUrl = (string) config('app.url');
        $host = parse_url($appUrl, PHP_URL_HOST);
        $localUrl = ! is_string($host) || $host === ''
            || in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true)
            || str_ends_with(strtolower($host), '.test');
        $urlStatus = $production && ($appUrl === '' || $localUrl) ? 'FAIL' : ($appUrl === '' ? 'FAIL' : 'PASS');
        $report($urlStatus, 'APP_URL', $appUrl === '' ? 'not set' : ($production && $localUrl ? 'not production-safe' : 'set'));

        $otpEnabled = (bool) config('security.otp_enabled', true);
        $report('PASS', 'OTP_ENABLED', $otpEnabled ? 'enabled' : 'disabled');
        $mailer = (string) config('mail.default');
        $unsafeMailer = in_array($mailer, ['log', 'array'], true);
        $report(
            $otpEnabled && $unsafeMailer ? 'FAIL' : 'PASS',
            'MAIL_MAILER',
            $otpEnabled && $unsafeMailer ? 'not suitable while OTP is enabled' : 'configured'
        );

        $telehealthProvider = config('telehealth.provider');
        $zoomCredentialsPresent = filled(config('services.zoom.account_id'))
            && filled(config('services.zoom.client_id'))
            && filled(config('services.zoom.client_secret'));
        $zoomEnabled = filter_var(config('services.zoom.enabled', false), FILTER_VALIDATE_BOOL);
        $zoomConfigured = $zoomEnabled && $zoomCredentialsPresent;
        $providerConfigured = in_array($telehealthProvider, ['zoom', 'jitsi_demo'], true);
        $report(
            ! $providerConfigured && ! $zoomConfigured ? 'WARN' : 'PASS',
            'TELEHEALTH_PROVIDER',
            filled($telehealthProvider) ? (string) $telehealthProvider : 'not explicitly set'
        );
        $report(
            $zoomConfigured ? 'PASS' : 'WARN',
            'ZOOM credentials',
            'all required credentials '.($zoomCredentialsPresent ? 'are set' : 'are not set')
        );

        $dpo = (array) config('privacy.dpo', []);
        $dpoComplete = filled($dpo['name'] ?? null)
            && filled($dpo['email'] ?? null)
            && filled($dpo['phone'] ?? null);
        $report($dpoComplete ? 'PASS' : 'WARN', 'DPO contact', $dpoComplete ? 'name, email, and phone are set' : 'name, email, or phone is missing');

        $aiProvider = strtolower((string) config('services.ai_triage.provider', 'rules'));
        $aiKey = match ($aiProvider) {
            'gemini' => config('services.ai_triage.gemini.api_key'),
            'openai' => config('services.ai_triage.openai.api_key'),
            default => null,
        };
        $aiKeyRequired = in_array($aiProvider, ['gemini', 'openai'], true);
        $report(
            $aiKeyRequired && blank($aiKey) ? 'WARN' : 'PASS',
            'AI provider',
            "{$aiProvider}; API key ".($aiKeyRequired ? (filled($aiKey) ? 'is set' : 'is not set') : 'not required')
        );

        $queue = (string) config('queue.default');
        $remindersEnabled = (bool) config('appointments.reminders_enabled', true);
        $report(
            $queue === 'sync' && $remindersEnabled ? 'WARN' : 'PASS',
            'QUEUE_CONNECTION',
            $queue.($queue === 'sync' && $remindersEnabled ? '; reminders run synchronously' : '')
        );

        $migrationStatus = $this->migrationStatus();
        $report($migrationStatus['status'], 'Migrations', $migrationStatus['detail']);

        $storageLinkExists = is_link(public_path('storage')) || is_dir(public_path('storage'));
        $report($storageLinkExists ? 'PASS' : 'WARN', 'Storage link', $storageLinkExists ? 'present' : 'missing');
        $this->line('WARN Scheduler and queue: ensure a once-per-minute scheduler and a queue worker are running.');

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array{status: string, detail: string}
     */
    private function migrationStatus(): array
    {
        if (! Schema::hasTable('migrations')) {
            return ['status' => 'FAIL', 'detail' => 'migration repository table is missing'];
        }

        $ran = DB::table('migrations')->pluck('migration')->all();
        $files = glob(database_path('migrations').'/*.php') ?: [];
        $pending = array_diff(
            array_map(fn (string $file): string => pathinfo($file, PATHINFO_FILENAME), $files),
            $ran
        );

        return $pending === []
            ? ['status' => 'PASS', 'detail' => 'no pending migrations']
            : ['status' => 'FAIL', 'detail' => count($pending).' pending migration(s)'];
    }
}
