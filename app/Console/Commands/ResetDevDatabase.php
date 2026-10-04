<?php

namespace App\Console\Commands;

use Database\Seeders\DemoDataSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class ResetDevDatabase extends Command
{
    protected $signature = 'hims:reset-dev {--with-demo : Seed demo users and sample workflow data}';

    protected $description = 'Rebuild the dedicated hims_dev database and seed reference data';

    public function handle(): int
    {
        $driver = config('database.default');
        $database = config("database.connections.{$driver}.database");
        if (! $this->isSafeTarget(app()->environment(), $driver, $database)) {
            $this->error('Refusing reset: this command only runs outside production on the hims_dev database.');

            return self::FAILURE;
        }

        if (Artisan::call('migrate:fresh', ['--force' => true]) !== self::SUCCESS) {
            $this->error('Database migration reset failed.');

            return self::FAILURE;
        }

        $seeders = [ReferenceDataSeeder::class];
        if ($this->option('with-demo')) {
            $seeders[] = DemoDataSeeder::class;
        }

        foreach ($seeders as $seeder) {
            if (Artisan::call('db:seed', ['--class' => $seeder, '--force' => true]) !== self::SUCCESS) {
                $this->error("Seeder failed: {$seeder}");

                return self::FAILURE;
            }
        }

        $this->info($this->option('with-demo')
            ? 'Development database reset with reference and demo data.'
            : 'Development database reset with reference data only.');

        return self::SUCCESS;
    }

    public function isSafeTarget(string $environment, mixed $driver, mixed $database): bool
    {
        return $environment !== 'production'
            && in_array($driver, ['mysql', 'mariadb'], true)
            && $database === 'hims_dev';
    }
}
