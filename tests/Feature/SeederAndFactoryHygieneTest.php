<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Department;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SeederAndFactoryHygieneTest extends TestCase
{
    use RefreshDatabase;

    public function test_reference_seeder_creates_app_reference_data_without_demo_records(): void
    {
        $this->seed(ReferenceDataSeeder::class);

        $this->assertDatabaseHas('roles', ['name' => 'doctor']);
        $this->assertDatabaseHas('departments', ['code' => 'CAR']);
        $this->assertDatabaseHas('appointment_types', ['name' => 'Outpatient']);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('patients', 0);
        $this->assertTrue(Schema::hasTable('charge_rates'));
    }

    public function test_demo_seeder_refuses_production_environment(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $originalEnvironment = app()->environment();
        app()->detectEnvironment(fn (): string => 'production');

        try {
            app(DemoDataSeeder::class)->run();
            $this->fail('Demo seeding should be refused in production.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Demo data seeding is disabled in production.', $exception->getMessage());
        } finally {
            app()->detectEnvironment(fn (): string => $originalEnvironment);
        }
    }

    public function test_factories_create_clinical_records_and_department_assignments_on_demand(): void
    {
        $this->seed(ReferenceDataSeeder::class);

        $department = Department::where('code', 'CAR')->firstOrFail();
        $nurse = User::factory()->nurse()->assignedToDepartment($department)->create();
        $patient = Patient::factory()->create();
        $appointment = Appointment::factory()->create([
            'patient_id' => $patient->id,
            'department_id' => $department->id,
        ]);
        $encounter = Encounter::factory()->create([
            'patient_id' => $patient->id,
            'provider_id' => $appointment->provider_id,
            'appointment_id' => $appointment->id,
        ]);

        $this->assertTrue($nurse->departments()->whereKey($department->id)->exists());
        $this->assertSame($patient->id, $appointment->patient_id);
        $this->assertSame($appointment->provider_id, $encounter->provider_id);
    }
}
