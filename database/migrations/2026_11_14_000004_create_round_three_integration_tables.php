<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'account_status_source')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('account_status_source', 16)->default('local');
            });
        }
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'sas_account_status')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('sas_account_status', 16)->nullable();
            });
        }

        if (Schema::hasTable('provider_schedules')
            && ! Schema::hasColumn('provider_schedules', 'integration_partner_id')) {
            Schema::table('provider_schedules', function (Blueprint $table): void {
                $table->foreignId('integration_partner_id')->nullable()
                    ->constrained('integration_partners')->nullOnDelete();
            });
        }

        if (Schema::hasTable('integration_disclosures')
            && Schema::hasColumn('integration_disclosures', 'patient_id')) {
            Schema::table('integration_disclosures', function (Blueprint $table): void {
                $table->foreignId('patient_id')->nullable()->change();
            });
        }

        if (! Schema::hasTable('integration_provider_versions')) {
            Schema::create('integration_provider_versions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('integration_partner_id')->constrained('integration_partners')->cascadeOnDelete();
                $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('source_version');
                $table->timestamps();
                $table->unique(['integration_partner_id', 'provider_id'], 'integration_provider_version_unique');
            });
        }

        if (! Schema::hasTable('integration_exports')) {
            Schema::create('integration_exports', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('integration_partner_id')->constrained('integration_partners')->restrictOnDelete();
                $table->string('export_ref', 64)->unique();
                $table->string('dataset', 40);
                $table->date('period_from');
                $table->date('period_to');
                $table->string('format', 8);
                $table->string('status', 16)->default('QUEUED');
                $table->unsignedBigInteger('row_count')->nullable();
                $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('expires_at')->nullable();
                $table->json('payload_snapshot')->nullable();
                $table->timestamps();
                $table->index(['integration_partner_id', 'status', 'created_at'], 'integration_exports_status_idx');
            });
        }

    }

    public function down(): void
    {
        Schema::dropIfExists('integration_exports');
        Schema::dropIfExists('integration_provider_versions');

        if (Schema::hasTable('users')) {
            $columns = array_values(array_filter(
                ['account_status_source', 'sas_account_status'],
                fn (string $column): bool => Schema::hasColumn('users', $column)
            ));
            if ($columns !== []) {
                Schema::table('users', fn (Blueprint $table) => $table->dropColumn($columns));
            }
        }
        if (Schema::hasTable('provider_schedules')
            && Schema::hasColumn('provider_schedules', 'integration_partner_id')) {
            Schema::table('provider_schedules', fn (Blueprint $table) => $table->dropConstrainedForeignId('integration_partner_id'));
        }

    }
};
