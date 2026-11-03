<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telehealth_sessions', function (Blueprint $table): void {
            $table->text('meeting_passcode')->nullable();
        });

        Schema::table('telehealth_participants', function (Blueprint $table): void {
            $table->timestamp('left_at')->nullable();
            $table->timestamp('privacy_acknowledged_at')->nullable();
        });

        Schema::create('telehealth_join_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('telehealth_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
            $table->index(['telehealth_session_id', 'user_id', 'expires_at'], 'telehealth_join_token_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telehealth_join_tokens');
        Schema::table('telehealth_participants', function (Blueprint $table): void {
            $table->dropColumn(['left_at', 'privacy_acknowledged_at']);
        });
        Schema::table('telehealth_sessions', function (Blueprint $table): void {
            $table->dropColumn('meeting_passcode');
        });
    }
};
