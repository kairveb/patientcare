<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patient_addresses', function (Blueprint $table): void {
            $table->string('region')->nullable();
            $table->string('submunicipality')->nullable();
            $table->string('region_psgc_code', 10)->nullable()->index();
            $table->string('province_psgc_code', 10)->nullable()->index();
            $table->string('city_psgc_code', 10)->nullable()->index();
            $table->string('submunicipality_psgc_code', 10)->nullable()->index();
            $table->string('barangay_psgc_code', 10)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('patient_addresses', function (Blueprint $table): void {
            $table->dropIndex(['region_psgc_code']);
            $table->dropIndex(['province_psgc_code']);
            $table->dropIndex(['city_psgc_code']);
            $table->dropIndex(['submunicipality_psgc_code']);
            $table->dropIndex(['barangay_psgc_code']);
            $table->dropColumn([
                'region',
                'submunicipality',
                'region_psgc_code',
                'province_psgc_code',
                'city_psgc_code',
                'submunicipality_psgc_code',
                'barangay_psgc_code',
            ]);
        });
    }
};
