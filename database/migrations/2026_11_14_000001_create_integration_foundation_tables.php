<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('integration_partners')) {
            Schema::create('integration_partners', function (Blueprint $table): void {
                $table->id();
                $table->string('code', 40)->unique();
                $table->string('name');
                $table->boolean('active')->default(false);
                $table->string('api_key_hash')->nullable();
                $table->text('webhook_signing_secret')->nullable();
                $table->json('allowed_abilities')->nullable();
                $table->string('mode', 10)->default('mock');
                $table->string('base_url')->nullable();
                $table->text('outbound_token')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('integration_events')) {
            Schema::create('integration_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('integration_partner_id')->constrained('integration_partners')->cascadeOnDelete();
                $table->string('event_id', 191);
                $table->unsignedSmallInteger('response_status')->nullable();
                $table->json('response_body')->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->timestamps();
                $table->unique(['integration_partner_id', 'event_id']);
            });
        }

        if (! Schema::hasTable('integration_outbox')) {
            Schema::create('integration_outbox', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('integration_partner_id')->constrained('integration_partners')->cascadeOnDelete();
                $table->string('entity_type', 80);
                $table->unsignedBigInteger('entity_id');
                $table->json('payload_reference');
                $table->string('status', 12)->default('PENDING');
                $table->unsignedSmallInteger('attempts')->default(0);
                $table->timestamp('next_retry_at')->nullable();
                $table->text('last_error')->nullable();
                $table->string('idempotency_key', 100)->unique();
                $table->timestamps();
                $table->index(['status', 'next_retry_at']);
                $table->index(['integration_partner_id', 'entity_type', 'entity_id']);
            });
        }

        if (! Schema::hasTable('integration_disclosures')) {
            Schema::create('integration_disclosures', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('integration_partner_id')->constrained('integration_partners')->restrictOnDelete();
                $table->foreignId('patient_id')->constrained()->restrictOnDelete();
                $table->string('purpose', 100);
                $table->json('fields_shared');
                $table->string('request_id', 80);
                $table->timestamp('disclosed_at');
                $table->timestamps();
                $table->unique(['integration_partner_id', 'request_id']);
            });
        }

        if (Schema::hasTable('integration_logs')) {
            Schema::table('integration_logs', function (Blueprint $table): void {
                if (! Schema::hasColumn('integration_logs', 'request_id')) {
                    $table->string('request_id', 80)->nullable()->index();
                }
                if (! Schema::hasColumn('integration_logs', 'integration_partner_id')) {
                    $table->foreignId('integration_partner_id')->nullable()->constrained('integration_partners')->nullOnDelete();
                }
                if (! Schema::hasColumn('integration_logs', 'status_code')) {
                    $table->unsignedSmallInteger('status_code')->nullable();
                }
            });
        }

        if (Schema::hasTable('api_logs')) {
            Schema::table('api_logs', function (Blueprint $table): void {
                if (! Schema::hasColumn('api_logs', 'request_id')) {
                    $table->string('request_id', 80)->nullable()->index();
                }
                if (! Schema::hasColumn('api_logs', 'integration_partner_id')) {
                    $table->foreignId('integration_partner_id')->nullable()->constrained('integration_partners')->nullOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('api_logs') && Schema::hasColumn('api_logs', 'integration_partner_id')) {
            Schema::table('api_logs', fn (Blueprint $table) => $table->dropConstrainedForeignId('integration_partner_id'));
            Schema::table('api_logs', fn (Blueprint $table) => $table->dropColumn('request_id'));
        }
        if (Schema::hasTable('integration_logs') && Schema::hasColumn('integration_logs', 'integration_partner_id')) {
            Schema::table('integration_logs', fn (Blueprint $table) => $table->dropConstrainedForeignId('integration_partner_id'));
            Schema::table('integration_logs', fn (Blueprint $table) => $table->dropColumn(['request_id', 'status_code']));
        }
        Schema::dropIfExists('integration_disclosures');
        Schema::dropIfExists('integration_outbox');
        Schema::dropIfExists('integration_events');
        Schema::dropIfExists('integration_partners');
    }
};
