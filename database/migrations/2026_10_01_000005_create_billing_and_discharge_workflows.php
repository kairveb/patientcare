<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prescriptions', function (Blueprint $table): void {
            $table->unsignedInteger('quantity')->default(1)->after('dosage');
            $table->timestamp('dispensed_at')->nullable()->after('prescribed_at');
            $table->foreignId('dispensed_by')->nullable()->after('dispensed_at')->constrained('users')->nullOnDelete();
            $table->index(['dispensed_at', 'patient_id'], 'prescriptions_dispensed_patient_idx');
        });

        Schema::create('charge_rates', function (Blueprint $table): void {
            $table->id();
            $table->string('charge_code', 120)->unique();
            $table->string('category', 32);
            $table->string('description', 255);
            $table->decimal('unit_amount', 12, 2);
            $table->char('currency', 3)->default('PHP');
            $table->boolean('active')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['category', 'active'], 'charge_rates_category_active_idx');
        });

        Schema::create('encounter_bills', function (Blueprint $table): void {
            $table->id();
            $table->string('statement_number', 40)->unique();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('encounter_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('admission_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('status', 16)->default('UNPAID');
            $table->char('currency', 3)->default('PHP');
            $table->decimal('subtotal', 12, 2);
            $table->decimal('total', 12, 2);
            $table->string('source_fingerprint', 64);
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at');
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_reference', 120)->nullable()->unique();
            $table->decimal('amount_paid', 12, 2)->nullable();
            $table->timestamps();
            $table->index(['patient_id', 'status', 'generated_at'], 'encounter_bills_patient_status_generated_idx');
        });

        Schema::create('bill_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('encounter_bill_id')->constrained('encounter_bills')->cascadeOnDelete();
            $table->foreignId('charge_rate_id')->nullable()->constrained('charge_rates')->nullOnDelete();
            $table->foreignId('encounter_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('prescription_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('admission_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('source_type', 32);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('description', 255);
            $table->decimal('quantity', 10, 2);
            $table->decimal('unit_amount', 12, 2);
            $table->decimal('line_total', 12, 2);
            $table->char('currency', 3)->default('PHP');
            $table->json('rate_snapshot')->nullable();
            $table->timestamps();
            $table->unique(['encounter_bill_id', 'source_type', 'source_id'], 'bill_items_source_unique');
            $table->index(['encounter_id', 'prescription_id', 'admission_id'], 'bill_items_clinical_sources_idx');
        });

        Schema::create('discharge_summaries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admission_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('encounter_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('er_visit_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('attending_provider_id')->constrained('providers')->restrictOnDelete();
            $table->foreignId('authored_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('status', 16)->default('DRAFT');
            $table->text('final_diagnosis');
            $table->longText('clinical_course');
            $table->longText('discharge_instructions');
            $table->text('follow_up_plan')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->index(['attending_provider_id', 'status', 'approved_at'], 'discharge_summaries_attending_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discharge_summaries');
        Schema::dropIfExists('bill_items');
        Schema::dropIfExists('encounter_bills');
        Schema::dropIfExists('charge_rates');

        Schema::table('prescriptions', function (Blueprint $table): void {
            $table->dropIndex('prescriptions_dispensed_patient_idx');
            $table->dropConstrainedForeignId('dispensed_by');
            $table->dropColumn(['dispensed_at', 'quantity']);
        });
    }
};
