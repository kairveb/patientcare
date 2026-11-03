<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('care_request_reference_sequences', function (Blueprint $table): void {
            $table->date('reference_date')->primary();
            $table->unsignedInteger('next_number')->default(0);
            $table->timestamps();
        });

        Schema::create('care_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('encounter_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('type', 20);
            $table->longText('description');
            $table->string('urgency', 20);
            $table->string('status', 20)->default('REQUESTED');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->longText('remarks')->nullable();
            $table->string('approval_reference')->nullable()->unique();
            $table->longText('result_text')->nullable();
            $table->foreignId('clinical_document_id')->nullable()->constrained('clinical_documents')->nullOnDelete();
            $table->timestamps();
            $table->index(['patient_id', 'status', 'created_at']);
            $table->index(['status', 'type', 'urgency', 'created_at']);
        });

        Schema::create('care_request_status_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('care_request_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->longText('remarks')->nullable();
            $table->timestamps();
            $table->index(['care_request_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('care_request_status_histories');
        Schema::dropIfExists('care_requests');
        Schema::dropIfExists('care_request_reference_sequences');
    }
};
