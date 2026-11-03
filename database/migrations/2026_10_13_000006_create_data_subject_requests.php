<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_subject_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->string('type', 40);
            $table->text('note');
            $table->string('status', 20)->default('SUBMITTED');
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('response_note')->nullable();
            $table->timestamps();
            $table->index(['patient_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('data_subject_request_status_histories', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('data_subject_request_id');
            $table->foreign('data_subject_request_id', 'dsrh_request_id_fk')
                ->references('id')->on('data_subject_requests')->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['data_subject_request_id', 'created_at'], 'dsr_history_request_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_subject_request_status_histories');
        Schema::dropIfExists('data_subject_requests');
    }
};
