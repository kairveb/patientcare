<?php

use App\Models\Appointment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropUnique('appointments_provider_id_starts_at_unique');
            $table->string('active_slot_key')->nullable();
        });

        DB::table('appointments')
            ->whereNotIn('status', [Appointment::STATUS_CANCELLED, Appointment::STATUS_NO_SHOW])
            ->orderBy('id')
            ->chunkById(500, function ($appointments): void {
                foreach ($appointments as $appointment) {
                    DB::table('appointments')
                        ->where('id', $appointment->id)
                        ->update([
                            'active_slot_key' => Appointment::makeActiveSlotKey(
                                (int) $appointment->provider_id,
                                (string) $appointment->starts_at
                            ),
                        ]);
                }
            });

        Schema::table('appointments', function (Blueprint $table): void {
            $table->unique('active_slot_key', 'appointments_active_slot_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropUnique('appointments_active_slot_key_unique');
            $table->dropColumn('active_slot_key');
            $table->unique(['provider_id', 'starts_at']);
        });
    }
};
