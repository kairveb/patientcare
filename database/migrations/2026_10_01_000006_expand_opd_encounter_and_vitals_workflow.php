<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('encounters', function (Blueprint $table): void {
            $table->longText('history_of_present_illness')->nullable();
            $table->longText('physical_examination')->nullable();
            $table->string('primary_diagnosis_code', 20)->nullable()->index();
            $table->string('primary_diagnosis_description', 255)->nullable();
        });

        Schema::table('vitals', function (Blueprint $table): void {
            $table->foreignId('appointment_id')->nullable()->unique()->after('encounter_id')
                ->constrained()->nullOnDelete();
            $table->decimal('height_cm', 5, 2)->nullable()->after('weight');
            $table->decimal('bmi', 5, 2)->nullable()->after('height_cm');
        });

        Schema::table('admissions', function (Blueprint $table): void {
            $table->foreignId('encounter_id')->nullable()->unique()->after('er_visit_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('admissions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('encounter_id');
        });

        Schema::table('vitals', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('appointment_id');
            $table->dropColumn(['height_cm', 'bmi']);
        });

        Schema::table('encounters', function (Blueprint $table): void {
            $table->dropIndex(['primary_diagnosis_code']);
            $table->dropColumn([
                'history_of_present_illness',
                'physical_examination',
                'primary_diagnosis_code',
                'primary_diagnosis_description',
            ]);
        });
    }
};
