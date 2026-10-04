<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            $table->foreignId('merged_into_patient_id')->nullable()->after('user_id')->constrained('patients')->restrictOnDelete();
            $table->foreignId('merged_by')->nullable()->after('merged_into_patient_id')->constrained('users')->nullOnDelete();
            $table->timestamp('merged_at')->nullable()->after('merged_by');
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            $table->dropForeign(['merged_into_patient_id']);
            $table->dropForeign(['merged_by']);
            $table->dropColumn(['merged_into_patient_id', 'merged_by', 'merged_at']);
        });
    }
};
