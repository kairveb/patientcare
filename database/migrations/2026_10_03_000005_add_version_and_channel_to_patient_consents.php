<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patient_consents', function (Blueprint $table): void {
            $table->string('version', 40)->nullable();
            $table->string('channel', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('patient_consents', function (Blueprint $table): void {
            $table->dropColumn(['version', 'channel']);
        });
    }
};
