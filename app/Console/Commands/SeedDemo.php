<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\AppointmentType;
use App\Models\Bed;
use App\Models\DemoRecord;
use App\Models\Department;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\Provider;
use App\Models\ProviderSchedule;
use App\Models\Role;
use App\Models\Room;
use App\Models\Specialty;
use App\Models\User;
use App\Models\Ward;
use App\Services\SchedulingService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;

class SeedDemo extends Command
{
    protected $signature = 'app:seed-demo {--force : Allow demo seeding in production when DEMO_MODE=true}';

    protected $description = 'Create marked synthetic demo accounts and clinical data';

    private const ACCOUNTS = [
        'registration' => ['name' => 'Demo Registration', 'email' => 'registration@example.com'],
        'doctor' => ['name' => 'Demo Doctor', 'email' => 'doctor@example.com'],
        'nurse' => ['name' => 'Demo Nurse', 'email' => 'nurse@example.com'],
        'patient' => ['name' => 'Demo Patient', 'email' => 'patient@example.com'],
    ];

    public function handle(): int
    {
        if ($this->productionIsUnapproved()) {
            $this->error('Production demo seeding requires both --force and DEMO_MODE=true.');

            return self::FAILURE;
        }

        $roles = Role::query()
            ->whereIn('name', array_keys(self::ACCOUNTS))
            ->get()
            ->keyBy('name');

        if ($roles->count() !== count(self::ACCOUNTS)) {
            $this->error('Required demo roles are missing. Run ReferenceDataSeeder first.');

            return self::FAILURE;
        }

        $appointmentType = AppointmentType::query()->where('name', 'Outpatient')->first();
        if (! $appointmentType) {
            $this->error('The Outpatient appointment type is missing. Run ReferenceDataSeeder first.');

            return self::FAILURE;
        }

        try {
            $accountPlans = $this->prepareAccounts($roles);
            $passwordsToShow = [];

            DB::transaction(function () use ($roles, $accountPlans, &$passwordsToShow, $appointmentType): void {
                $users = $this->seedAccounts($roles, $accountPlans, $passwordsToShow);
                $department = $this->ensureDemoModel(
                    'department:cardiology',
                    Department::class,
                    ['code' => 'DEMO'],
                    ['name' => 'Demo Cardiology', 'active' => true]
                );
                $specialty = Specialty::query()->where('name', 'Cardiology')->first();
                if (! $specialty) {
                    $specialty = $this->ensureDemoModel(
                        'specialty:cardiology',
                        Specialty::class,
                        ['name' => 'Demo Cardiology'],
                        ['department_id' => $department->id]
                    );
                }

                $doctor = $users['doctor'];
                $nurse = $users['nurse'];
                $provider = $this->ensureDemoModel(
                    'provider:doctor',
                    Provider::class,
                    ['user_id' => $doctor->id],
                    [
                        'department_id' => $department->id,
                        'license_number' => null,
                        'display_name' => $doctor->name,
                        'active' => true,
                    ]
                );
                $provider->specialties()->syncWithoutDetaching([$specialty->id]);
                $nurse->departments()->syncWithoutDetaching([$department->id]);

                $schedules = [];
                for ($day = 0; $day <= 6; $day++) {
                    $schedules[] = $this->ensureDemoModel(
                        "schedule:doctor:{$day}",
                        ProviderSchedule::class,
                        ['provider_id' => $provider->id, 'day_of_week' => $day],
                        [
                            'start_time' => '09:00:00',
                            'end_time' => '11:00:00',
                            'slot_duration' => 30,
                        ]
                    );
                }

                $today = now()->startOfDay();
                $lastDay = $today->copy()->addDays(13);
                $this->assertNoUnmarkedSlots($provider, $today, $lastDay);
                foreach ($schedules as $schedule) {
                    app(SchedulingService::class)->generateSlots(
                        $schedule,
                        $today->toDateString(),
                        $lastDay->toDateString()
                    );
                }

                AppointmentSlot::query()
                    ->where('provider_id', $provider->id)
                    ->whereBetween('starts_at', [$today, $lastDay->copy()->endOfDay()])
                    ->orderBy('starts_at')
                    ->get()
                    ->each(function (AppointmentSlot $slot): void {
                        $key = 'slot:doctor:'.$slot->starts_at->format('Y-m-d-His');
                        $this->markExistingOrNew($slot, $key);
                    });

                $patients = [
                    [
                        'key' => 'patient:001',
                        'mrn' => 'DEMO-PAT-001',
                        'first_name' => 'Demo',
                        'last_name' => 'Patient One',
                        'date_of_birth' => '1985-05-12',
                        'email' => $users['patient']->email,
                    ],
                    [
                        'key' => 'patient:002',
                        'mrn' => 'DEMO-PAT-002',
                        'first_name' => 'Sample',
                        'last_name' => 'Person Two',
                        'date_of_birth' => '1992-09-23',
                        'email' => 'patient.two@example.com',
                    ],
                    [
                        'key' => 'patient:003',
                        'mrn' => 'DEMO-PAT-003',
                        'first_name' => 'Example',
                        'last_name' => 'Person Three',
                        'date_of_birth' => '1978-02-08',
                        'email' => 'patient.three@example.com',
                    ],
                ];

                foreach ($patients as $index => $data) {
                    $patients[$index]['model'] = $this->ensureDemoModel(
                        $data['key'],
                        Patient::class,
                        ['mrn' => $data['mrn']],
                        [
                            'user_id' => $index === 0 ? $users['patient']->id : null,
                            'first_name' => $data['first_name'],
                            'last_name' => $data['last_name'],
                            'middle_name' => null,
                            'date_of_birth' => $data['date_of_birth'],
                            'sex' => 'Not specified',
                            'civil_status' => null,
                            'nationality' => null,
                            'phone' => null,
                            'email' => $data['email'],
                            'allergies' => null,
                            'verified' => true,
                        ]
                    );
                }

                $ward = $this->ensureDemoModel(
                    'ward:main',
                    Ward::class,
                    ['code' => 'DEMO'],
                    ['name' => 'Demo Ward', 'type' => 'General', 'active' => true]
                );
                $room = $this->ensureDemoModel(
                    'room:main',
                    Room::class,
                    ['ward_id' => $ward->id, 'number' => 'D-01'],
                    ['type' => 'Standard']
                );
                foreach (['D-A', 'D-B', 'D-C'] as $bedNumber) {
                    $this->ensureDemoModel(
                        'bed:'.$bedNumber,
                        Bed::class,
                        ['room_id' => $room->id, 'number' => $bedNumber],
                        ['status' => 'AVAILABLE']
                    );
                }

                foreach ([
                    ['number' => 'DEMO-APT-001', 'patient' => 0, 'days' => 2, 'time' => '09:00:00'],
                    ['number' => 'DEMO-APT-002', 'patient' => 1, 'days' => 3, 'time' => '09:30:00'],
                ] as $appointmentData) {
                    $startsAt = now()->startOfDay()
                        ->addDays($appointmentData['days'])
                        ->setTimeFromTimeString($appointmentData['time']);
                    $appointment = $this->ensureDemoModel(
                        'appointment:'.$appointmentData['number'],
                        Appointment::class,
                        ['appointment_number' => $appointmentData['number']],
                        [
                            'patient_id' => $patients[$appointmentData['patient']]['model']->id,
                            'provider_id' => $provider->id,
                            'department_id' => $department->id,
                            'appointment_type_id' => $appointmentType->id,
                            'starts_at' => $startsAt,
                            'ends_at' => $startsAt->copy()->addMinutes(30),
                            'status' => Appointment::STATUS_CONFIRMED,
                            'reason' => 'Synthetic demo appointment',
                            'created_by' => $users['registration']->id,
                        ]
                    );

                    AppointmentSlot::query()
                        ->where('provider_id', $provider->id)
                        ->where('starts_at', $appointment->starts_at)
                        ->update(['status' => AppointmentSlot::STATUS_BOOKED]);
                }

                $encounterStartsAt = now()->subHour();
                $this->ensureDemoModel(
                    'encounter:opd:001',
                    Encounter::class,
                    ['encounter_number' => 'DEMO-ENC-001'],
                    [
                        'patient_id' => $patients[2]['model']->id,
                        'provider_id' => $provider->id,
                        'appointment_id' => null,
                        'type' => Encounter::TYPE_OUTPATIENT,
                        'started_at' => $encounterStartsAt,
                        'ended_at' => now()->subMinutes(30),
                        'chief_complaint' => 'Synthetic demo consultation',
                        'assessment' => 'No real patient information',
                        'plan' => 'Demonstration only',
                        'status' => Encounter::STATUS_COMPLETED,
                    ]
                );
            });

            $this->info('Demo data is marked in demo_records and may be removed with app:purge-demo.');
            $this->info('Generated passwords are shown once; save them now. Environment-supplied passwords are never displayed.');
            if ($passwordsToShow !== []) {
                $this->table(['Role', 'Email', 'Generated password'], $passwordsToShow);
            }
            $this->info('Created or reused the four marked demo accounts and synthetic clinical data.');

            return self::SUCCESS;
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function productionIsUnapproved(): bool
    {
        return app()->environment('production')
            && (! $this->option('force')
                || filter_var(Env::get('DEMO_MODE'), FILTER_VALIDATE_BOOLEAN) !== true);
    }

    /**
     * @param  Collection<string, Role>  $roles
     * @return array<string, array{user: ?User, password: ?string, show_password: bool}>
     */
    private function prepareAccounts($roles): array
    {
        $plans = [];

        foreach (self::ACCOUNTS as $role => $account) {
            $key = 'account:'.$role;
            $marker = DemoRecord::query()->where('demo_key', $key)->first();
            $user = null;

            if ($marker) {
                if ($marker->model_type !== (new User)->getMorphClass()) {
                    throw new RuntimeException("The demo key {$key} is marked for an unexpected record type.");
                }

                $user = User::query()->find($marker->model_id);
                if (! $user || $user->email !== $account['email']) {
                    throw new RuntimeException("The marked demo account {$account['email']} is missing or inconsistent.");
                }
                if ($user->hasRole('super-admin')) {
                    throw new RuntimeException("The marked demo account {$account['email']} has a super-admin role; no changes were made.");
                }
            } elseif (User::query()->where('email', $account['email'])->exists()) {
                throw new RuntimeException("The reserved demo email {$account['email']} belongs to an unmarked account; no changes were made.");
            }

            $password = null;
            $showPassword = false;
            if (! $user) {
                $passwordEnv = 'DEMO_'.strtoupper($role).'_PASSWORD';
                $password = Env::get($passwordEnv);
                if (! is_string($password) || $password === '') {
                    $password = $this->generatePassword();
                    $showPassword = true;
                }

                if (Validator::make(['password' => $password], ['password' => User::PASSWORD_RULE])->fails()) {
                    throw new RuntimeException("The password in {$passwordEnv} does not meet the account password requirements.");
                }
            }

            $plans[$role] = [
                'user' => $user,
                'password' => $password,
                'show_password' => $showPassword,
            ];
        }

        return $plans;
    }

    private function generatePassword(): string
    {
        return Str::password(28, true, true, false)
            .$this->randomCharacter('abcdefghijklmnopqrstuvwxyz')
            .$this->randomCharacter('ABCDEFGHIJKLMNOPQRSTUVWXYZ')
            .$this->randomCharacter('0123456789')
            .$this->randomCharacter('@$!%*#?&');
    }

    private function randomCharacter(string $characters): string
    {
        return $characters[random_int(0, strlen($characters) - 1)];
    }

    private function seedAccounts($roles, array $plans, array &$passwordsToShow): array
    {
        $users = [];

        foreach (self::ACCOUNTS as $role => $account) {
            $plan = $plans[$role];
            $user = $plan['user'];
            if (! $user) {
                $user = User::query()->create([
                    'name' => $account['name'],
                    'email' => $account['email'],
                    'password' => $plan['password'],
                ]);
                $user->forceFill([
                    'email_verified_at' => now(),
                    'is_active' => true,
                ])->save();
                DemoRecord::mark($user, 'account:'.$role);
                $user->roles()->sync([$roles->get($role)->id]);

                if ($plan['show_password']) {
                    $passwordsToShow[] = [$role, $user->email, $plan['password']];
                }
            }

            $users[$role] = $user;
        }

        return $users;
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  array<string, mixed>  $identity
     * @param  array<string, mixed>  $attributes
     */
    private function ensureDemoModel(
        string $demoKey,
        string $modelClass,
        array $identity,
        array $attributes
    ): Model {
        $modelType = (new $modelClass)->getMorphClass();
        $marker = DemoRecord::query()->where('demo_key', $demoKey)->first();
        if ($marker) {
            if ($marker->model_type !== $modelType) {
                throw new RuntimeException("The demo key {$demoKey} is marked for an unexpected record type.");
            }

            $query = $modelClass::query();
            if (in_array(SoftDeletes::class, class_uses_recursive($modelClass), true)) {
                $query->withTrashed();
            }
            $model = $query->find($marker->model_id);
            if (! $model || ($model instanceof Patient && $model->trashed())) {
                throw new RuntimeException("The marked demo record {$demoKey} is missing or unavailable.");
            }

            return $model;
        }

        $query = $modelClass::query();
        if (in_array(SoftDeletes::class, class_uses_recursive($modelClass), true)) {
            $query->withTrashed();
        }
        if ($query->where($identity)->exists()) {
            throw new RuntimeException("An unmarked record conflicts with the reserved demo key {$demoKey}; no changes were made.");
        }

        $model = $modelClass::query()->create(array_merge($identity, $attributes));
        DemoRecord::mark($model, $demoKey);

        return $model;
    }

    private function markExistingOrNew(Model $model, string $demoKey): void
    {
        $existingMarker = DemoRecord::query()
            ->where('demo_key', $demoKey)
            ->first();
        if ($existingMarker) {
            if ($existingMarker->model_type !== $model->getMorphClass()
                || (int) $existingMarker->model_id !== (int) $model->getKey()) {
                throw new RuntimeException("The demo key {$demoKey} is marked for an unexpected record.");
            }

            return;
        }

        if (DemoRecord::isMarked($model)) {
            throw new RuntimeException("The appointment slot {$demoKey} has a conflicting demo marker.");
        }

        DemoRecord::mark($model, $demoKey);
    }

    private function assertNoUnmarkedSlots(Provider $provider, Carbon $from, Carbon $to): void
    {
        $conflicts = AppointmentSlot::query()
            ->where('provider_id', $provider->id)
            ->whereBetween('starts_at', [$from, $to->copy()->endOfDay()])
            ->get()
            ->filter(fn (AppointmentSlot $slot): bool => ! DemoRecord::isMarked($slot));

        if ($conflicts->isNotEmpty()) {
            throw new RuntimeException('Unmarked appointment slots already exist for the demo provider; no slots were changed.');
        }
    }
}
