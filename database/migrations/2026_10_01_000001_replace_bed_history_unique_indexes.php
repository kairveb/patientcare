<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->replaceUniqueIndex(
            'bed_reservations',
            'bed_reservations_bed_id_status_unique',
            'bed_reservations_bed_id_status_index',
        );
        $this->replaceUniqueIndex(
            'bed_assignments',
            'bed_assignments_bed_id_status_unique',
            'bed_assignments_bed_id_status_index',
        );
    }

    public function down(): void
    {
        $this->restoreUniqueIndex(
            'bed_assignments',
            'bed_assignments_bed_id_status_unique',
            'bed_assignments_bed_id_status_index',
        );
        $this->restoreUniqueIndex(
            'bed_reservations',
            'bed_reservations_bed_id_status_unique',
            'bed_reservations_bed_id_status_index',
        );
    }

    private function replaceUniqueIndex(string $tableName, string $uniqueIndex, string $lookupIndex): void
    {
        if (! Schema::hasIndex($tableName, $lookupIndex)) {
            Schema::table($tableName, function (Blueprint $table) use ($lookupIndex): void {
                $table->index(['bed_id', 'status'], $lookupIndex);
            });
        }

        if (Schema::hasIndex($tableName, $uniqueIndex)) {
            Schema::table($tableName, function (Blueprint $table) use ($uniqueIndex): void {
                $table->dropUnique($uniqueIndex);
            });
        }
    }

    private function restoreUniqueIndex(string $tableName, string $uniqueIndex, string $lookupIndex): void
    {
        if (! Schema::hasIndex($tableName, $uniqueIndex)) {
            $hasDuplicates = DB::table($tableName)
                ->select('bed_id', 'status')
                ->groupBy('bed_id', 'status')
                ->havingRaw('COUNT(*) > 1')
                ->exists();

            if ($hasDuplicates) {
                throw new RuntimeException("Cannot restore {$uniqueIndex} while duplicate bed history rows exist.");
            }

            Schema::table($tableName, function (Blueprint $table) use ($uniqueIndex): void {
                $table->unique(['bed_id', 'status'], $uniqueIndex);
            });
        }

        if (Schema::hasIndex($tableName, $lookupIndex)) {
            Schema::table($tableName, function (Blueprint $table) use ($lookupIndex): void {
                $table->dropIndex($lookupIndex);
            });
        }
    }
};
