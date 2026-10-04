<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('triage_assessments', function (Blueprint $table): void {
            $table->unsignedTinyInteger('gcs')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('triage_assessments', function (Blueprint $table): void {
            $table->dropColumn('gcs');
        });
    }
};
