<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('care_requests')) {
            Schema::table('care_requests', function (Blueprint $table): void {
                foreach ([
                    'external_id' => fn () => $table->string('external_id')->nullable(),
                    'external_partner_code' => fn () => $table->string('external_partner_code', 40)->nullable(),
                    'partner_status' => fn () => $table->string('partner_status', 24)->nullable(),
                    'partner_status_updated_at' => fn () => $table->timestamp('partner_status_updated_at')->nullable(),
                    'accession_reference' => fn () => $table->string('accession_reference', 191)->nullable(),
                    'result_reference' => fn () => $table->string('result_reference', 191)->nullable(),
                ] as $column => $add) {
                    if (! Schema::hasColumn('care_requests', $column)) {
                        $add();
                    }
                }
                if (! Schema::hasIndex('care_requests', 'care_request_partner_external_unique')) {
                    $table->unique(['external_partner_code', 'external_id'], 'care_request_partner_external_unique');
                }
            });
        }

        if (Schema::hasTable('prescriptions')) {
            Schema::table('prescriptions', function (Blueprint $table): void {
                foreach ([
                    'external_id' => fn () => $table->string('external_id')->nullable()->unique(),
                    'partner_status' => fn () => $table->string('partner_status', 24)->nullable(),
                    'partner_status_updated_at' => fn () => $table->timestamp('partner_status_updated_at')->nullable(),
                    'dispensed_at' => fn () => $table->timestamp('dispensed_at')->nullable(),
                ] as $column => $add) {
                    if (! Schema::hasColumn('prescriptions', $column)) {
                        $add();
                    }
                }
            });
        }

        if (! Schema::hasTable('integration_reference_maps')) {
            Schema::create('integration_reference_maps', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('integration_partner_id')->constrained('integration_partners')->cascadeOnDelete();
                $table->string('resource_type', 40);
                $table->unsignedBigInteger('resource_id');
                $table->string('emr_reference', 64)->unique();
                $table->timestamps();
                $table->unique(['integration_partner_id', 'resource_type', 'resource_id'], 'integration_reference_resource_unique');
                $table->unique(['integration_partner_id', 'emr_reference'], 'integration_reference_partner_unique');
            });
        }

        if (! Schema::hasTable('integration_clinical_documents')) {
            Schema::create('integration_clinical_documents', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('integration_partner_id')->constrained('integration_partners')->restrictOnDelete();
                $table->foreignId('encounter_id')->constrained()->restrictOnDelete();
                $table->string('document_reference', 191);
                $table->string('status', 24);
                $table->timestamps();
                $table->unique(['integration_partner_id', 'document_reference'], 'integration_clinical_document_partner_ref_unique');
                $table->index(['encounter_id', 'integration_partner_id'], 'integration_clinical_document_encounter_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_clinical_documents');
        Schema::dropIfExists('integration_reference_maps');

        foreach (['care_requests', 'prescriptions'] as $name) {
            if (! Schema::hasTable($name)) {
                continue;
            }
            $columns = $name === 'care_requests'
                ? ['external_id', 'external_partner_code', 'partner_status', 'partner_status_updated_at', 'accession_reference', 'result_reference']
                : ['external_id', 'partner_status', 'partner_status_updated_at'];
            $columns = array_values(array_filter($columns, fn (string $column): bool => Schema::hasColumn($name, $column)));
            if ($columns !== []) {
                Schema::table($name, fn (Blueprint $table) => $table->dropColumn($columns));
            }
        }
    }
};
