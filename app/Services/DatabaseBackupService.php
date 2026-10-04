<?php

namespace App\Services;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class DatabaseBackupService
{
    public function run(array $config, string $database, string $backupPath): ProcessResult
    {
        $optionFile = tempnam(sys_get_temp_dir(), 'pacc-mysqldump-');
        if ($optionFile === false) {
            throw new RuntimeException('Unable to create a temporary database credentials file.');
        }

        try {
            $this->restrictPermissions($optionFile);
            $contents = implode(PHP_EOL, [
                '[client]',
                'host='.$this->optionValue((string) ($config['host'] ?? '127.0.0.1')),
                'port='.$this->optionValue((string) ($config['port'] ?? '3306')),
                'user='.$this->optionValue((string) ($config['username'] ?? 'root')),
                'password='.$this->optionValue((string) ($config['password'] ?? '')),
                '',
            ]);

            if (file_put_contents($optionFile, $contents, LOCK_EX) === false) {
                throw new RuntimeException('Unable to write the temporary database credentials file.');
            }

            $stderrTarget = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
            $command = sprintf(
                '%s --defaults-extra-file=%s %s > %s 2>%s',
                escapeshellarg('mysqldump'),
                escapeshellarg($optionFile),
                escapeshellarg($database),
                escapeshellarg($backupPath),
                $stderrTarget
            );

            return Process::forever()->run($command);
        } finally {
            if (is_file($optionFile) && ! @unlink($optionFile)) {
                Log::error('Unable to remove temporary database credentials file.');
                throw new RuntimeException('Unable to remove the temporary database credentials file.');
            }
        }
    }

    private function restrictPermissions(string $path): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $username = getenv('USERNAME');
            if (! is_string($username) || $username === '') {
                throw new RuntimeException('Unable to identify the Windows account for the temporary credentials file.');
            }

            $domain = getenv('USERDOMAIN');
            $identity = is_string($domain) && $domain !== ''
                ? $domain.'\\'.$username
                : $username;
            $result = Process::run([
                'icacls',
                $path,
                '/inheritance:r',
                '/grant:r',
                $identity.':(R,W)',
            ]);

            if (! $result->successful()) {
                throw new RuntimeException('Unable to restrict temporary database credentials file permissions.');
            }

            return;
        }

        if (! chmod($path, 0600)) {
            throw new RuntimeException('Unable to restrict temporary database credentials file permissions.');
        }
    }

    private function optionValue(string $value): string
    {
        if (str_contains($value, "\n") || str_contains($value, "\r") || str_contains($value, "\0")) {
            throw new RuntimeException('Database connection values cannot contain line breaks.');
        }

        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }
}
