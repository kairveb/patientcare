<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            $table->string('religion', 100)->nullable();
            $table->string('occupation', 150)->nullable();
            $table->longText('philhealth_number')->nullable();
            $table->longText('senior_id_number')->nullable();
            $table->longText('pwd_id_number')->nullable();
            $table->string('hmo_name', 150)->nullable();
            $table->longText('hmo_member_id')->nullable();
            $table->string('guarantor_name', 150)->nullable();
            $table->string('guarantor_relationship', 100)->nullable();
            $table->string('guarantor_contact', 30)->nullable();

            foreach (['philhealth', 'senior_id', 'pwd_id', 'hmo_member'] as $identifier) {
                $table->foreignId($identifier.'_verified_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp($identifier.'_verified_at')->nullable();
            }
        });

        Schema::table('clinical_documents', function (Blueprint $table): void {
            $table->string('identifier_type', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('clinical_documents', function (Blueprint $table): void {
            $table->dropColumn('identifier_type');
        });

        Schema::table('patients', function (Blueprint $table): void {
            foreach (['philhealth', 'senior_id', 'pwd_id', 'hmo_member'] as $identifier) {
                $table->dropConstrainedForeignId($identifier.'_verified_by');
            }

            $table->dropColumn([
                'religion',
                'occupation',
                'philhealth_number',
                'senior_id_number',
                'pwd_id_number',
                'hmo_name',
                'hmo_member_id',
                'guarantor_name',
                'guarantor_relationship',
                'guarantor_contact',
                'philhealth_verified_at',
                'senior_id_verified_at',
                'pwd_id_verified_at',
                'hmo_member_verified_at',
            ]);
        });
    }
};
