<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admissions', function (Blueprint $table): void {
            $table->string('guarantor_name', 150)->nullable();
            $table->string('guarantor_relationship', 100)->nullable();
            $table->string('guarantor_contact', 30)->nullable();
            $table->longText('loa_reference')->nullable();
        });

        Schema::create('billing_clearance_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admission_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('discharge_summary_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('REQUESTED');
            $table->timestamp('requested_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_clearance_requests');

        Schema::table('admissions', function (Blueprint $table): void {
            $table->dropColumn([
                'guarantor_name',
                'guarantor_relationship',
                'guarantor_contact',
                'loa_reference',
            ]);
        });
    }
};
