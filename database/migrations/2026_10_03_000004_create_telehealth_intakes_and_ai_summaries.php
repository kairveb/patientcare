<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telehealth_intakes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('telehealth_session_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->longText('symptoms');
            $table->string('duration');
            $table->unsignedTinyInteger('severity');
            $table->longText('allergies')->nullable();
            $table->longText('current_medications')->nullable();
            $table->longText('vitals')->nullable();
            $table->string('ai_urgency');
            $table->longText('ai_reasons');
            $table->boolean('is_emergency')->default(false);
            $table->timestamps();
        });

        Schema::create('telehealth_ai_summaries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('telehealth_session_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('encounter_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('generated_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->longText('doctor_notes');
            $table->longText('summary_text');
            $table->string('status')->default('DRAFT');
            $table->timestamp('generated_at');
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telehealth_ai_summaries');
        Schema::dropIfExists('telehealth_intakes');
    }
};
