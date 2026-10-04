<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prescriptions', function (Blueprint $table) {
            $table->foreignId('telehealth_session_id')->nullable()->change();
            $table->foreignId('encounter_id')->nullable()->after('telehealth_session_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('prescriptions')->whereNull('telehealth_session_id')->exists()) {
            throw new RuntimeException('Cannot restore the required telehealth session link while encounter prescriptions exist.');
        }

        Schema::table('prescriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('encounter_id');
            $table->foreignId('telehealth_session_id')->nullable(false)->change();
        });
    }
};
