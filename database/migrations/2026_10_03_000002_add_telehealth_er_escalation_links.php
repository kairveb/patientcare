<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('er_visits', function (Blueprint $table): void {
            $table->foreignId('telehealth_session_id')
                ->nullable()
                ->unique()
                ->constrained('telehealth_sessions')
                ->nullOnDelete();
        });

        Schema::table('telehealth_sessions', function (Blueprint $table): void {
            $table->timestamp('escalated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('telehealth_sessions', function (Blueprint $table): void {
            $table->dropColumn('escalated_at');
        });

        Schema::table('er_visits', function (Blueprint $table): void {
            $table->dropUnique(['telehealth_session_id']);
            $table->dropConstrainedForeignId('telehealth_session_id');
        });
    }
};
