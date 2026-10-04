<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('symptom_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->longText('symptoms');
            $table->unsignedTinyInteger('age');
            $table->longText('vitals')->nullable();
            $table->string('urgency_level');
            $table->unsignedTinyInteger('urgency_score');
            $table->unsignedTinyInteger('ai_confidence')->nullable();
            $table->longText('ai_reasons')->nullable();
            $table->longText('red_flags')->nullable();
            $table->string('recommended_department');
            $table->string('recommended_consult_type');
            $table->boolean('requires_er')->default(false);
            $table->timestamps();
            $table->index(['patient_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('symptom_checks');
    }
};
