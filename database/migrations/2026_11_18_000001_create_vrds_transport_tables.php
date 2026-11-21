<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('transport_requests')) {
            Schema::create('transport_requests', function (Blueprint $table): void {
                $table->id();
                $table->string('source_type', 20);
                $table->unsignedBigInteger('source_id');
                $table->string('priority', 12);
                $table->string('pickup_zone', 40);
                $table->string('destination_zone', 40);
                $table->json('needs');
                $table->unsignedSmallInteger('passenger_count')->default(1);
                $table->string('status', 20)->default('REQUESTED');
                $table->string('external_id', 191)->nullable()->unique();
                $table->string('vehicle_ref', 191)->nullable();
                $table->timestamp('eta_at')->nullable();
                $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('status_updated_at')->nullable();
                $table->string('cancelled_reason', 500)->nullable();
                $table->timestamps();
                $table->index(['source_type', 'source_id']);
                $table->index(['status', 'created_at']);
            });
        }

        if (! Schema::hasTable('transport_request_histories')) {
            Schema::create('transport_request_histories', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('transport_request_id')->constrained()->cascadeOnDelete();
                $table->string('from_status', 20)->nullable();
                $table->string('to_status', 20);
                $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('change_source', 20);
                $table->json('metadata')->nullable();
                $table->timestamp('changed_at');
                $table->timestamps();
                $table->index(['transport_request_id', 'changed_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('transport_request_histories');
        Schema::dropIfExists('transport_requests');
    }
};
