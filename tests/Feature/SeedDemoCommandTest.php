<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\Bed;
use App\Models\BedAssignment;
use App\Models\DemoRecord;
use App\Models\Department;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\Provider;
use App\Models\ProviderSchedule;
use App\Models\Role;
use App\Models\Room;
use App\Models\User;
use App\Models\Ward;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class SeedDemoCommandTest extends TestCase
{
    use RefreshDatabase;

    private array $originalEnvironment = [];

    protected function tearDown(): void
    {
        foreach ($this->originalEnvironment as $name => $value) {
            if ($value === false) {
                putenv($name);
                unset($_ENV[$name], $_SERVER[$name]);

                continue;
            }

            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }

        Env::enablePutenv();

        parent::tearDown();
    }

    public function test_command_creates_only_marked_synthetic_demo_data_and_reruns_without_resetting_passwords(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->setDemoPasswords();
        $adminPassword = $this->randomValidPassword();
        $admin = User::factory()->create([
            'email' => 'existing-admin@safe.test',
            'password' => Hash::make($adminPassword),
        ]);
        $admin->roles()->attach(Role::query()->where('name', 'super-admin')->value('id'));
        $unrelatedUser = User::factory()->create();
        $department = Department::query()->create([
            'code' => 'SAFE',
            'name' => 'Unrelated Department',
            'active' => true,
        ]);
        $patient = Patient::factory()->create();
        $provider = Provider::factory()->create([
            'user_id' => $unrelatedUser->id,
            'department_id' => $department->id,
        ]);
        $ward = Ward::query()->create(['code' => 'SAFE', 'name' => 'Unrelated Ward']);
        $room = Room::query()->create(['ward_id' => $ward->id, 'number' => 'S-01']);
        $bed = Bed::query()->create(['room_id' => $room->id, 'number' => 'S-A']);
        $admission = Admission::query()->create([
            'admission_number' => 'SAFE-ADM-001',
            'patient_id' => $patient->id,
            'attending_provider_id' => $provider->id,
            'status' => 'ADMITTED',
            'reason' => 'Unrelated fixture',
            'admitted_at' => now(),
            'created_by' => $unrelatedUser->id,
        ]);
        BedAssignment::query()->create([
            'admission_id' => $admission->id,
            'bed_id' => $bed->id,
            'assigned_by' => $unrelatedUser->id,
            'assigned_at' => now(),
            'status' => 'ACTIVE',
        ]);

        $before = $this->nonDemoCounts();
        $this->artisan('app:seed-demo')
            ->expectsOutputToContain('Generated passwords are shown once; save them now.')
            ->assertSuccessful();

        $afterFirstRun = $this->nonDemoCounts();
        $this->assertSame($before, $afterFirstRun);
        $this->assertDatabaseCount('patients', 4);
        $this->assertSame(1, User::query()->whereHas('roles', fn ($query) => $query->where('name', 'super-admin'))->count());
        $this->assertSame('existing-admin@safe.test', $admin->fresh()->email);
        $this->assertTrue(Hash::check($adminPassword, $admin->fresh()->password));

        $demoAccounts = User::query()->whereIn('email', [
            'registration@example.com',
            'doctor@example.com',
            'nurse@example.com',
            'patient@example.com',
        ])->get();
        $this->assertCount(4, $demoAccounts);
        foreach ($demoAccounts as $account) {
            $this->assertTrue($account->is_active);
            $this->assertNotNull($account->email_verified_at);
            $this->assertFalse($account->hasRole('super-admin'));
            $this->assertTrue(DemoRecord::isMarked($account));
        }

        $passwordHashes = $demoAccounts->mapWithKeys(fn (User $user): array => [
            $user->email => $user->getRawOriginal('password'),
        ])->all();
        $slotCount = AppointmentSlot::query()->count();
        $this->assertSame(56, $slotCount);
        $this->assertSame(
            now()->toDateString(),
            Carbon::parse(AppointmentSlot::query()->min('starts_at'))->toDateString()
        );

        $this->artisan('app:seed-demo')->assertSuccessful();
        $this->assertSame($slotCount, AppointmentSlot::query()->count());
        foreach ($passwordHashes as $email => $hash) {
            $this->assertSame($hash, User::query()->where('email', $email)->value('password'));
        }

        $this->travelTo(now()->addDay());
        $this->artisan('app:seed-demo')->assertSuccessful();
        $this->assertSame($slotCount + 4, AppointmentSlot::query()->count());
        $this->assertSame($before, $this->nonDemoCounts());

        $patientLogin = User::query()->where('email', 'patient@example.com')->firstOrFail();
        $this->assertSame($patientLogin->id, Patient::query()->where('mrn', 'DEMO-PAT-001')->value('user_id'));
        $this->assertSame(7, ProviderSchedule::query()->count());
        $this->assertTrue($patientLogin->patient()->exists());
        $this->assertTrue($patientLogin->departments()->doesntExist());
        $this->assertTrue(User::query()->where('email', 'nurse@example.com')->firstOrFail()->departments()->exists());
        $this->assertSame(1, Encounter::query()->where('encounter_number', 'DEMO-ENC-001')->count());
        $this->assertSame(2, Appointment::query()->whereIn('appointment_number', ['DEMO-APT-001', 'DEMO-APT-002'])->count());
    }

    public function test_command_refuses_reserved_unmarked_accounts_and_production_requires_force_and_demo_mode(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->setDemoPasswords();
        User::factory()->create(['email' => 'doctor@example.com']);

        $this->artisan('app:seed-demo')
            ->expectsOutputToContain('belongs to an unmarked account')
            ->assertFailed();
        $this->assertDatabaseCount('demo_records', 0);

        User::query()->where('email', 'doctor@example.com')->delete();
        $this->app->detectEnvironment(fn (): string => 'production');
        $this->setEnvironment('DEMO_MODE', 'false');

        $this->artisan('app:seed-demo', ['--force' => true])
            ->expectsOutput('Production demo seeding requires both --force and DEMO_MODE=true.')
            ->assertFailed();
        $this->assertDatabaseCount('demo_records', 0);

        $this->setEnvironment('DEMO_MODE', 'true');
        $this->artisan('app:seed-demo', ['--force' => true])->assertSuccessful();
        $this->assertDatabaseCount('users', 4);
        $this->assertSame(0, User::query()->whereHas('roles', fn ($query) => $query->where('name', 'super-admin'))->count());
        $demoRecordCount = DemoRecord::query()->count();

        $this->setEnvironment('DEMO_MODE', 'false');
        $this->artisan('app:purge-demo', ['--force' => true])
            ->expectsOutput('Production demo purging requires both --force and DEMO_MODE=true.')
            ->assertFailed();
        $this->assertDatabaseCount('demo_records', $demoRecordCount);

        $doctor = User::query()->where('email', 'doctor@example.com')->firstOrFail();
        $doctor->roles()->attach(Role::query()->where('name', 'super-admin')->value('id'));
        $this->setEnvironment('DEMO_MODE', 'true');
        $this->artisan('app:purge-demo', ['--force' => true])
            ->expectsOutput('A marked demo account has a super-admin role; purge refused.')
            ->assertFailed();
        $this->assertDatabaseHas('users', ['id' => $doctor->id]);
        $this->assertDatabaseHas('demo_records', ['demo_key' => 'account:doctor']);
    }

    public function test_purge_refuses_unmarked_later_patient_dependents_and_preserves_audit_logs(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->setDemoPasswords();
        $this->artisan('app:seed-demo')->assertSuccessful();

        $patient = Patient::query()->where('mrn', 'DEMO-PAT-001')->firstOrFail();
        $demoDepartment = Department::query()->where('code', 'DEMO')->firstOrFail();
        $unrelatedUser = User::factory()->create(['email' => 'unrelated-department-user@safe.test']);
        $unrelatedUser->departments()->attach($demoDepartment->id);
        $provider = Provider::query()->whereHas('user', fn ($query) => $query->where('email', 'doctor@example.com'))->firstOrFail();
        $vitalId = DB::table('vitals')->insertGetId([
            'patient_id' => $patient->id,
            'recorded_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $appointment = Appointment::factory()->create([
            'appointment_number' => 'APT-UNMARKED-'.Str::upper(Str::random(8)),
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
            'starts_at' => now()->addDays(20),
            'ends_at' => now()->addDays(20)->addMinutes(30),
        ]);
        $encounter = Encounter::factory()->create([
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
        ]);
        $demoUser = User::query()->where('email', 'patient@example.com')->firstOrFail();
        $auditLogId = DB::table('audit_logs')->insertGetId([
            'user_id' => $demoUser->id,
            'action' => 'demo_test',
            'resource_type' => 'patient',
            'resource_id' => $patient->id,
            'result' => 'SUCCESS',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('app:purge-demo')
            ->expectsOutputToContain('Demo purge refused; unmarked records still reference demo data:')
            ->expectsOutputToContain('appointments')
            ->expectsOutputToContain('department_user')
            ->expectsOutputToContain('encounters')
            ->expectsOutputToContain('vitals')
            ->assertFailed();
        $this->assertDatabaseHas('vitals', ['id' => $vitalId, 'patient_id' => $patient->id]);
        $this->assertDatabaseHas('appointments', ['id' => $appointment->id, 'patient_id' => $patient->id]);
        $this->assertDatabaseHas('encounters', ['id' => $encounter->id, 'patient_id' => $patient->id]);
        $this->assertDatabaseHas('department_user', [
            'department_id' => $demoDepartment->id,
            'user_id' => $unrelatedUser->id,
        ]);
        $this->assertDatabaseHas('audit_logs', ['id' => $auditLogId]);
        $this->assertDatabaseHas('patients', ['id' => $patient->id]);
    }

    public function test_purge_requires_extra_confirmation_and_deletes_only_marked_records(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->setDemoPasswords();
        $unrelatedUser = User::factory()->create(['email' => 'unrelated@safe.test']);
        $this->artisan('app:seed-demo')->assertSuccessful();
        $demoUser = User::query()->where('email', 'patient@example.com')->firstOrFail();
        $auditLogId = DB::table('audit_logs')->insertGetId([
            'user_id' => $demoUser->id,
            'action' => 'demo_test',
            'resource_type' => 'user',
            'resource_id' => $demoUser->id,
            'result' => 'SUCCESS',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('app:purge-demo')
            ->expectsConfirmation('This permanently deletes all marked demo records. Continue?', 'yes')
            ->assertSuccessful();

        $this->assertDatabaseCount('demo_records', 0);
        $this->assertDatabaseHas('users', ['id' => $unrelatedUser->id]);
        $this->assertDatabaseHas('audit_logs', ['id' => $auditLogId, 'user_id' => null]);
        $this->assertDatabaseMissing('patients', ['mrn' => 'DEMO-PAT-001']);
    }

    public function test_random_passwords_are_displayed_only_by_the_seed_command(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        foreach (array_keys(self::passwordEnvironmentNames()) as $name) {
            $this->setEnvironment($name, null);
        }

        $this->artisan('app:seed-demo')
            ->expectsOutputToContain('Generated passwords are shown once; save them now.')
            ->expectsOutputToContain('Generated password')
            ->assertSuccessful();

        $output = Artisan::output();
        $this->assertStringNotContainsString('DEMO_REGISTRATION_PASSWORD', $output);
    }

    private function nonDemoCounts(): array
    {
        $models = [
            'users' => User::class,
            'departments' => Department::class,
            'wards' => Ward::class,
            'rooms' => Room::class,
            'beds' => Bed::class,
            'bed_assignments' => BedAssignment::class,
            'patients' => Patient::class,
        ];

        $counts = [];
        foreach ($models as $table => $modelClass) {
            $modelType = (new $modelClass)->getMorphClass();
            $markedIds = DemoRecord::query()
                ->where('model_type', $modelType)
                ->select('model_id');
            $counts[$table] = Schema::hasTable($table)
                ? DB::table($table)->whereNotIn('id', $markedIds)->count()
                : 0;
        }

        return $counts;
    }

    private function setDemoPasswords(): void
    {
        foreach (self::passwordEnvironmentNames() as $role => $name) {
            $this->setEnvironment($name, $this->randomValidPassword());
        }
    }

    private function randomValidPassword(): string
    {
        return Str::password(20, true, true, false)
            .strtolower('abcdefghijklmnopqrstuvwxyz'[random_int(0, 25)])
            .'ABCDEFGHIJKLMNOPQRSTUVWXYZ'[random_int(0, 25)]
            .'0123456789'[random_int(0, 9)]
            .'@$!%*#?&'[random_int(0, 7)];
    }

    private static function passwordEnvironmentNames(): array
    {
        return [
            'registration' => 'DEMO_REGISTRATION_PASSWORD',
            'doctor' => 'DEMO_DOCTOR_PASSWORD',
            'nurse' => 'DEMO_NURSE_PASSWORD',
            'patient' => 'DEMO_PATIENT_PASSWORD',
        ];
    }

    private function setEnvironment(string $name, ?string $value): void
    {
        if (! array_key_exists($name, $this->originalEnvironment)) {
            $this->originalEnvironment[$name] = getenv($name);
        }

        if ($value === null) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        } else {
            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }

        Env::enablePutenv();
    }
}
