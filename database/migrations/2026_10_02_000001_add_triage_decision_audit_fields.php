<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('triage_assessments', function (Blueprint $table): void {
            $table->string('ai_suggested_level')->nullable();
            $table->unsignedTinyInteger('ai_confidence')->nullable();
            $table->json('ai_reasons')->nullable();
            $table->string('final_level')->nullable();
            $table->boolean('is_overridden')->default(false);
            $table->text('override_reason')->nullable();
            $table->foreignId('overridden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('overridden_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('triage_assessments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('overridden_by');
            $table->dropColumn([
                'ai_suggested_level',
                'ai_confidence',
                'ai_reasons',
                'final_level',
                'is_overridden',
                'override_reason',
                'overridden_at',
            ]);
        });
    }
};
