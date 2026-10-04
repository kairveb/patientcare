<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use App\Services\AiTriageService;
use App\Services\TriageService;
use Database\Seeders\HimsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TriageAgeAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(HimsSeeder::class);
    }

    public function test_pediatric_thresholds_are_adjusted_and_marked_for_clinical_validation(): void
    {
        $service = app(AiTriageService::class);
        $pediatric = $service->analyze([
            'age' => 10,
            'vitals' => ['respiratory_rate' => 35],
        ]);
        $adult = $service->analyze([
            'age' => 35,
            'vitals' => ['respiratory_rate' => 35],
        ]);

        $this->assertSame('Urgent', $pediatric['priority']);
        $this->assertSame('Emergency', $adult['priority']);
        $this->assertStringContainsString('clinical validation', implode(' ', $pediatric['reasons']));
    }

    public function test_geriatric_thresholds_are_adjusted_without_changing_adult_scoring(): void
    {
        $service = app(AiTriageService::class);
        $geriatric = $service->analyze([
            'age' => 70,
            'vitals' => ['heart_rate' => 125],
        ]);
        $adult = $service->analyze([
            'age' => 35,
            'vitals' => ['heart_rate' => 125],
        ]);

        $this->assertSame('Emergency', $geriatric['priority']);
        $this->assertSame('Non-Urgent', $adult['priority']);
        $this->assertStringContainsString('clinical validation', implode(' ', $geriatric['reasons']));
    }

    public function test_triage_api_uses_linked_patient_age_and_validates_age_inputs_and_role(): void
    {
        $nurse = $this->userWithRole('nurse');
        $patient = Patient::factory()->create([
            'date_of_birth' => now()->subYears(70)->toDateString(),
        ]);

        $this->actingAs($nurse)->postJson('/api/v1/triage/score', [
            'patient_id' => $patient->id,
            'age' => 35,
            'vitals' => ['blood_pressure' => '120/80', 'heart_rate' => 125],
        ])->assertOk()
            ->assertJsonPath('data.priority', 'Emergency')
            ->assertJsonPath('data.reasons.1', 'Geriatric age-adjusted vital-sign thresholds are provisional and require clinical validation.');

        $this->actingAs($nurse)->postJson('/api/v1/triage/score', [
            'age' => 121,
            'vitals' => ['heart_rate' => 80],
        ])->assertUnprocessable()->assertJsonValidationErrors('age');

        $registration = $this->userWithRole('registration');
        $this->actingAs($registration)->postJson('/api/v1/triage/score', [
            'patient_id' => $patient->id,
            'vitals' => ['heart_rate' => 80],
        ])->assertForbidden();
    }

    public function test_er_triage_score_derives_age_from_the_linked_patient_record(): void
    {
        $patient = Patient::factory()->create([
            'date_of_birth' => now()->subYears(70)->toDateString(),
        ]);

        $result = app(TriageService::class)->score([
            'patient_id' => $patient->id,
            'vitals' => ['heart_rate' => 125],
        ]);

        $this->assertSame('Emergency', $result['priority']);
        $this->assertStringContainsString('Geriatric age-adjusted', implode(' ', $result['reasons']));
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create(['email' => $roleName.'-'.Str::uuid().'@example.test']);
        $user->roles()->attach(Role::where('name', $roleName)->firstOrFail());

        return $user;
    }
}
