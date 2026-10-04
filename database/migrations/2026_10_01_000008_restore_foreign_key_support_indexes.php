<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEXES = [
        'encounters' => [
            'encounters_provider_id_fk_index' => ['provider_id'],
            'encounters_patient_id_fk_index' => ['patient_id'],
        ],
        'triage_assessments' => [
            'triage_assessments_patient_id_fk_index' => ['patient_id'],
        ],
    ];

    public function up(): void
    {
        if (! in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach (self::INDEXES as $tableName => $indexes) {
            foreach ($indexes as $name => $columns) {
                if (! Schema::hasIndex($tableName, $columns)) {
                    Schema::table($tableName, function (Blueprint $table) use ($name, $columns): void {
                        $table->index($columns, $name);
                    });
                }
            }
        }
    }

    public function down(): void
    {
        // Keep these indexes: they restore the MySQL foreign-key indexes removed as redundant by migration 000003.
    }
};
