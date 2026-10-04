<?php

namespace Tests\Feature\Api;

use App\Models\ErQueue;
use App\Models\ErVisit;
use App\Models\Patient;
use App\Models\Role;
use App\Models\TriageAssessment;
use App\Models\User;
use Database\Seeders\HimsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmergencyVisitReadAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(HimsSeeder::class);
    }

    public function test_clinical_roles_can_read_triage_and_queue_relations(): void
    {
        $visit = $this->createVisit();

        foreach (['nurse', 'doctor', 'super-admin'] as $roleName) {
            $user = User::whereHas('roles', fn ($query) => $query->where('name', $roleName))->firstOrFail();
            Sanctum::actingAs($user);

            $this->getJson("/api/v1/emergency/visits/{$visit->id}")
                ->assertOk()
                ->assertJsonPath('data.triage.priority', 'Level 2')
                ->assertJsonPath('data.queue.priority', 'Level 2')
                ->assertJsonPath('data.patient.id', $visit->patient_id);
        }
    }

    public function test_registration_receives_only_registration_level_visit_fields(): void
    {
        $visit = $this->createVisit();
        $registration = User::where('email', 'registration@coor.test')->firstOrFail();
        Sanctum::actingAs($registration);

        $this->getJson("/api/v1/emergency/visits/{$visit->id}")
            ->assertOk()
            ->assertJsonPath('data.visit_number', $visit->visit_number)
            ->assertJsonPath('data.patient.id', $visit->patient_id)
            ->assertJsonMissingPath('data.triage')
            ->assertJsonMissingPath('data.triage_assessments')
            ->assertJsonMissingPath('data.triage_reassessments')
            ->assertJsonMissingPath('data.queue')
            ->assertJsonMissingPath('data.chief_complaint')
            ->assertJsonMissingPath('data.referral_details');
    }

    public function test_patient_and_unpermitted_roles_cannot_read_emergency_visit_detail(): void
    {
        $visit = $this->createVisit();
        $patientUser = User::factory()->create();
        $patientUser->roles()->attach(Role::where('name', 'patient')->firstOrFail());

        foreach ([$patientUser, User::factory()->create()] as $user) {
            Sanctum::actingAs($user);
            $this->getJson("/api/v1/emergency/visits/{$visit->id}")->assertForbidden();
        }
    }

    private function createVisit(): ErVisit
    {
        $nurse = User::where('email', 'nurse@coor.test')->firstOrFail();
        $patient = Patient::factory()->create();
        $visit = ErVisit::create([
            'visit_number' => 'ER-READ-AUTH-001',
            'patient_id' => $patient->id,
            'arrived_at' => now(),
            'arrival_method' => 'Walk-in',
            'chief_complaint' => 'Private clinical complaint',
            'referral_details' => 'Private clinical referral',
            'status' => ErVisit::STATUS_TRIAGED,
        ]);
        TriageAssessment::create([
            'er_visit_id' => $visit->id,
            'patient_id' => $patient->id,
            'triage_nurse_id' => $nurse->id,
            'triaged_at' => now(),
            'chief_complaint' => 'Private triage complaint',
            'symptoms' => ['pain'],
            'pain_score' => 6,
            'priority' => 'Level 2',
            'priority_score' => 2,
            'triage_color' => 'yellow',
            'final_level' => 'Urgent',
            'ai_suggested_level' => 'Urgent',
            'is_overridden' => false,
            'status' => 'COMPLETE',
        ]);
        ErQueue::create([
            'er_visit_id' => $visit->id,
            'priority' => 'Level 2',
            'status' => ErQueue::STATUS_WAITING,
            'queued_at' => now(),
        ]);

        return $visit;
    }
}
