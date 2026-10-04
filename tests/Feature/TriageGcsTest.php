<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TriageAssessment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TriageGcsTest extends TestCase
{
    use RefreshDatabase;

    public function test_gcs_eight_or_below_is_a_critical_ai_red_flag(): void
    {
        $nurse = $this->userWithRole('nurse');

        $response = $this->actingAs($nurse)->postJson('/api/v1/triage/score', [
            'chief_complaint' => 'Routine follow-up',
            'vitals' => ['gcs' => 8],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.level', 1)
            ->assertJsonPath('data.priority', 'Emergency');
        $this->assertStringContainsString('GCS', implode(' ', $response->json('data.reasons')));
    }

    public function test_triage_persists_valid_gcs_and_ai_reason(): void
    {
        $nurse = $this->userWithRole('nurse');
        $patient = $this->patient();

        $this->actingAs($nurse)->post(route('triage.store'), [
            'patient_id' => $patient->id,
            'chief_complaint' => 'Routine follow-up',
            'symptoms' => 'follow-up',
            'gcs' => 8,
            'priority_override' => 'Emergency',
            'ai_confirmed' => '1',
        ])->assertRedirect();

        $assessment = TriageAssessment::where('patient_id', $patient->id)->firstOrFail();
        $this->assertSame(8, $assessment->gcs);
        $this->assertSame('Emergency', $assessment->ai_suggested_level);
        $this->assertStringContainsString('GCS', implode(' ', $assessment->ai_reasons));
    }

    public function test_gcs_outside_three_to_fifteen_is_rejected(): void
    {
        $nurse = $this->userWithRole('nurse');

        $this->actingAs($nurse)->postJson('/api/v1/triage/score', [
            'chief_complaint' => 'Routine follow-up',
            'vitals' => ['gcs' => 2],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('vitals.gcs');
    }

    public function test_patient_cannot_submit_gcs_triage(): void
    {
        $patientUser = $this->userWithRole('patient');
        $patient = $this->patient();

        $this->actingAs($patientUser)->post(route('triage.store'), [
            'patient_id' => $patient->id,
            'chief_complaint' => 'Routine follow-up',
            'gcs' => 8,
            'priority_override' => 'Emergency',
            'ai_confirmed' => '1',
        ])->assertForbidden();

        $this->assertSame(0, TriageAssessment::count());
    }

    private function userWithRole(string $roleName): User
    {
        $role = Role::firstOrCreate(['name' => $roleName], ['label' => ucfirst($roleName)]);
        $permission = Permission::firstOrCreate(['name' => 'triage-patients'], ['label' => 'Triage Patients']);
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $user = User::factory()->create([
            'email' => $roleName.'.'.Str::uuid().'@example.test',
            'email_verified_at' => now(),
        ]);
        $user->roles()->attach($role->id);

        return $user;
    }

    private function patient(): Patient
    {
        return Patient::create([
            'mrn' => 'MRN-GCS-'.Str::uuid(),
            'first_name' => 'Jordan',
            'last_name' => 'Patient',
            'date_of_birth' => '1995-02-14',
            'sex' => 'Male',
            'verified' => true,
        ]);
    }
}
