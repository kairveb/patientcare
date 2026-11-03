<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('symptom_checks', function (Blueprint $table): void {
            $table->string('ai_provider')->nullable();
            $table->longText('ai_result')->nullable();
            $table->boolean('rule_overrode_ai')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('symptom_checks', function (Blueprint $table): void {
            $table->dropColumn(['rule_overrode_ai', 'ai_result', 'ai_provider']);
        });
    }
};
