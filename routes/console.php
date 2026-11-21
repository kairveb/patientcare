<?php

use App\Services\AppointmentReminderService;
use App\Services\DatabaseBackupService;
use App\Services\IntegrationExportService;
use App\Services\MailFailureLogger;
use App\Services\PatientService;
use App\Mail\TestMailMessage;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Validator;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('hims:mail:test {email}', function (MailFailureLogger $mailFailureLogger): int {
    $email = (string) $this->argument('email');
    $validation = Validator::make(['email' => $email], ['email' => ['required', 'email']]);

    if ($validation->fails()) {
        $this->error('Mail test failed: provide a valid email address.');

        return self::FAILURE;
    }

    try {
        Mail::to($email)->send(new TestMailMessage());
    } catch (\Throwable $exception) {
        $mailFailureLogger->recordFailure('mail_test', $email, $exception);
        $this->error('Mail test failed. Check the sanitized application log entry.');

        return self::FAILURE;
    }

    $this->info('Mail test sent successfully.');

    return self::SUCCESS;
})->purpose('Send a plain-text test email using the configured mail transport');

Artisan::command('db:backup', function (DatabaseBackupService $backupService) {
    $connection = config('database.default');

    if (! in_array($connection, ['mysql', 'mariadb'], true)) {
        $this->warn('Database backup is only available for MySQL/MariaDB connections.');

        return self::FAILURE;
    }

    $config = config("database.connections.{$connection}");
    $database = $config['database'] ?? null;
    $host = $config['host'] ?? '127.0.0.1';
    $port = $config['port'] ?? '3306';
    $username = $config['username'] ?? 'root';
    $password = $config['password'] ?? '';

    if (! $database) {
        $this->error('No database name is configured for the active database connection.');

        return self::FAILURE;
    }

    $dir = storage_path('app/backups');
    if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
        $this->error('Unable to create the backup directory at ' . $dir);

        return self::FAILURE;
    }

    $filename = now()->format('Ymd_His') . '_' . $connection . '.sql';
    $backupPath = $dir . DIRECTORY_SEPARATOR . $filename;

    try {
        $result = $backupService->run([
            'host' => $host,
            'port' => (string) $port,
            'username' => $username,
            'password' => $password,
        ], $database, $backupPath);
    } catch (\RuntimeException $exception) {
        Log::error('Database backup failed.', [
            'connection' => $connection,
            'exception' => $exception::class,
        ]);
        $this->error('Database backup failed. Check the application log for details.');

        return self::FAILURE;
    }

    if (! $result->successful()) {
        Log::error('Database backup failed.', [
            'connection' => $connection,
            'exit_code' => $result->exitCode(),
        ]);
        $this->error('Database backup failed. Check the application log for details.');

        return self::FAILURE;
    }

    $this->info('Database backup created at ' . $backupPath);

    return self::SUCCESS;
})->purpose('Create a MySQL backup using mysqldump');

Artisan::command('patients:repair-encryption', function () {
    $this->info('Scanning patients for legacy plaintext sensitive fields...');

    $updated = 0;
    $alreadyEncrypted = 0;
    $skipped = 0;

    $patients = DB::table('patients')->select('id', 'allergies', 'insurance_number')->get();

    foreach ($patients as $patientRow) {
        foreach (['allergies', 'insurance_number'] as $field) {
            $rawValue = $patientRow->{$field};

            if ($rawValue === null || $rawValue === '') {
                $skipped++;
                continue;
            }

            try {
                Crypt::decrypt((string) $rawValue);
                $alreadyEncrypted++;
                continue;
            } catch (\Throwable $e) {
                // Raw value is legacy plaintext or otherwise invalid ciphertext and needs to be encrypted via the model cast.
            }

            $patient = new \App\Models\Patient();
            $patient->exists = true;
            $patient->id = $patientRow->id;
            $patient->{$field} = $rawValue;
            $patient->save();
            $updated++;
        }
    }

    $this->info("Patients scanned: {$patients->count()}");
    $this->info("Already encrypted: {$alreadyEncrypted}");
    $this->info("Legacy plaintext repaired: {$updated}");
    $this->info("Null/empty values skipped: {$skipped}");

    return self::SUCCESS;
})->purpose('Repair legacy plaintext patient allergies and insurance_number values by re-encrypting them through the model.');

Artisan::command('patients:backfill-reference-codes', function (PatientService $patients): int {
    $updated = $patients->backfillReferenceCodes();
    $this->info("Patient reference codes backfilled: {$updated}");

    return self::SUCCESS;
})->purpose('Assign permanent reference codes to existing patient records.');

Schedule::command('db:backup')
    ->dailyAt('02:00')
    ->when(fn (): bool => (bool) config('database-backup.schedule_enabled'));

Artisan::command('appointments:send-reminders', function (AppointmentReminderService $reminders): void {
    $queued = $reminders->queueDueReminders();
    $this->info("Queued {$queued} appointment reminder(s).");
})->purpose('Queue email reminders for confirmed appointments');

Schedule::command('appointments:send-reminders')->everyMinute()->withoutOverlapping(5);

Artisan::command('hims:request-hads-export {dataset} {from} {to} {format}', function (IntegrationExportService $exports): int {
    try {
        $export = $exports->request(
            (string) $this->argument('dataset'),
            (string) $this->argument('from'),
            (string) $this->argument('to'),
            (string) $this->argument('format')
        );
    } catch (\Illuminate\Validation\ValidationException $exception) {
        $this->error($exception->getMessage());

        return self::INVALID;
    }

    $this->info("HADS export {$export->export_ref}: {$export->status}");

    return self::SUCCESS;
})->purpose('Request a minimized, aggregate-only HADS export for scheduled operational reporting');

Schedule::call(fn (): int => app(IntegrationExportService::class)->expireRetainedExports())
    ->dailyAt('00:10')
    ->name('expire-hads-export-snapshots')
    ->withoutOverlapping();
