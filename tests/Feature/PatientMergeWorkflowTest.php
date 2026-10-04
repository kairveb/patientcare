<?php

namespace Tests\Feature;

use App\Models\ErVisit;
use App\Models\Patient;
use App\Models\PatientAddress;
use App\Models\User;
use App\Services\PatientConsentService;
use App\Services\PatientService;
use Database\Seeders\HimsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PatientMergeWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(HimsSeeder::class);
    }

    public function test_duplicate_detection_scores_fuzzy_names_and_normalized_contact_without_exposing_other_patient_data(): void
    {
        $patient = Patient::where('mrn', 'MRN-2026-000001')->firstOrFail();

        $matches = app(PatientService::class)->findDuplicates([
            'first_name' => 'Maria',
            'last_name' => 'Santoss',
            'date_of_birth' => '1987-04-18',
            'phone' => '+639171234567',
        ]);

        $this->assertNotEmpty($matches);
        $this->assertSame($patient->id, $matches[0]['id']);
        $this->assertGreaterThanOrEqual(45, $matches[0]['similarity_score']);
        $this->assertContains('Date of birth matches', $matches[0]['match_reasons']);
        $this->assertContains('Contact number matches', $matches[0]['match_reasons']);
        $this->assertArrayNotHasKey('allergies', $matches[0]);
        $this->assertArrayNotHasKey('email', $matches[0]);
    }

    public function test_staff_registration_warns_for_a_fuzzy_match_and_audits_an_explicit_separate_record_override(): void
    {
        $registration = $this->user('registration@coor.test');
        $patientData = [
            'first_name' => 'Maria',
            'last_name' => 'Santoss',
            'date_of_birth' => '1987-04-18',
            'sex' => 'Female',
            'phone' => '09171234567',
            'data_privacy_consent' => '1',
        ];
        $initialCount = Patient::count();

        $this->actingAs($registration)
            ->from(route('patients.index'))
            ->post(route('patients.store'), $patientData)
            ->assertRedirect(route('patients.index'))
            ->assertSessionHas('duplicate_warning')
            ->assertSessionHas('duplicates.0.similarity_score');
        $this->assertSame($initialCount, Patient::count());

        $this->actingAs($registration)
            ->post(route('patients.store'), $patientData + ['force' => '1'])
            ->assertRedirect();

        $this->assertSame($initialCount + 1, Patient::count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'DUPLICATE_REGISTRATION_OVERRIDE',
            'resource_type' => 'patient',
            'user_id' => $registration->id,
        ]);
    }

    public function test_api_registration_returns_duplicate_candidates_and_audits_explicit_override(): void
    {
        $registration = $this->user('registration@coor.test');
        $payload = [
            'first_name' => 'Maria',
            'last_name' => 'Santoss',
            'date_of_birth' => '1987-04-18',
            'sex' => 'Female',
            'phone' => '+639171234567',
            'data_privacy_consent' => true,
        ];

        $response = $this->actingAs($registration)
            ->postJson('/api/v1/patients', $payload)
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'POSSIBLE_DUPLICATE_PATIENTS')
            ->assertJsonPath('candidates.0.mrn', 'MRN-2026-000001');
        $this->assertGreaterThanOrEqual(90, $response->json('candidates.0.similarity_score'));

        $this->actingAs($registration)
            ->postJson('/api/v1/patients', $payload + ['force_duplicate' => true])
            ->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'DUPLICATE_REGISTRATION_OVERRIDE',
            'user_id' => $registration->id,
        ]);
    }

    public function test_public_pre_registration_blocks_possible_duplicates_without_revealing_candidate_details(): void
    {
        $initialCount = Patient::count();

        $this->from('/pre-register')
            ->post('/pre-register', [
                'first_name' => 'Maria',
                'last_name' => 'Santos',
                'date_of_birth' => '1987-04-18',
                'sex' => 'Female',
                'phone' => '09171234567',
                'data_privacy_consent' => '1',
            ])
            ->assertRedirect('/pre-register')
            ->assertSessionHasErrors('duplicate_patient');

        $this->assertSame(
            'A patient record may already match these details. Please contact Registration Staff to verify your record before submitting again.',
            session('errors')->getBag('default')->first('duplicate_patient')
        );
        $this->assertSame($initialCount, Patient::count());
    }

    public function test_registration_staff_merge_moves_linked_records_and_preserves_source_record_and_audit_history(): void
    {
        $source = Patient::where('mrn', 'MRN-2026-000001')->firstOrFail();
        $registration = $this->user('registration@coor.test');
        $source->update([
            'philhealth_number' => '123456789012',
            'philhealth_verified_by' => $registration->id,
            'philhealth_verified_at' => now(),
        ]);
        $target = Patient::create([
            'mrn' => 'MRN-MERGE-TARGET-001',
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'date_of_birth' => '1987-04-18',
            'sex' => 'Female',
            'verified' => false,
        ]);
        $nurse = $this->user('nurse@coor.test');
        $visit = ErVisit::create([
            'visit_number' => 'ER-MERGE-'.uniqid(),
            'patient_id' => $source->id,
            'arrived_at' => now(),
            'chief_complaint' => 'Historical complaint',
            'status' => ErVisit::STATUS_ARRIVED,
            'created_by' => $nurse->id,
        ]);
        $address = PatientAddress::create([
            'patient_id' => $source->id,
            'line1' => '10 Mabini Street',
            'city' => 'Manila',
            'primary' => true,
        ]);
        $consent = app(PatientConsentService::class)->record($source, 'TELEHEALTH', true, $source->user, 'self');

        $this->actingAs($registration)
            ->post(route('patients.merge', $source), ['target_patient_id' => $target->id])
            ->assertRedirect(route('patients.show', $target));

        $this->assertSame($target->id, $visit->fresh()->patient_id);
        $this->assertSame($target->id, $address->fresh()->patient_id);
        $this->assertSame($source->id, $consent->fresh()->patient_id);
        $this->assertTrue(app(PatientConsentService::class)->hasCurrentGrant($target->fresh(), 'TELEHEALTH'));
        $this->assertSame('Penicillin', $target->fresh()->allergies);
        $this->assertSame('09171234567', $target->fresh()->phone);
        $this->assertSame('123456789012', $target->fresh()->philhealth_number);
        $this->assertSame($registration->id, $target->fresh()->philhealth_verified_by);
        $this->assertTrue($target->fresh()->verified);
        $this->assertSame($target->id, $source->fresh()->merged_into_patient_id);
        $this->assertTrue($source->fresh()->trashed());
        $this->assertSame('Maria', $source->fresh()->first_name);
        $this->assertSame($target->id, $source->fresh()->mergedInto->id);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'MERGE_PATIENT_SOURCE',
            'resource_type' => 'patient',
            'resource_id' => $source->id,
            'user_id' => $registration->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'MERGE_PATIENT_TARGET',
            'resource_type' => 'patient',
            'resource_id' => $target->id,
            'user_id' => $registration->id,
        ]);
        $this->assertSame($source->user_id, $target->fresh()->user_id);
    }

    public function test_api_merge_uses_the_same_similarity_validation_and_denies_non_registration_roles(): void
    {
        $source = Patient::where('mrn', 'MRN-2026-000001')->firstOrFail();
        $unrelated = Patient::create([
            'mrn' => 'MRN-UNRELATED-'.uniqid(),
            'first_name' => 'Alicia',
            'last_name' => 'Reyes',
            'date_of_birth' => '1972-09-12',
            'sex' => 'Female',
            'verified' => false,
        ]);
        $registration = $this->user('registration@coor.test');
        $this->actingAs($registration)
            ->postJson("/api/v1/patients/{$source->id}/merge", ['target_patient_id' => $unrelated->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('target_patient_id');
        $this->assertFalse($source->fresh()->trashed());

        $nurse = $this->user('nurse@coor.test');
        $this->actingAs($nurse)
            ->postJson("/api/v1/patients/{$source->id}/merge", ['target_patient_id' => $unrelated->id])
            ->assertForbidden();
    }

    public function test_api_merge_succeeds_for_a_scored_duplicate_patient_pair(): void
    {
        $source = Patient::where('mrn', 'MRN-2026-000001')->firstOrFail();
        $target = Patient::create([
            'mrn' => 'MRN-API-MERGE-'.uniqid(),
            'first_name' => 'Maria',
            'last_name' => 'Santoss',
            'date_of_birth' => '1987-04-18',
            'sex' => 'Female',
            'verified' => false,
        ]);
        $registration = $this->user('registration@coor.test');

        $this->actingAs($registration)
            ->postJson("/api/v1/patients/{$source->id}/merge", ['target_patient_id' => $target->id])
            ->assertOk()
            ->assertJsonPath('data.id', $target->id);

        $this->assertTrue($source->fresh()->trashed());
        $this->assertSame($target->id, $source->fresh()->merged_into_patient_id);
    }

    public function test_merge_refuses_to_combine_two_distinct_patient_portal_accounts_without_changing_either_record(): void
    {
        $source = Patient::where('mrn', 'MRN-2026-000001')->firstOrFail();
        $targetAccount = User::factory()->create();
        $target = Patient::create([
            'user_id' => $targetAccount->id,
            'mrn' => 'MRN-ACCOUNT-MERGE-'.uniqid(),
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'date_of_birth' => '1987-04-18',
            'sex' => 'Female',
            'verified' => false,
        ]);

        $this->actingAs($this->user('registration@coor.test'))
            ->post(route('patients.merge', $source), ['target_patient_id' => $target->id])
            ->assertSessionHasErrors('target_patient_id');

        $this->assertFalse($source->fresh()->trashed());
        $this->assertNull($source->fresh()->merged_into_patient_id);
        $this->assertSame($targetAccount->id, $target->fresh()->user_id);
    }

    public function test_temporary_er_identity_can_be_merged_into_a_completed_record_by_mrn(): void
    {
        $source = Patient::create([
            'mrn' => 'ER-UNKNOWN-MERGE-001',
            'is_temporary_identity' => true,
            'temporary_identity_label' => 'ER-UNKNOWN-MERGE-001',
            'verified' => false,
        ]);
        $target = Patient::create([
            'mrn' => 'MRN-COMPLETED-'.uniqid(),
            'first_name' => 'John',
            'last_name' => 'Doe',
            'date_of_birth' => '1980-01-01',
            'sex' => 'Male',
            'verified' => true,
        ]);

        $this->actingAs($this->user('registration@coor.test'))
            ->post(route('patients.merge', $source), ['target_mrn' => $target->mrn])
            ->assertRedirect(route('patients.show', $target));

        $this->assertSame($target->id, $source->fresh()->merged_into_patient_id);
        $this->assertSame($target->id, $source->fresh()->mergedInto->id);
    }

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }
}
