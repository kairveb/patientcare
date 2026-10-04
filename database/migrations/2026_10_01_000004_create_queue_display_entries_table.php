<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queue_display_entries', function (Blueprint $table) {
            $table->id();
            $table->string('service', 16);
            $table->string('status', 16)->default('WAITING');
            $table->foreignId('appointment_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('prescription_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['service', 'created_at', 'status'], 'queue_display_service_created_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_display_entries');
    }
};
