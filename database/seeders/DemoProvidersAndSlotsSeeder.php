<?php

namespace Database\Seeders;

use App\Models\AppointmentType;
use App\Models\Department;
use App\Models\Provider;
use App\Models\ProviderSchedule;
use App\Models\Role;
use App\Models\Specialty;
use App\Models\User;
use App\Services\SchedulingService;
use Illuminate\Database\Seeder;
use RuntimeException;
use Illuminate\Support\Str;

class DemoProvidersAndSlotsSeeder extends Seeder
{
    private const PROVIDERS = [
        ['code' => 'CAR', 'department' => 'Cardiology', 'specialty' => 'Cardiology', 'name' => 'Dr. Gabriel Lim', 'bio' => 'Provides adult cardiology consultations and ongoing heart-health care.'],
        ['code' => 'PED', 'department' => 'Pediatrics', 'specialty' => 'Pediatrics', 'name' => 'Dr. Sofia Reyes', 'bio' => 'Provides pediatric consultations and preventive health guidance for children.'],
        ['code' => 'ORT', 'department' => 'Orthopedics', 'specialty' => 'Orthopedics', 'name' => 'Dr. Mateo Cruz', 'bio' => 'Provides orthopedic assessment and care for bone and joint conditions.'],
    ];

    public function run(SchedulingService $scheduling): void
    {
        $doctorRole = Role::firstOrCreate(['name' => 'doctor'], ['label' => 'Doctor']);
        AppointmentType::firstOrCreate(['name' => 'Outpatient'], ['default_duration' => 30, 'telehealth' => false]);
        AppointmentType::firstOrCreate(['name' => 'Telehealth'], ['default_duration' => 30, 'telehealth' => true]);

        foreach (self::PROVIDERS as $index => $data) {
            $department = Department::query()->where('code', $data['code'])->first()
                ?? Department::query()->where('name', $data['department'])->first();

            if (! $department) {
                $department = Department::create([
                    'code' => $data['code'],
                    'name' => $data['department'],
                ]);
            }

            if (! $department->active) {
                throw new RuntimeException("The {$department->name} department must be active before demo providers can be seeded.");
            }

            $specialty = Specialty::firstOrCreate(
                ['name' => $data['specialty']],
                ['department_id' => $department->id]
            );
            $email = sprintf('demo-provider-%02d@example.invalid', $index + 1);
            $user = User::firstOrCreate(
                ['email' => $email],
                [
                    'name' => $data['name'],
                    'password' => Str::random(64),
                    'email_verified_at' => null,
                ]
            );
            $user->roles()->syncWithoutDetaching([$doctorRole->id]);

            $provider = Provider::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'department_id' => $department->id,
                    'license_number' => sprintf('DEMO-PROVIDER-%02d', $index + 1),
                    'display_name' => $data['name'],
                    'active' => true,
                    'accepts_virtual' => true,
                ]
            );
            if (blank($provider->bio)) {
                $provider->forceFill(['bio' => $data['bio']])->save();
            }
            $provider->specialties()->syncWithoutDetaching([$specialty->id]);

            foreach (range(1, 5) as $weekday) {
                $schedule = ProviderSchedule::firstOrCreate(
                    ['provider_id' => $provider->id, 'day_of_week' => $weekday],
                    [
                        'start_time' => '09:00:00',
                        'end_time' => '17:00:00',
                        'slot_duration' => 30,
                        'break_start' => '12:00:00',
                        'break_end' => '13:00:00',
                    ]
                );

                $fromDate = now()->addDay()->toDateString();
                $toDate = now()->addDays(30)->toDateString();
                $scheduling->generateSlots($schedule, $fromDate, $toDate);
            }
        }
    }
}
