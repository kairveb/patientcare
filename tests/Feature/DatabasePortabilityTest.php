<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\Permission;
use App\Models\Patient;
use App\Models\Provider;
use App\Models\Role;
use App\Models\TelehealthSession;
use App\Models\User;
use Database\Seeders\HimsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabasePortabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_mysql_and_mariadb_connections_are_environment_driven(): void
    {
        $this->assertSame((string) env('DB_CONNECTION', 'sqlite'), config('database.default'));
        foreach (['mysql', 'mariadb'] as $engine) {
            $this->assertSame((string) env('DB_HOST', '127.0.0.1'), config("database.connections.{$engine}.host"));
            $this->assertSame((string) env('DB_PORT', '3306'), (string) config("database.connections.{$engine}.port"));
            $this->assertSame((string) env('DB_DATABASE', 'laravel'), config("database.connections.{$engine}.database"));
            $this->assertSame((string) env('DB_USERNAME', 'root'), config("database.connections.{$engine}.username"));
        }
    }

    public function test_telehealth_name_search_matches_multiple_name_tokens_without_concat_sql(): void
    {
        $this->seed(HimsSeeder::class);
        $role = Role::firstOrCreate(['name' => 'nurse'], ['label' => 'Nurse']);
        $permission = Permission::firstOrCreate(['name' => 'view-telehealth'], ['label' => 'View Telehealth']);
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $nurse = User::factory()->create();
        $nurse->roles()->attach($role);

        $patient = Patient::factory()->create(['first_name' => 'Mina', 'last_name' => 'Portable']);
        $provider = Provider::factory()->create();
        $appointmentType = AppointmentType::query()->where('telehealth', true)->firstOrFail();
        $appointment = Appointment::factory()->create([
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
            'appointment_type_id' => $appointmentType->id,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addMinutes(30),
        ]);
        TelehealthSession::create([
            'appointment_id' => $appointment->id,
            'start_time' => $appointment->starts_at,
            'duration' => 30,
            'status' => TelehealthSession::STATUS_SCHEDULED,
        ]);

        $this->actingAs($nurse)
            ->get(route('telehealth.index', ['q' => 'Mina Portable']))
            ->assertOk()
            ->assertSee('Mina Portable');
    }
}
