<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('triage_reassessments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('er_visit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('triage_assessment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recorded_by')->constrained('users');
            $table->timestamp('recorded_at');
            $table->longText('vitals');
            $table->string('ai_suggested_level');
            $table->unsignedTinyInteger('ai_confidence')->nullable();
            $table->longText('ai_reasons');
            $table->string('current_final_level');
            $table->boolean('more_severe')->default(false);
            $table->string('status')->default('PENDING');
            $table->string('final_level')->nullable();
            $table->text('override_reason')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->index(['er_visit_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('triage_reassessments');
    }
};
