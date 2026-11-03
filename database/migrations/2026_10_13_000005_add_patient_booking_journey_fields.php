<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('providers', function (Blueprint $table): void {
            $table->string('bio', 1000)->nullable();
            $table->unsignedSmallInteger('years_of_practice')->nullable();
            $table->string('languages', 255)->nullable();
            $table->string('photo_path')->nullable();
            $table->boolean('accepts_virtual')->default(false);
        });

        Schema::table('appointments', function (Blueprint $table): void {
            $table->string('booking_source', 20)->default('online')->index();
            $table->foreignId('parent_appointment_id')->nullable()->after('created_by')
                ->constrained('appointments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('parent_appointment_id');
            $table->dropIndex(['booking_source']);
            $table->dropColumn('booking_source');
        });

        Schema::table('providers', function (Blueprint $table): void {
            $table->dropColumn(['bio', 'years_of_practice', 'languages', 'photo_path', 'accepts_virtual']);
        });
    }
};
