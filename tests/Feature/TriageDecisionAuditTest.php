<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ErVisit;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TriageAssessment;
use App\Models\TriageVital;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use App\Services\TriageService;
use App\Support\TriageDecisionRules;
use Tests\TestCase;

class TriageDecisionAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_nurse_accepting_ai_suggestion_persists_decision_and_audit_record(): void
    {
        $nurse = $this->userWithRole('nurse');
        $patient = $this->patient();

        $this->actingAs($nurse)->post(route('triage.store'), $this->triagePayload($patient, [
            'priority_override' => 'Non-Urgent',
        ]))->assertRedirect();

        $assessment = TriageAssessment::where('patient_id', $patient->id)->firstOrFail();
        $this->assertSame('Non-Urgent', $assessment->ai_suggested_level);
        $this->assertSame('Non-Urgent', $assessment->final_level);
        $this->assertFalse($assessment->is_overridden);
        $this->assertNull($assessment->override_reason);
        $this->assertNull($assessment->overridden_by);
        $this->assertNotNull($assessment->ai_confidence);
        $this->assertNotEmpty($assessment->ai_reasons);

        $log = AuditLog::where('action', 'CONFIRM_TRIAGE_DECISION')->firstOrFail();
        $this->assertSame($nurse->id, $log->user_id);
        $this->assertSame($assessment->id, $log->resource_id);
        $this->assertSame('Non-Urgent', $log->metadata['ai_suggested_level']);
        $this->assertSame('Non-Urgent', $log->metadata['final_level']);
        $this->assertNull($log->metadata['override_reason']);
    }

    public function test_doctor_can_override_with_reason_and_decision_is_audited(): void
    {
        $doctor = $this->userWithRole('doctor');
        $patient = $this->patient();
        $visit = ErVisit::create([
            'visit_number' => 'ER-DECISION-'.Str::uuid(),
            'patient_id' => $patient->id,
            'arrived_at' => now(),
            'arrival_method' => 'Walk-in',
            'chief_complaint' => 'Cold follow-up',
            'status' => ErVisit::STATUS_ARRIVED,
        ]);

        $this->actingAs($doctor)->post(route('emergency.triage', $visit), $this->triagePayload($patient, [
            'priority_override' => 'Emergency',
            'override_reason' => 'Patient appears acutely unstable.',
        ]))->assertRedirect();

        $assessment = TriageAssessment::where('patient_id', $patient->id)->firstOrFail();
        $this->assertSame('Non-Urgent', $assessment->ai_suggested_level);
        $this->assertSame('Emergency', $assessment->final_level);
        $this->assertTrue($assessment->is_overridden);
        $this->assertSame('Patient appears acutely unstable.', $assessment->override_reason);
        $this->assertSame($doctor->id, $assessment->overridden_by);
        $this->assertNotNull($assessment->overridden_at);

        $this->assertSame(
            'Patient appears acutely unstable.',
            AuditLog::where('action', 'CONFIRM_TRIAGE_DECISION')->firstOrFail()->metadata['override_reason']
        );
    }

    public function test_override_reason_is_required_and_disallowed_when_ai_level_is_accepted(): void
    {
        $nurse = $this->userWithRole('nurse');
        $patient = $this->patient();
        $this->actingAs($nurse);

        $this->from(route('triage.create'))->post(route('triage.store'), $this->triagePayload($patient, [
            'priority_override' => 'Emergency',
        ]))->assertSessionHasErrors('override_reason');

        $this->from(route('triage.create'))->post(route('triage.store'), $this->triagePayload($patient, [
            'priority_override' => 'Emergency',
            'override_reason' => 'Too short',
        ]))->assertSessionHasErrors('override_reason');

        $this->from(route('triage.create'))->post(route('triage.store'), $this->triagePayload($patient, [
            'priority_override' => 'Non-Urgent',
            'override_reason' => 'No override happened.',
        ]))->assertSessionHasErrors('override_reason');

        $this->assertSame(0, TriageAssessment::count());
        $this->assertSame(0, AuditLog::where('action', 'CONFIRM_TRIAGE_DECISION')->count());
    }

    public function test_patient_and_registration_staff_cannot_confirm_triage(): void
    {
        $patient = $this->patient();
        $payload = $this->triagePayload($patient, ['priority_override' => 'Non-Urgent']);

        foreach (['patient', 'registration'] as $roleName) {
            $this->actingAs($this->userWithRole($roleName))
                ->post(route('triage.store'), $payload)
                ->assertForbidden();
        }

        $this->assertSame(0, TriageAssessment::count());
    }

    public function test_super_admin_web_triage_saves_symptoms_pain_blood_pressure_and_color_priority(): void
    {
        $admin = $this->userWithRole('super-admin');
        $patient = $this->patient();
        $this->actingAs($admin);

        $payload = $this->triagePayload($patient, [
            'chief_complaint' => 'Feeling unwell',
            'symptoms' => 'dsadasd',
            'pain_score' => 5,
            'blood_pressure' => '80/23',
            'priority' => 'Red',
        ]);
        if (TriageDecisionRules::normalizeLevel(app(TriageService::class)->score([
            'patient_id' => $patient->id,
            'chief_complaint' => 'Feeling unwell',
            'symptoms' => ['dsadasd'],
            'pain_score' => 5,
            'vitals' => ['blood_pressure' => '80/23'],
        ])['priority']) !== 'Emergency') {
            $payload['override_reason'] = 'Clinical review confirmed emergency priority.';
        }

        $this->post(route('triage.store'), $payload)
            ->assertRedirect();

        $assessment = TriageAssessment::where('patient_id', $patient->id)->firstOrFail();
        $this->assertSame(['dsadasd'], $assessment->symptoms);
        $this->assertSame(5, $assessment->pain_score);
        $this->assertSame('Level 1', $assessment->priority);
        $this->assertSame('Emergency', $assessment->final_level);
        $this->assertSame('80/23', TriageVital::where('triage_assessment_id', $assessment->id)->value('blood_pressure'));
    }

    public function test_nurse_api_triage_persists_the_same_clinical_inputs(): void
    {
        $nurse = $this->userWithRole('nurse');
        $patient = $this->patient();
        $visit = ErVisit::create([
            'visit_number' => 'ER-API-'.Str::uuid(),
            'patient_id' => $patient->id,
            'arrived_at' => now(),
            'arrival_method' => 'Walk-in',
            'chief_complaint' => 'Feeling unwell',
            'status' => ErVisit::STATUS_ARRIVED,
        ]);
        $payload = [
            'patient_id' => $patient->id,
            'chief_complaint' => 'Feeling unwell',
            'symptoms' => ['dsadasd'],
            'pain_score' => 5,
            'vitals' => ['blood_pressure' => '80/23'],
            'priority' => 'Red',
            'ai_confirmed' => '1',
        ];
        if (TriageDecisionRules::normalizeLevel(app(TriageService::class)->score([
            'patient_id' => $patient->id,
            'chief_complaint' => 'Feeling unwell',
            'symptoms' => ['dsadasd'],
            'pain_score' => 5,
            'vitals' => ['blood_pressure' => '80/23'],
        ])['priority']) !== 'Emergency') {
            $payload['override_reason'] = 'Clinical review confirmed emergency priority.';
        }

        $this->actingAs($nurse)->postJson("/api/v1/emergency/{$visit->id}/triage", $payload)
            ->assertCreated()
            ->assertJsonPath('data.priority', 'Level 1')
            ->assertJsonPath('data.symptoms', ['dsadasd'])
            ->assertJsonPath('data.pain_score', 5)
            ->assertJsonPath('data.vitals.blood_pressure', '80/23');
    }

    public function test_triage_validation_errors_are_visible_after_redirect(): void
    {
        $nurse = $this->userWithRole('nurse');
        $patient = $this->patient();

        $response = $this->actingAs($nurse)
            ->from(route('triage.create'))
            ->followingRedirects()
            ->post(route('triage.store'), [
                'patient_id' => $patient->id,
                'chief_complaint' => 'Cold follow-up',
                'symptoms' => 'cold',
                'pain_score' => 1,
            ]);

        $response->assertOk()
            ->assertSee('accepted');
    }

    private function userWithRole(string $roleName): User
    {
        $role = Role::firstOrCreate(['name' => $roleName], ['label' => ucfirst($roleName)]);
        $permission = Permission::firstOrCreate(['name' => 'triage-patients'], ['label' => 'Triage Patients']);
        $permissions = [$permission->id];
        if (in_array($roleName, ['nurse', 'doctor'], true)) {
            $viewErPermission = Permission::firstOrCreate(
                ['name' => 'view-er'],
                ['label' => 'View ER']
            );
            $permissions[] = $viewErPermission->id;
        }
        if (in_array($roleName, ['nurse', 'super-admin'], true)) {
            $createErPermission = Permission::firstOrCreate(
                ['name' => 'create-er-visits'],
                ['label' => 'Create ER Visits']
            );
            $permissions[] = $createErPermission->id;
        }
        $role->permissions()->syncWithoutDetaching($permissions);

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
            'mrn' => 'MRN-DECISION-'.Str::uuid(),
            'first_name' => 'Taylor',
            'last_name' => 'Patient',
            'date_of_birth' => '1992-05-10',
            'sex' => 'Female',
            'verified' => true,
        ]);
    }

    private function triagePayload(Patient $patient, array $overrides = []): array
    {
        return array_merge([
            'patient_id' => $patient->id,
            'chief_complaint' => 'Cold follow-up',
            'symptoms' => 'cold',
            'pain_score' => 1,
            'ai_confirmed' => '1',
        ], $overrides);
    }
}
