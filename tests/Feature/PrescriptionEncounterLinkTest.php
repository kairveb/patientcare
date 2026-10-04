<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\TelehealthSession;
use App\Models\User;
use Database\Seeders\HimsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PrescriptionEncounterLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(HimsSeeder::class);
    }

    public function test_prescription_schema_supports_optional_telehealth_and_encounter_links(): void
    {
        $this->assertTrue(Schema::hasColumn('prescriptions', 'encounter_id'));

        $patient = Patient::query()->firstOrFail();
        $doctor = User::where('email', 'doctor@coor.test')->firstOrFail();
        $encounter = Encounter::create([
            'encounter_number' => 'ENC-2026-RX-001',
            'patient_id' => $patient->id,
            'provider_id' => $doctor->provider->id,
            'type' => Encounter::TYPE_OUTPATIENT,
            'started_at' => now(),
            'status' => 'OPEN',
        ]);

        $prescription = Prescription::create([
            'telehealth_session_id' => null,
            'encounter_id' => $encounter->id,
            'patient_id' => $patient->id,
            'medication_name' => 'Example medication',
            'dosage' => 'One tablet',
            'instructions' => 'Take once daily.',
            'prescribed_by' => $doctor->id,
            'prescribed_at' => now(),
        ]);

        $this->assertDatabaseHas('prescriptions', [
            'id' => $prescription->id,
            'telehealth_session_id' => null,
            'encounter_id' => $encounter->id,
        ]);
        $this->assertSame($prescription->id, $encounter->prescriptions()->firstOrFail()->id);
        $this->assertSame($encounter->id, $prescription->encounter->id);

        $encounter->delete();
        $this->assertDatabaseHas('prescriptions', [
            'id' => $prescription->id,
            'encounter_id' => null,
        ]);
    }

    public function test_api_allows_owning_doctor_to_prescribe_to_an_encounter_and_rejects_other_roles(): void
    {
        $doctor = User::where('email', 'doctor@coor.test')->firstOrFail();
        $nurse = User::where('email', 'nurse@coor.test')->firstOrFail();
        $encounter = $this->createEncounter($doctor);
        $payload = [
            'medication_name' => 'Example medication',
            'dosage' => 'One tablet',
            'instructions' => 'Take once daily.',
        ];

        $this->actingAs($doctor)->postJson("/api/v1/encounters/{$encounter->id}/prescriptions", [
            'medication_name' => '   ',
            'dosage' => 'One tablet',
            'instructions' => 'Take once daily.',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['medication_name']);

        $this->actingAs($doctor)->postJson("/api/v1/encounters/{$encounter->id}/prescriptions", $payload)
            ->assertCreated()
            ->assertJsonPath('data.telehealth_session_id', null)
            ->assertJsonPath('data.encounter_id', $encounter->id)
            ->assertJsonPath('data.patient_id', $encounter->patient_id);
        $this->actingAs($doctor)->getJson("/api/v1/encounters/{$encounter->id}")
            ->assertOk()
            ->assertJsonPath('data.prescriptions.0.encounter_id', $encounter->id);

        $this->actingAs($nurse)->postJson("/api/v1/encounters/{$encounter->id}/prescriptions", $payload)
            ->assertForbidden();
    }

    public function test_web_prescription_action_is_hidden_for_nurses_and_patient_portal_lists_regular_encounter_prescriptions(): void
    {
        $doctor = User::where('email', 'doctor@coor.test')->firstOrFail();
        $nurse = User::where('email', 'nurse@coor.test')->firstOrFail();
        $encounter = $this->createEncounter($doctor);

        $this->actingAs($nurse)->get(route('encounters.show', $encounter))
            ->assertOk()
            ->assertDontSee('Issue Prescription')
            ->assertDontSee('encounters/'.$encounter->id.'/prescriptions');

        $this->actingAs($doctor)->get(route('encounters.show', $encounter))
            ->assertOk()
            ->assertSee('Issue Prescription');

        $this->actingAs($doctor)->post(route('encounters.prescriptions.store', $encounter), [
            'medication_name' => 'Encounter medication',
            'dosage' => 'Two tablets',
            'instructions' => 'Take after meals.',
        ])->assertRedirect(route('encounters.show', $encounter));

        $this->assertDatabaseHas('prescriptions', [
            'encounter_id' => $encounter->id,
            'telehealth_session_id' => null,
            'patient_id' => $encounter->patient_id,
            'medication_name' => 'Encounter medication',
        ]);

        $patientUser = $encounter->patient->user;
        if (! $patientUser) {
            $patientUser = User::factory()->create(['email_verified_at' => now()]);
            $patientUser->roles()->attach(\App\Models\Role::where('name', 'patient')->value('id'));
            $encounter->patient->update(['user_id' => $patientUser->id]);
        }

        $this->actingAs($patientUser)->get('/patient-portal')
            ->assertOk()
            ->assertSee('Encounter medication')
            ->assertSee('Take after meals.');
    }

    public function test_telehealth_prescription_flow_retains_its_session_link(): void
    {
        $doctor = User::where('email', 'doctor@coor.test')->firstOrFail();
        $patient = Patient::query()->firstOrFail();
        $appointment = Appointment::create([
            'appointment_number' => 'APT-2026-RX-001',
            'patient_id' => $patient->id,
            'provider_id' => $doctor->provider->id,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addMinutes(30),
            'status' => Appointment::STATUS_CONFIRMED,
        ]);
        $session = TelehealthSession::create([
            'appointment_id' => $appointment->id,
            'start_time' => now()->addDay(),
            'duration' => 30,
            'status' => TelehealthSession::STATUS_SCHEDULED,
        ]);

        $this->actingAs($doctor)->postJson("/api/v1/telehealth/{$session->id}/prescription", [
            'medication_name' => 'Telehealth medication',
            'dosage' => 'One dose',
            'instructions' => 'Use as directed.',
        ])->assertOk();

        $this->assertDatabaseHas('prescriptions', [
            'telehealth_session_id' => $session->id,
            'encounter_id' => null,
            'patient_id' => $patient->id,
        ]);
    }

    private function createEncounter(User $doctor): Encounter
    {
        return Encounter::create([
            'encounter_number' => 'ENC-'.strtoupper(bin2hex(random_bytes(6))),
            'patient_id' => Patient::query()->firstOrFail()->id,
            'provider_id' => $doctor->provider->id,
            'type' => Encounter::TYPE_OUTPATIENT,
            'started_at' => now(),
            'status' => 'OPEN',
        ]);
    }
}
