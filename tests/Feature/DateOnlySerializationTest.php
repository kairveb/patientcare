<?php

namespace Tests\Feature;

use App\Models\Encounter;
use App\Models\Patient;
use App\Models\User;
use App\Services\PatientService;
use Carbon\Carbon;
use Database\Seeders\HimsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DateOnlySerializationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(HimsSeeder::class);
    }

    public function test_date_only_values_round_trip_through_api_and_web_in_manila_timezone(): void
    {
        $originalTimezone = date_default_timezone_get();
        $originalAppTimezone = config('app.timezone');
        config(['app.timezone' => 'Asia/Manila']);
        date_default_timezone_set('Asia/Manila');

        try {
            $patient = Patient::factory()->create([
                'first_name' => 'DateOnly',
                'last_name' => 'Patient',
                'date_of_birth' => '2000-05-06',
                'sex' => 'Female',
            ]);
            $doctor = User::where('email', 'doctor@coor.test')->firstOrFail();

            $encounterResponse = $this->actingAs($doctor, 'sanctum')->postJson('/api/v1/encounters', [
                'patient_id' => $patient->id,
                'provider_id' => $doctor->provider->id,
                'type' => Encounter::TYPE_OUTPATIENT,
                'follow_up_date' => '2026-05-20',
            ])->assertCreated()
                ->assertJsonPath('data.follow_up_date', '2026-05-20');

            $encounter = Encounter::findOrFail($encounterResponse->json('data.id'));
            $this->assertDatabaseHas('encounters', [
                'id' => $encounter->id,
                'follow_up_date' => '2026-05-20',
            ]);
            $this->assertSame('2026-05-20', $encounter->follow_up_date->toDateString());

            $duplicates = app(PatientService::class)->findDuplicates([
                'first_name' => 'DateOnly',
                'last_name' => 'Patient',
                'date_of_birth' => '2000-05-06',
            ]);
            $this->assertSame($patient->id, $duplicates[0]['id']);
            $this->assertSame('2000-05-06', $duplicates[0]['date_of_birth']);

            $registration = User::where('email', 'registration@coor.test')->firstOrFail();
            foreach (['02:00:00', '14:00:00'] as $time) {
                Carbon::setTestNow(Carbon::parse("2026-05-06 {$time}", 'Asia/Manila'));
                $registration->forceFill(['last_activity_at' => now()])->save();

                $patientResponse = $this->actingAs($registration, 'sanctum')->getJson("/api/v1/patients/{$patient->id}");
                $patientResponse->assertOk()->assertJsonPath('data.date_of_birth', '2000-05-06');

                $this->actingAs($registration, 'sanctum')->postJson('/api/v1/emergency/visits/unknown', [
                    'first_name' => 'DateOnly',
                    'date_of_birth' => '2000-05-06',
                    'sex' => 'Female',
                    'chief_complaint' => 'Date-only serialization check',
                ])->assertCreated()
                    ->assertJsonPath('data.patient.date_of_birth', '2000-05-06');

                $this->assertSame(26, $patient->fresh()->age);

                $webResponse = $this->actingAs($registration, 'web')->get(route('patients.show', $patient));
                $webResponse->assertOk()
                    ->assertSee('May 06, 2000')
                    ->assertSee('May 20, 2026');
            }
        } finally {
            Carbon::setTestNow();
            date_default_timezone_set($originalTimezone);
            config(['app.timezone' => $originalAppTimezone]);
        }
    }
}
