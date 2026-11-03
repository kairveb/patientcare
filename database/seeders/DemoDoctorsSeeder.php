<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Provider;
use App\Models\ProviderSchedule;
use App\Models\Role;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoDoctorsSeeder extends Seeder
{
    private const DEMO_PASSWORD = 'DemoDoctors!234';

    private const DOCTORS = [
        ['email' => 'doctor1@example.test', 'name' => 'Dr. Demo Juan Reyes', 'department' => 'CAR', 'specialty' => 'Cardiology'],
        ['email' => 'doctor2@example.test', 'name' => 'Dr. Demo Maria Cruz', 'department' => 'PED', 'specialty' => 'Pediatrics'],
        ['email' => 'doctor3@example.test', 'name' => 'Dr. Demo Pedro Santos', 'department' => 'ORT', 'specialty' => 'Orthopedics'],
        ['email' => 'doctor4@example.test', 'name' => 'Dr. Demo Ana Dela Cruz', 'department' => 'GEN', 'specialty' => 'Internal Medicine'],
        ['email' => 'doctor5@example.test', 'name' => 'Dr. Demo Jose Garcia', 'department' => 'EMG', 'specialty' => 'Emergency Medicine'],
        ['email' => 'doctor6@example.test', 'name' => 'Dr. Demo Liza Ramos', 'department' => 'CAR', 'specialty' => 'Cardiology'],
    ];

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new \RuntimeException('Demo doctor seeding is disabled in production.');
        }

        $doctorRole = Role::query()->where('name', 'doctor')->firstOrFail();

        foreach (self::DOCTORS as $index => $doctor) {
            $department = Department::query()->where('code', $doctor['department'])->firstOrFail();
            $specialty = Specialty::query()->where('name', $doctor['specialty'])->firstOrFail();

            $user = User::updateOrCreate(
                ['email' => $doctor['email']],
                [
                    'name' => $doctor['name'],
                    'password' => Hash::make(self::DEMO_PASSWORD),
                    'email_verified_at' => now(),
                    'last_activity_at' => now(),
                ]
            );
            $user->forceFill(['email_verified_at' => now()])->save();
            $user->roles()->syncWithoutDetaching([$doctorRole->id]);

            $provider = Provider::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'department_id' => $department->id,
                    'license_number' => sprintf('DEMO-LIC-%03d', $index + 1),
                    'display_name' => $doctor['name'],
                    'active' => true,
                ]
            );
            $provider->specialties()->syncWithoutDetaching([$specialty->id]);

            foreach (range(1, 5) as $weekday) {
                ProviderSchedule::updateOrCreate(
                    ['provider_id' => $provider->id, 'day_of_week' => $weekday],
                    [
                        'start_time' => '09:00:00',
                        'end_time' => '17:00:00',
                        'slot_duration' => 30,
                        'break_start' => '12:00:00',
                        'break_end' => '13:00:00',
                        'unavailable_date' => null,
                    ]
                );
            }
        }
    }
}
