<?php

namespace Tests\Feature;

use App\Models\Encounter;
use App\Models\Patient;
use App\Models\Provider;
use App\Models\User;
use App\Services\EncounterService;
use Database\Seeders\HimsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class EncounterClinicalSafeguardsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(HimsSeeder::class);
    }

    public function test_only_doctors_can_create_encounters_and_only_for_their_own_provider_profile(): void
    {
        $doctor = User::where('email', 'doctor@coor.test')->firstOrFail();
        $nurse = User::where('email', 'nurse@coor.test')->firstOrFail();
        $patient = Patient::query()->firstOrFail();
        $otherProvider = Provider::create([
            'user_id' => User::factory()->create()->id,
            'display_name' => 'Dr. Other Provider',
            'active' => true,
        ]);

        $payload = [
            'patient_id' => $patient->id,
            'provider_id' => $doctor->provider->id,
            'type' => Encounter::TYPE_OUTPATIENT,
            'chief_complaint' => 'Persistent headache',
        ];

        $this->actingAs($nurse)->postJson('/api/v1/encounters', $payload)->assertForbidden();

        $this->actingAs($doctor)->postJson('/api/v1/encounters', array_merge($payload, [
            'provider_id' => $otherProvider->id,
        ]))->assertForbidden();

        $this->actingAs($doctor)->postJson('/api/v1/encounters', $payload)
            ->assertCreated()
            ->assertJsonPath('data.provider_id', $doctor->provider->id)
            ->assertJsonPath('data.status', 'OPEN');
    }

    public function test_encounter_creation_controls_and_routes_are_restricted_to_doctors(): void
    {
        $doctor = User::where('email', 'doctor@coor.test')->firstOrFail();
        $nurse = User::where('email', 'nurse@coor.test')->firstOrFail();
        $superAdmin = User::where('email', 'super-admin@coor.test')->firstOrFail();

        $this->actingAs($nurse)->get('/encounters')
            ->assertOk()
            ->assertDontSee('New Encounter')
            ->assertDontSee('id="encounterModal"');
        $this->actingAs($nurse)->get('/encounters/create')->assertForbidden();
        $this->actingAs($superAdmin)->get('/encounters/create')->assertForbidden();

        $this->actingAs($doctor)->get('/encounters')
            ->assertOk()
            ->assertSee('New Encounter')
            ->assertSee($doctor->provider->full_name);
        $this->actingAs($doctor)->get('/encounters/create')->assertOk();
    }

    public function test_encounters_cannot_be_completed_without_required_clinical_documentation(): void
    {
        $doctor = User::where('email', 'doctor@coor.test')->firstOrFail();
        $encounter = $this->createOpenEncounter($doctor);

        $this->actingAs($doctor)
            ->from(route('encounters.show', $encounter))
            ->post(route('encounters.complete', $encounter), [
                'history_of_present_illness' => ' ',
                'physical_examination' => '',
                'primary_diagnosis_code' => '',
                'primary_diagnosis_description' => '',
                'assessment' => '   ',
                'plan' => '',
            ])
            ->assertRedirect(route('encounters.show', $encounter))
            ->assertSessionHasErrors([
                'history_of_present_illness',
                'physical_examination',
                'primary_diagnosis_code',
                'primary_diagnosis_description',
                'assessment',
                'plan',
            ]);

        $this->assertDatabaseHas('encounters', [
            'id' => $encounter->id,
            'status' => 'OPEN',
            'assessment' => null,
            'plan' => null,
        ]);

        $this->actingAs($doctor)->post(route('encounters.complete', $encounter), [
            'history_of_present_illness' => 'Intermittent headache for two days.',
            'physical_examination' => 'Alert, oriented, no focal neurologic deficit.',
            'primary_diagnosis_code' => 'R51.9',
            'primary_diagnosis_description' => 'Headache, unspecified',
            'assessment' => 'Tension headache without neurologic deficit.',
            'plan' => 'Hydration, rest, and follow-up in one week.',
        ])->assertRedirect();

        $this->assertDatabaseHas('encounters', [
            'id' => $encounter->id,
            'status' => 'COMPLETED',
            'assessment' => 'Tension headache without neurologic deficit.',
            'plan' => 'Hydration, rest, and follow-up in one week.',
            'primary_diagnosis_code' => 'R51.9',
        ]);
    }

    public function test_super_admin_cannot_complete_encounters_through_web_or_api(): void
    {
        $superAdmin = User::where('email', 'super-admin@coor.test')->firstOrFail();
        $doctor = User::where('email', 'doctor@coor.test')->firstOrFail();
        $webEncounter = $this->createOpenEncounter($doctor);
        $apiEncounter = $this->createOpenEncounter($doctor);
        $documentation = [
            'history_of_present_illness' => 'Headache for two days.',
            'physical_examination' => 'No acute findings.',
            'primary_diagnosis_code' => 'R51.9',
            'primary_diagnosis_description' => 'Headache, unspecified',
            'assessment' => 'Stable.',
            'plan' => 'Follow up as needed.',
        ];

        $this->actingAs($superAdmin)->get(route('encounters.show', $webEncounter))
            ->assertOk()
            ->assertDontSee('Complete Encounter')
            ->assertDontSee('id="encounter-completion-form"');

        $webResponse = $this->actingAs($superAdmin)
            ->post(route('encounters.complete', $webEncounter), $documentation);
        $apiResponse = $this->actingAs($superAdmin)
            ->postJson("/api/v1/encounters/{$apiEncounter->id}/complete", $documentation);

        $webResponse->assertForbidden();
        $apiResponse->assertForbidden();
        $this->assertSame('OPEN', $webEncounter->fresh()->status);
        $this->assertSame('OPEN', $apiEncounter->fresh()->status);

        try {
            app(EncounterService::class)->complete($apiEncounter, $documentation, $superAdmin);
            $this->fail('The service must reject Super-Admin encounter completion.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_non_assigned_roles_receive_matching_web_and_api_completion_denials(): void
    {
        $doctor = User::where('email', 'doctor@coor.test')->firstOrFail();
        $documentation = [
            'history_of_present_illness' => 'Headache for two days.',
            'physical_examination' => 'No acute findings.',
            'primary_diagnosis_code' => 'R51.9',
            'primary_diagnosis_description' => 'Headache, unspecified',
            'assessment' => 'Stable.',
            'plan' => 'Follow up as needed.',
        ];

        foreach (['nurse@coor.test', 'registration@coor.test', 'patient@coor.test'] as $email) {
            $user = User::where('email', $email)->firstOrFail();
            $encounter = $this->createOpenEncounter($doctor);

            $this->actingAs($user)->post(route('encounters.complete', $encounter), $documentation)
                ->assertForbidden();
            $this->actingAs($user)->postJson("/api/v1/encounters/{$encounter->id}/complete", $documentation)
                ->assertForbidden();
            $this->assertDatabaseHas('encounters', ['id' => $encounter->id, 'status' => Encounter::STATUS_OPEN]);
        }
    }

    public function test_completion_controls_are_hidden_from_nurses_and_doctors_cannot_complete_another_providers_encounter(): void
    {
        $doctor = User::where('email', 'doctor@coor.test')->firstOrFail();
        $nurse = User::where('email', 'nurse@coor.test')->firstOrFail();
        $encounter = $this->createOpenEncounter($doctor);

        $this->actingAs($nurse)->get(route('encounters.show', $encounter))
            ->assertOk()
            ->assertDontSee('Complete Encounter')
            ->assertDontSee('name="assessment"')
            ->assertDontSee('name="plan"');
        $this->actingAs($nurse)->post(route('encounters.complete', $encounter), [
            'assessment' => 'Assessment text.',
            'plan' => 'Plan text.',
        ])->assertForbidden();

        $this->actingAs($doctor)->get(route('encounters.show', $encounter))
            ->assertOk()
            ->assertSee('Complete Encounter')
            ->assertSee('Assessment *')
            ->assertSee('Plan *');

        $otherProvider = Provider::create([
            'user_id' => User::factory()->create()->id,
            'display_name' => 'Dr. Encounter Owner',
            'active' => true,
        ]);
        $encounter->update(['provider_id' => $otherProvider->id]);

        $this->actingAs($doctor)->get(route('encounters.show', $encounter))
            ->assertOk()
            ->assertDontSee('Complete Encounter')
            ->assertDontSee('name="assessment"');

        $this->actingAs($doctor)->post(route('encounters.complete', $encounter), [
            'history_of_present_illness' => 'Symptoms began yesterday.',
            'physical_examination' => 'No acute findings.',
            'primary_diagnosis_code' => 'R51.9',
            'primary_diagnosis_description' => 'Headache, unspecified',
            'assessment' => 'Assessment text.',
            'plan' => 'Plan text.',
        ])->assertForbidden();
    }

    public function test_api_rejects_completion_and_admission_requests_for_another_doctors_encounter(): void
    {
        $doctor = User::where('email', 'doctor@coor.test')->firstOrFail();
        $otherDoctor = User::factory()->create();
        $otherDoctor->roles()->syncWithoutDetaching([$doctor->roles()->where('name', 'doctor')->value('roles.id')]);
        Provider::create([
            'user_id' => $otherDoctor->id,
            'display_name' => 'Dr. Other Owner',
            'active' => true,
        ]);
        $encounter = $this->createOpenEncounter($doctor);
        $documentation = [
            'history_of_present_illness' => 'Headache for two days.',
            'physical_examination' => 'No acute findings.',
            'primary_diagnosis_code' => 'R51.9',
            'primary_diagnosis_description' => 'Headache, unspecified',
            'assessment' => 'Stable.',
            'plan' => 'Follow up as needed.',
        ];

        $this->actingAs($otherDoctor)->postJson("/api/v1/encounters/{$encounter->id}/complete", $documentation)
            ->assertForbidden();
        $this->actingAs($otherDoctor)->postJson("/api/v1/encounters/{$encounter->id}/admission-request", [
            'reason' => 'Requires admission.',
        ])->assertForbidden();

        $this->assertDatabaseHas('encounters', ['id' => $encounter->id, 'status' => Encounter::STATUS_OPEN]);
        $this->assertDatabaseMissing('admissions', ['encounter_id' => $encounter->id]);
    }

    public function test_new_encounter_modal_restores_input_and_shows_validation_errors(): void
    {
        $doctor = User::where('email', 'doctor@coor.test')->firstOrFail();
        $patient = Patient::query()->firstOrFail();

        $response = $this->actingAs($doctor)
            ->from(route('encounters.index'))
            ->followingRedirects()
            ->post(route('encounters.store'), [
                'patient_id' => $patient->id,
                'provider_id' => $doctor->provider->id,
                'type' => Encounter::TYPE_OUTPATIENT,
                'primary_diagnosis_code' => 'not-an-icd-code',
                'chief_complaint' => 'Retain this complaint',
            ]);

        $response->assertOk()
            ->assertSee('not-an-icd-code')
            ->assertSee('The primary diagnosis code must be a valid ICD-10 code format.')
            ->assertSee('Retain this complaint')
            ->assertSee('getOrCreateInstance(modal).show()');
    }

    public function test_api_and_service_reject_invalid_icd10_format_and_normalize_valid_codes(): void
    {
        $doctor = User::where('email', 'doctor@coor.test')->firstOrFail();
        $patient = Patient::query()->firstOrFail();
        $payload = [
            'patient_id' => $patient->id,
            'provider_id' => $doctor->provider->id,
            'type' => Encounter::TYPE_OUTPATIENT,
            'primary_diagnosis_code' => 'R5?.9',
        ];

        $this->actingAs($doctor)->postJson('/api/v1/encounters', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['primary_diagnosis_code']);

        $validEncounter = app(EncounterService::class)->create(array_merge($payload, [
            'primary_diagnosis_code' => 'r51.9',
        ]));
        $this->assertSame('R51.9', $validEncounter->primary_diagnosis_code);

        $invalidEncounter = $this->createOpenEncounter($doctor);
        try {
            app(EncounterService::class)->complete($invalidEncounter, [
                'history_of_present_illness' => 'Symptoms for two days.',
                'physical_examination' => 'No acute findings.',
                'primary_diagnosis_code' => 'R5?.9',
                'primary_diagnosis_description' => 'Invalid code',
                'assessment' => 'Stable.',
                'plan' => 'Follow up.',
            ], $doctor);
            $this->fail('EncounterService must reject invalid diagnosis code formats.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('primary_diagnosis_code', $exception->errors());
        }
    }

    public function test_encounter_service_rejects_completion_when_clinical_documentation_is_missing(): void
    {
        $doctor = User::where('email', 'doctor@coor.test')->firstOrFail();
        $encounter = $this->createOpenEncounter($doctor);

        try {
            app(EncounterService::class)->complete($encounter, ['assessment' => 'Assessment only.'], $doctor);
            $this->fail('EncounterService must reject incomplete completion documentation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('plan', $exception->errors());
        }

        $this->assertDatabaseHas('encounters', [
            'id' => $encounter->id,
            'status' => 'OPEN',
        ]);
    }

    private function createOpenEncounter(User $doctor): Encounter
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
