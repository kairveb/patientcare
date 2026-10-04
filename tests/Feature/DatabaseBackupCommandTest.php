<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Console\Scheduling\Schedule;
use Mockery;
use Tests\TestCase;

class DatabaseBackupCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduled_database_backup_can_be_disabled_by_configuration(): void
    {
        $schedule = app(Schedule::class);
        $backupEvent = collect($schedule->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'db:backup'));

        $this->assertNotNull($backupEvent);
        $this->assertTrue($backupEvent->filtersPass($this->app));

        config(['database-backup.schedule_enabled' => false]);

        $this->assertFalse($backupEvent->filtersPass($this->app));
    }

    public function test_backup_uses_temporary_credentials_and_sanitizes_process_failure(): void
    {
        $defaultConnection = config('database.default');
        $password = 'Test$Secret;42';
        $this->configureMysql($password);
        Log::spy();

        $dumpCommand = null;
        $optionFile = null;
        Process::fake(function (PendingProcess $process) use (&$dumpCommand, &$optionFile, $password) {
            $command = $process->command;

            if (is_array($command) && ($command[0] ?? null) === 'icacls') {
                return Process::result();
            }

            if (is_string($command) && str_contains($command, 'mysqldump')) {
                $dumpCommand = $command;
                preg_match('/--defaults-extra-file=(?:"([^"]+)"|([^ ]+))/', $command, $matches);
                $optionFile = $matches[1] ?? $matches[2] ?? null;
                $this->assertNotNull($optionFile);
                $this->assertFileExists($optionFile);
                $this->assertStringContainsString(
                    'password="'.$password.'"',
                    (string) file_get_contents($optionFile)
                );

                return Process::result('', 'mysqldump failed with '.$password, 2);
            }

            $this->fail('Unexpected external process was requested.');
        });

        try {
            $exitCode = Artisan::call('db:backup');
            $output = Artisan::output();
        } finally {
            config(['database.default' => $defaultConnection]);
        }

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Database backup failed.', $output);
        $this->assertStringNotContainsString($password, $output);
        $this->assertNotNull($dumpCommand);
        $this->assertStringNotContainsString($password, $dumpCommand);
        $this->assertFileDoesNotExist($optionFile);

        Log::shouldHaveReceived('error')
            ->once()
            ->with('Database backup failed.', Mockery::on(
                fn (array $context): bool => ($context['exit_code'] ?? null) === 2
                    && ! str_contains(json_encode($context), $password)
            ));

        Process::assertRan(fn (PendingProcess $process): bool => is_string($process->command)
            && str_contains($process->command, 'mysqldump')
            && ! str_contains($process->command, $password));
    }

    private function configureMysql(string $password): void
    {
        config([
            'database.default' => 'mysql',
            'database.connections.mysql' => [
                'driver' => 'mysql',
                'host' => '127.0.0.1',
                'port' => '3306',
                'database' => 'test_database',
                'username' => 'test_user',
                'password' => $password,
            ],
        ]);
    }
}
