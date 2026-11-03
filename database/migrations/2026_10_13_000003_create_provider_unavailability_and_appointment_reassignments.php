<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_unavailabilities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('reason', 30);
            $table->text('details')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['provider_id', 'starts_at', 'ends_at'], 'provider_unavailable_range_idx');
        });

        Schema::create('appointment_reassignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_unavailability_id')->nullable()->constrained('provider_unavailabilities')->nullOnDelete();
            $table->foreignId('original_provider_id')->constrained('providers')->restrictOnDelete();
            $table->foreignId('suggested_provider_id')->nullable()->constrained('providers')->nullOnDelete();
            $table->foreignId('suggested_slot_id')->nullable()->constrained('appointment_slots')->nullOnDelete();
            $table->dateTime('suggested_starts_at')->nullable();
            $table->foreignId('waitlist_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 30)->default('PENDING_REVIEW');
            $table->string('reason', 30);
            $table->json('next_available_dates')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at'], 'appointment_reassignment_status_idx');
            $table->index(['appointment_id', 'status'], 'appointment_reassignment_appointment_idx');
        });

        Schema::create('appointment_reassignment_histories', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('appointment_reassignment_id');
            $table->foreign('appointment_reassignment_id', 'arh_reassignment_id_fk')
                ->references('id')->on('appointment_reassignments')->cascadeOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained('providers')->nullOnDelete();
            $table->foreignId('appointment_slot_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('starts_at')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_reassignment_histories');
        Schema::dropIfExists('appointment_reassignments');
        Schema::dropIfExists('provider_unavailabilities');
    }
};
