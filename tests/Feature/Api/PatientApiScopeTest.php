<?php

namespace Tests\Feature\Api;

use App\Models\Appointment;
use App\Models\Encounter;
use App\Models\ErVisit;
use App\Models\Patient;
use App\Models\Provider;
use App\Models\TriageAssessment;
use App\Models\User;
use Database\Seeders\HimsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PatientApiScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(HimsSeeder::class);
    }

    public function test_doctor_api_search_and_detail_only_return_patients_linked_to_their_provider(): void
    {
        $doctor = User::where('email', 'doctor@coor.test')->firstOrFail();
        $provider = $doctor->provider;
        $otherProvider = Provider::create([
            'user_id' => User::factory()->create()->id,
            'display_name' => 'Dr. Different Provider',
            'active' => true,
        ]);

        $encounterPatient = $this->createPatient('MRN-2026-810001', 'Linked', 'Encounter');
        $appointmentPatient = $this->createPatient('MRN-2026-810002', 'Linked', 'Appointment');
        $otherProviderPatient = $this->createPatient('MRN-2026-810003', 'Unlinked', 'Patient');
        $unassignedPatient = $this->createPatient('MRN-2026-810004', 'Unassigned', 'Patient');

        $encounterPatient->encounters()->create([
            'encounter_number' => 'ENC-2026-810001',
            'provider_id' => $provider->id,
            'type' => Encounter::TYPE_OUTPATIENT,
            'started_at' => now(),
            'status' => 'OPEN',
        ]);
        Appointment::create([
            'appointment_number' => 'APT-2026-810001',
            'patient_id' => $appointmentPatient->id,
            'provider_id' => $provider->id,
            'starts_at' => now()->addDays(2),
            'ends_at' => now()->addDays(2)->addMinutes(30),
            'status' => Appointment::STATUS_CONFIRMED,
        ]);
        $otherProviderPatient->encounters()->create([
            'encounter_number' => 'ENC-2026-810002',
            'provider_id' => $otherProvider->id,
            'type' => Encounter::TYPE_OUTPATIENT,
            'started_at' => now(),
            'status' => 'OPEN',
        ]);

        $response = $this->actingAs($doctor)->getJson('/api/v1/patients');
        $response->assertOk()
            ->assertJsonFragment(['mrn' => $encounterPatient->mrn])
            ->assertJsonFragment(['mrn' => $appointmentPatient->mrn])
            ->assertJsonMissing(['mrn' => $otherProviderPatient->mrn])
            ->assertJsonMissing(['mrn' => $unassignedPatient->mrn]);

        $this->actingAs($doctor)->getJson('/api/v1/patients/search/Unlinked')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->actingAs($doctor)->getJson("/api/v1/patients/{$otherProviderPatient->id}")
            ->assertNotFound();
        $this->actingAs($doctor)->getJson("/api/v1/patients/{$appointmentPatient->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $appointmentPatient->id);
    }

    public function test_doctor_without_provider_profile_sees_no_patient_records(): void
    {
        $doctor = User::factory()->create([
            'email_verified_at' => now(),
        ]);
        $doctor->roles()->attach(\App\Models\Role::where('name', 'doctor')->value('id'));

        $this->actingAs($doctor)->getJson('/api/v1/patients')
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('data', []);
    }

    public function test_nurse_api_search_matches_er_and_triage_patient_scope(): void
    {
        $nurse = User::where('email', 'nurse@coor.test')->firstOrFail();
        $erPatient = $this->createPatient('MRN-2026-820001', 'Nurse', 'ER');
        $unrelatedPatient = $this->createPatient('MRN-2026-820002', 'Outside', 'ER');
        $visit = ErVisit::create([
            'visit_number' => 'ER-2026-820001',
            'patient_id' => $erPatient->id,
            'chief_complaint' => 'Chest discomfort',
            'arrived_at' => now(),
            'status' => ErVisit::STATUS_ARRIVED,
        ]);
        TriageAssessment::create([
            'patient_id' => $erPatient->id,
            'er_visit_id' => $visit->id,
            'triage_nurse_id' => $nurse->id,
            'triaged_at' => now(),
            'priority' => 'LEVEL_3',
            'status' => 'COMPLETE',
        ]);

        $this->actingAs($nurse)->getJson('/api/v1/patients')
            ->assertOk()
            ->assertJsonFragment(['mrn' => $erPatient->mrn])
            ->assertJsonMissing(['mrn' => $unrelatedPatient->mrn]);

        $this->actingAs($nurse)->getJson("/api/v1/patients/{$unrelatedPatient->id}")
            ->assertNotFound();
    }

    public function test_registration_api_and_web_patient_directory_keep_the_broader_scope_and_web_role_lists_match(): void
    {
        $registration = User::where('email', 'registration@coor.test')->firstOrFail();
        $doctor = User::where('email', 'doctor@coor.test')->firstOrFail();
        $nurse = User::where('email', 'nurse@coor.test')->firstOrFail();

        $doctorPatient = $this->createPatient('MRN-2026-830001', 'Doctor', 'Scope');
        $otherPatient = $this->createPatient('MRN-2026-830002', 'Other', 'Scope');
        $doctorPatient->encounters()->create([
            'encounter_number' => 'ENC-2026-830001',
            'provider_id' => $doctor->provider->id,
            'type' => Encounter::TYPE_OUTPATIENT,
            'started_at' => now(),
            'status' => 'OPEN',
        ]);

        $this->actingAs($registration)->getJson('/api/v1/patients')
            ->assertOk()
            ->assertJsonFragment(['mrn' => $doctorPatient->mrn])
            ->assertJsonFragment(['mrn' => $otherPatient->mrn]);

        $this->actingAs($doctor)->get('/patients')
            ->assertOk()
            ->assertSee($doctorPatient->mrn)
            ->assertDontSee($otherPatient->mrn)
            ->assertSee('Search patient records available to your role')
            ->assertSee('name="q"', false);

        $this->actingAs($nurse)->get('/patients')
            ->assertOk()
            ->assertDontSee($doctorPatient->mrn)
            ->assertDontSee($otherPatient->mrn)
            ->assertSee('name="q"', false);

        $this->actingAs($registration)->get('/patients')
            ->assertOk()
            ->assertSee($doctorPatient->mrn)
            ->assertSee($otherPatient->mrn);
    }

    private function createPatient(string $mrn, string $firstName, string $lastName): Patient
    {
        return Patient::create([
            'mrn' => $mrn,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'date_of_birth' => '1990-01-01',
            'sex' => 'Other',
            'verified' => true,
        ]);
    }
}
