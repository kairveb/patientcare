<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('symptom_checks', function (Blueprint $table): void {
            $table->string('clinician_final_urgency', 20)->nullable();
            $table->string('clinician_agreement', 20)->nullable();
            $table->foreignId('clinician_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('clinician_reviewed_at')->nullable();
            $table->index(['clinician_agreement', 'created_at'], 'symptom_check_evaluation_idx');
        });
    }

    public function down(): void
    {
        Schema::table('symptom_checks', function (Blueprint $table): void {
            $table->dropIndex('symptom_check_evaluation_idx');
            $table->dropConstrainedForeignId('clinician_reviewed_by');
            $table->dropColumn([
                'clinician_final_urgency',
                'clinician_agreement',
                'clinician_reviewed_at',
            ]);
        });
    }
};
