<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            $table->string('first_name')->nullable()->change();
            $table->string('last_name')->nullable()->change();
            $table->date('date_of_birth')->nullable()->change();
            $table->string('sex', 20)->nullable()->change();
            $table->boolean('is_temporary_identity')->default(false);
            $table->string('temporary_identity_label')->nullable()->unique();
        });
    }

    public function down(): void
    {
        DB::table('patients')
            ->where('is_temporary_identity', true)
            ->whereNull('first_name')
            ->update(['first_name' => 'Unknown']);

        DB::table('patients')
            ->where('is_temporary_identity', true)
            ->whereNull('last_name')
            ->update(['last_name' => 'Unknown']);

        DB::table('patients')
            ->where('is_temporary_identity', true)
            ->whereNull('date_of_birth')
            ->update(['date_of_birth' => '1900-01-01']);

        DB::table('patients')
            ->where('is_temporary_identity', true)
            ->whereNull('sex')
            ->update(['sex' => 'Other']);

        Schema::table('patients', function (Blueprint $table): void {
            $table->dropUnique(['temporary_identity_label']);
            $table->dropColumn(['is_temporary_identity', 'temporary_identity_label']);
            $table->string('first_name')->nullable(false)->change();
            $table->string('last_name')->nullable(false)->change();
            $table->date('date_of_birth')->nullable(false)->change();
            $table->string('sex', 20)->nullable(false)->change();
        });
    }
};
