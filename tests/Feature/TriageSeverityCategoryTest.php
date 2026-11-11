<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\TriageAssessment;
use App\Models\User;
use App\Services\AiTriageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TriageSeverityCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_severity_category_is_derived_from_the_five_priority_levels(): void
    {
        $this->assertSame('Severe (critical)', TriageAssessment::severityCategoryFor('Emergency'));
        $this->assertSame('Severe (critical)', TriageAssessment::severityCategoryFor('Urgent'));
        foreach (['Prompt', 'Non-Urgent', 'Routine'] as $level) {
            $this->assertSame('Not Severe', TriageAssessment::severityCategoryFor($level));
        }

        $assessment = new TriageAssessment(['final_level' => 'Emergency', 'priority_score' => 1]);
        $this->assertSame('Severe (critical)', $assessment->toArray()['severity_category']);
    }

    public function test_triage_api_score_returns_the_derived_category(): void
    {
        $role = Role::firstOrCreate(['name' => 'nurse'], ['label' => 'Nurse']);
        $permission = Permission::firstOrCreate(['name' => 'triage-patients'], ['label' => 'Triage Patients']);
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $user = User::factory()->create();
        $user->roles()->attach($role);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/triage/score', [
                'chief_complaint' => 'chest pain',
                'symptoms' => ['chest pain'],
            ])
            ->assertOk()
            ->assertJsonPath('data.severity_category', 'Severe (critical)');

        $routine = app(AiTriageService::class)->analyze(['chief_complaint' => 'mild rash']);
        $this->assertSame('Not Severe', $routine['severity_category']);
    }
}
