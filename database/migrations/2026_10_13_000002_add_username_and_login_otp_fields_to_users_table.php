<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('username')->nullable()->unique();
            $table->string('login_otp_hash')->nullable();
            $table->string('login_otp_challenge_hash', 64)->nullable()->index();
            $table->timestamp('login_otp_expires_at')->nullable();
            $table->unsignedTinyInteger('login_otp_attempts')->default(0);
            $table->unsignedTinyInteger('login_otp_resends')->default(0);
            $table->timestamp('login_otp_sent_at')->nullable();
        });

        DB::table('users')->select(['id', 'email'])->orderBy('id')->chunkById(100, function ($users): void {
            foreach ($users as $user) {
                $prefix = strtolower((string) strstr($user->email, '@', true));
                $base = trim((string) preg_replace('/[^a-z0-9._-]+/', '', $prefix), '._-');
                $base = $base !== '' ? substr($base, 0, 180) : 'user';
                $candidate = $base;
                $suffix = 1;

                while (DB::table('users')->where('username', $candidate)->exists()) {
                    $tail = (string) $suffix++;
                    $candidate = substr($base, 0, 190 - strlen($tail)).$tail;
                }

                DB::table('users')->where('id', $user->id)->update(['username' => $candidate]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['username']);
            $table->dropColumn([
                'username',
                'login_otp_hash',
                'login_otp_challenge_hash',
                'login_otp_expires_at',
                'login_otp_attempts',
                'login_otp_resends',
                'login_otp_sent_at',
            ]);
        });
    }
};
