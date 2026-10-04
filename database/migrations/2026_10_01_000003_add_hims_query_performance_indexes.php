<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $indexes = [
        'appointments' => [
            'appointments_provider_status_created_idx' => ['provider_id', 'status', 'created_at'],
        ],
        'encounters' => [
            'encounters_provider_status_created_idx' => ['provider_id', 'status', 'created_at'],
            'encounters_patient_started_idx' => ['patient_id', 'started_at'],
        ],
        'telehealth_sessions' => [
            'telehealth_status_start_idx' => ['status', 'start_time'],
        ],
        'triage_assessments' => [
            'triage_patient_triaged_idx' => ['patient_id', 'triaged_at'],
            'triage_priority_triaged_idx' => ['priority', 'triaged_at'],
        ],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $tableName => $indexes) {
            Schema::table($tableName, function (Blueprint $table) use ($indexes): void {
                foreach ($indexes as $name => $columns) {
                    $table->index($columns, $name);
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $tableName => $indexes) {
            Schema::table($tableName, function (Blueprint $table) use ($indexes): void {
                foreach (array_keys($indexes) as $name) {
                    $table->dropIndex($name);
                }
            });
        }
    }
};
