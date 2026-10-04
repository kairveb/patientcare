<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Department;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\Provider;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase as BaseTestCase;

class MySqlConcurrencyTest extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('MySQL concurrency behavior is only exercised on the MySQL driver.');
        }

        if (config('database.connections.mysql.database') !== 'hims_test') {
            $this->fail('MySQL concurrency tests require the dedicated hims_test database.');
        }

        $this->seed(ReferenceDataSeeder::class);
    }

    public function refreshDatabase()
    {
        if (config('database.default') !== 'mysql') {
            parent::refreshDatabase();

            return;
        }

        if (config('database.connections.mysql.database') !== 'hims_test') {
            throw new \RuntimeException('MySQL concurrency tests require the dedicated hims_test database.');
        }

        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    public function test_concurrent_encounter_completion_allows_only_one_transition(): void
    {
        [$doctor, $provider] = $this->doctorAndProvider();
        $encounter = Encounter::factory()->create([
            'patient_id' => Patient::factory(),
            'provider_id' => $provider->id,
        ]);

        $results = $this->runConcurrent('complete', $encounter->id, $doctor->id);

        $this->assertSame(['success', 'validation_rejected'], $this->statuses($results));
        $this->assertSame(Encounter::STATUS_COMPLETED, $encounter->fresh()->status);
    }

    public function test_concurrent_vitals_upserts_leave_one_appointment_vital_row(): void
    {
        [$doctor, $provider] = $this->doctorAndProvider();
        $patient = Patient::factory()->create();
        $department = Department::where('code', 'CAR')->firstOrFail();
        $nurse = User::factory()->nurse()->assignedToDepartment($department)->create();
        $appointment = Appointment::factory()->create([
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
            'department_id' => $department->id,
            'status' => Appointment::STATUS_CHECKED_IN,
        ]);

        $results = $this->runConcurrent('vitals', $appointment->id, $nurse->id);

        $this->assertSame(['success', 'success'], $this->statuses($results));
        $this->assertSame(1, $appointment->vitals()->count());
    }

    public function test_concurrent_duplicate_admission_requests_create_one_admission(): void
    {
        [$doctor, $provider] = $this->doctorAndProvider();
        $encounter = Encounter::factory()->create([
            'patient_id' => Patient::factory(),
            'provider_id' => $provider->id,
        ]);

        $results = $this->runConcurrent('admission', $encounter->id, $doctor->id);

        $this->assertSame(['success', 'validation_rejected'], $this->statuses($results));
        $this->assertSame(1, $encounter->admission()->count());
    }

    private function doctorAndProvider(): array
    {
        $doctor = User::factory()->create();
        $doctor->roles()->attach(Role::where('name', 'doctor')->value('id'));
        $provider = Provider::factory()->create([
            'user_id' => $doctor->id,
            'department_id' => Department::where('code', 'CAR')->value('id'),
        ]);

        return [$doctor, $provider];
    }

    private function runConcurrent(string $operation, int $recordId, int $actorId): array
    {
        $this->assertSame(0, DB::transactionLevel(), 'Concurrency workers must not be hidden behind a test transaction.');
        $barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hims-concurrency-'.bin2hex(random_bytes(8));
        $worker = base_path('tests/Support/concurrency-worker.php');
        $processes = [];

        foreach (range(1, 2) as $_) {
            $database = config('database.connections.mysql');
            $environment = array_merge((array) getenv(), [
                'APP_ENV' => 'testing',
                'APP_KEY' => (string) config('app.key'),
                'DB_CONNECTION' => 'mysql',
                'DB_HOST' => (string) $database['host'],
                'DB_PORT' => (string) $database['port'],
                'DB_DATABASE' => 'hims_test',
                'DB_USERNAME' => (string) $database['username'],
                'DB_PASSWORD' => (string) $database['password'],
            ]);
            $process = new Process(
                [PHP_BINARY, $worker, $operation, (string) $recordId, (string) $actorId, $barrier],
                base_path(),
                $environment
            );
            $process->setTimeout(30);
            $process->start();
            $processes[] = $process;
        }

        file_put_contents($barrier, 'start');
        try {
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            }
        } finally {
            if (is_file($barrier)) {
                unlink($barrier);
            }
        }

        return array_map(
            static fn (Process $process): array => json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR),
            $processes
        );
    }

    private function statuses(array $results): array
    {
        $statuses = array_column($results, 'status');
        sort($statuses);

        return $statuses;
    }
}
