<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $columns = [
            'integration_external_id' => fn (Blueprint $table) => $table->string('integration_external_id')->nullable()->unique(),
            'integration_status' => fn (Blueprint $table) => $table->string('integration_status', 20)->nullable(),
        ];

        foreach (['encounter_bills', 'billing_clearance_requests', 'admissions', 'care_requests'] as $name) {
            if (! Schema::hasTable($name)) {
                continue;
            }
            Schema::table($name, function (Blueprint $table) use ($name, $columns): void {
                foreach ($columns as $column => $add) {
                    if (! Schema::hasColumn($name, $column)) {
                        $add($table);
                    }
                }
            });
        }

        foreach (['admissions', 'care_requests'] as $name) {
            if (! Schema::hasTable($name)) {
                continue;
            }
            Schema::table($name, function (Blueprint $table) use ($name): void {
                foreach ([
                    'coverage_code' => fn () => $table->string('coverage_code', 80)->nullable(),
                    'loa_status' => fn () => $table->string('loa_status', 12)->nullable(),
                    'loa_requested_amount' => fn () => $table->decimal('loa_requested_amount', 12, 2)->nullable(),
                    'loa_approved_amount' => fn () => $table->decimal('loa_approved_amount', 12, 2)->nullable(),
                    'loa_decided_at' => fn () => $table->timestamp('loa_decided_at')->nullable(),
                    'loa_payer_reference' => fn () => $table->string('loa_payer_reference', 120)->nullable(),
                ] as $column => $add) {
                    if (! Schema::hasColumn($name, $column)) {
                        $add();
                    }
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['encounter_bills', 'billing_clearance_requests', 'admissions', 'care_requests'] as $name) {
            if (! Schema::hasTable($name)) {
                continue;
            }
            $columns = array_values(array_filter(
                ['integration_external_id', 'integration_status'],
                fn (string $column): bool => Schema::hasColumn($name, $column)
            ));
            if ($columns !== []) {
                Schema::table($name, fn (Blueprint $table) => $table->dropColumn($columns));
            }
        }
        foreach (['admissions', 'care_requests'] as $name) {
            if (! Schema::hasTable($name)) {
                continue;
            }
            $columns = array_values(array_filter(
                ['coverage_code', 'loa_status', 'loa_requested_amount', 'loa_approved_amount', 'loa_decided_at', 'loa_payer_reference'],
                fn (string $column): bool => Schema::hasColumn($name, $column)
            ));
            if ($columns !== []) {
                Schema::table($name, fn (Blueprint $table) => $table->dropColumn($columns));
            }
        }
    }
};
