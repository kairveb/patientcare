<?php

namespace Tests\Feature;

use App\Events\EmergencyQueueAlert;
use App\Models\AuditLog;
use App\Models\ErVisit;
use App\Models\Patient;
use App\Models\Role;
use App\Models\TriageAssessment;
use App\Models\TriageReassessment;
use App\Models\User;
use Database\Seeders\HimsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class TriageReassessmentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(HimsSeeder::class);
    }

    public function test_nurse_records_separate_ai_reassessment_and_doctor_confirms_before_final_level_changes(): void
    {
        Event::fake([EmergencyQueueAlert::class]);
        $nurse = $this->userWithRole('nurse');
        $doctor = $this->userWithRole('doctor');
        [$visit, $assessment] = $this->activeVisit($nurse);

        $response = $this->actingAs($nurse)->post(route('emergency.reassessments.store', $visit), [
            'vitals' => [
                'blood_pressure' => '120/80',
                'heart_rate' => 80,
                'respiratory_rate' => 16,
                'temperature' => 37.0,
                'spo2' => 85,
                'gcs' => 8,
            ],
        ]);

        $reassessment = TriageReassessment::firstOrFail();
        $response->assertRedirect(route('emergency.show', $visit));
        $this->assertSame($visit->id, $reassessment->er_visit_id);
        $this->assertSame($assessment->id, $reassessment->triage_assessment_id);
        $this->assertSame($nurse->id, $reassessment->recorded_by);
        $this->assertSame('Emergency', $reassessment->ai_suggested_level);
        $this->assertSame('Routine', $reassessment->current_final_level);
        $this->assertTrue($reassessment->more_severe);
        $this->assertSame(TriageReassessment::STATUS_PENDING, $reassessment->status);
        $this->assertSame('Routine', $assessment->fresh()->final_level);
        $this->assertSame('Level 5', $assessment->fresh()->priority);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'CREATE_TRIAGE_REASSESSMENT',
            'resource_id' => $reassessment->id,
            'user_id' => $nurse->id,
        ]);
        Event::assertDispatched(EmergencyQueueAlert::class, fn (EmergencyQueueAlert $event): bool =>
            $event->priority === 'Level 1' && str_contains($event->message, 'more severe')
        );

        $this->actingAs($nurse)->get(route('emergency.show', $visit))
            ->assertOk()
            ->assertSee('AI Reassessment History')
            ->assertSee('clinician review alerted.')
            ->assertSee('Confirm clinical decision');

        $this->actingAs($doctor)
            ->post(route('emergency.reassessments.confirm', [$visit, $reassessment]), [
                'final_level' => 'Emergency',
            ])
            ->assertRedirect(route('emergency.show', $visit));

        $this->assertSame(TriageReassessment::STATUS_CONFIRMED, $reassessment->fresh()->status);
        $this->assertSame('Emergency', $reassessment->fresh()->final_level);
        $this->assertSame($doctor->id, $reassessment->fresh()->confirmed_by);
        $this->assertSame('Emergency', $assessment->fresh()->final_level);
        $this->assertSame('Level 1', $assessment->fresh()->priority);
        $this->assertSame($doctor->id, AuditLog::where('action', 'CONFIRM_TRIAGE_DECISION')->firstOrFail()->user_id);
    }

    public function test_confirming_a_different_level_requires_reason_and_records_clinical_override(): void
    {
        $nurse = $this->userWithRole('nurse');
        [$visit, $assessment] = $this->activeVisit($nurse);
        $reassessment = $this->recordEmergencyReassessment($nurse, $visit);
        $this->actingAs($nurse);

        $this->from(route('emergency.show', $visit))
            ->post(route('emergency.reassessments.confirm', [$visit, $reassessment]), [
                'final_level' => 'Routine',
            ])
            ->assertSessionHasErrors('override_reason');
        $this->from(route('emergency.show', $visit))
            ->post(route('emergency.reassessments.confirm', [$visit, $reassessment]), [
                'final_level' => 'Routine',
                'override_reason' => 'Too short',
            ])
            ->assertSessionHasErrors('override_reason');

        $this->assertSame('Routine', $assessment->fresh()->final_level);
        $this->assertSame(TriageReassessment::STATUS_PENDING, $reassessment->fresh()->status);

        $this->post(route('emergency.reassessments.confirm', [$visit, $reassessment]), [
            'final_level' => 'Routine',
            'override_reason' => 'Patient stabilized after immediate intervention.',
        ])->assertRedirect(route('emergency.show', $visit));

        $this->assertSame('Routine', $assessment->fresh()->final_level);
        $this->assertTrue($assessment->fresh()->is_overridden);
        $this->assertSame('Patient stabilized after immediate intervention.', $reassessment->fresh()->override_reason);
        $this->assertSame(TriageReassessment::STATUS_CONFIRMED, $reassessment->fresh()->status);
    }

    public function test_reassessment_validates_vitals_and_active_visit_and_forbids_registration_staff(): void
    {
        $nurse = $this->userWithRole('nurse');
        $registration = $this->userWithRole('registration');
        [$visit] = $this->activeVisit($nurse);

        $this->actingAs($registration)
            ->post(route('emergency.reassessments.store', $visit), [])
            ->assertForbidden();
        $this->actingAs($nurse)
            ->from(route('emergency.show', $visit))
            ->post(route('emergency.reassessments.store', $visit), [
                'vitals' => ['blood_pressure' => '120/80', 'heart_rate' => 500],
            ])
            ->assertSessionHasErrors(['vitals.heart_rate', 'vitals.respiratory_rate', 'vitals.temperature', 'vitals.spo2']);
        $this->assertSame(0, TriageReassessment::count());

        $visit->update(['status' => ErVisit::STATUS_DISCHARGED]);
        $this->actingAs($nurse)
            ->post(route('emergency.reassessments.store', $visit), $this->vitalsPayload())
            ->assertStatus(409);
        $this->assertSame(0, TriageReassessment::count());
    }

    private function recordEmergencyReassessment(User $nurse, ErVisit $visit): TriageReassessment
    {
        $this->actingAs($nurse)->post(route('emergency.reassessments.store', $visit), $this->vitalsPayload());

        return TriageReassessment::firstOrFail();
    }

    private function activeVisit(User $nurse): array
    {
        $patient = Patient::factory()->create();
        $visit = ErVisit::create([
            'visit_number' => 'ER-REASSESS-'.Str::upper(Str::random(8)),
            'patient_id' => $patient->id,
            'arrived_at' => now(),
            'chief_complaint' => 'Cough',
            'status' => ErVisit::STATUS_TRIAGED,
        ]);
        $assessment = TriageAssessment::create([
            'er_visit_id' => $visit->id,
            'patient_id' => $patient->id,
            'triage_nurse_id' => $nurse->id,
            'triaged_at' => now()->subMinutes(10),
            'chief_complaint' => 'Cough',
            'symptoms' => ['cough'],
            'pain_score' => 1,
            'priority' => 'Level 5',
            'priority_score' => 5,
            'triage_color' => 'green',
            'final_level' => 'Routine',
            'ai_suggested_level' => 'Routine',
            'is_overridden' => false,
            'status' => 'COMPLETE',
        ]);

        return [$visit, $assessment];
    }

    private function vitalsPayload(): array
    {
        return [
            'vitals' => [
                'blood_pressure' => '120/80',
                'heart_rate' => 80,
                'respiratory_rate' => 16,
                'temperature' => 37.0,
                'spo2' => 85,
                'gcs' => 8,
            ],
        ];
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create(['email' => $roleName.'-'.Str::uuid().'@example.test']);
        $user->roles()->attach(Role::where('name', $roleName)->firstOrFail());

        return $user;
    }
}
