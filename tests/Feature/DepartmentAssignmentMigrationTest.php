<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DepartmentAssignmentMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_department_assignment_migration_can_be_reversed_and_reapplied(): void
    {
        $migration = require database_path('migrations/2026_10_01_000007_create_department_user_table.php');

        $migration->down();
        $this->assertFalse(Schema::hasTable('department_user'));

        $migration->up();
        $this->assertTrue(Schema::hasTable('department_user'));
        $this->assertTrue(Schema::hasColumns('department_user', ['user_id', 'department_id']));
    }
}
