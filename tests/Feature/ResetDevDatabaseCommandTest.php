<?php

namespace Tests\Feature;

use App\Console\Commands\ResetDevDatabase;
use Tests\TestCase;

class ResetDevDatabaseCommandTest extends TestCase
{
    public function test_reset_command_refuses_sqlite_without_attempting_a_reset(): void
    {
        $this->artisan('hims:reset-dev')
            ->expectsOutput('Refusing reset: this command only runs outside production on the hims_dev database.')
            ->assertFailed();
    }

    public function test_reset_command_refuses_a_non_development_database(): void
    {
        $command = app(ResetDevDatabase::class);

        $this->assertFalse($command->isSafeTarget('local', 'mysql', 'coor'));
        $this->assertFalse($command->isSafeTarget('local', 'sqlite', 'hims_dev'));
    }

    public function test_reset_command_refuses_production_even_when_database_name_matches(): void
    {
        $command = app(ResetDevDatabase::class);

        $this->assertFalse($command->isSafeTarget('production', 'mysql', 'hims_dev'));
        $this->assertTrue($command->isSafeTarget('local', 'mysql', 'hims_dev'));
    }
}
