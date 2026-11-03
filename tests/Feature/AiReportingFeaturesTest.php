<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Provider;
use App\Models\Role;
use App\Models\SymptomCheck;
use App\Models\User;
use App\Services\AiEvaluationService;
use App\Services\PatientConsentService;
use Database\Seeders\HimsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiReportingFeaturesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(HimsSeeder::class);
    }

    public function test_report_csv_neutralizes_formula_cells_and_audits_filters_without_content(): void
    {
        $admin = $this->userWithRole('registration');
        $this->grantReports($admin);
        $patient = Patient::factory()->create();
        $provider = Provider::factory()->create(['display_name' => '=HYPERLINK("https://bad.test")']);
        Appointment::factory()->create([
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
            'starts_at' => now(),
            'ends_at' => now()->addMinutes(30),
            'status' => Appointment::STATUS_CONFIRMED,
        ]);

        $response = $this->withSession(['auth.password_confirmed_at' => time()])
            ->actingAs($admin)
            ->get(route('reports.export', [
                'report' => 'appointments',
                'format' => 'csv',
                'start' => today()->toDateString(),
                'end' => today()->toDateString(),
            ]))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString('patient name', strtolower($csv));
        $log = \App\Models\AuditLog::query()->where('action', 'EXPORT_REPORT')->sole();
        $this->assertSame('appointments', $log->metadata['report']);
        $this->assertSame(today()->toDateString(), $log->metadata['filters']['start']);
        $this->assertStringNotContainsString('HYPERLINK', json_encode($log->metadata, JSON_THROW_ON_ERROR));
    }

    public function test_report_export_requires_recent_password_confirmation_and_limits_failed_attempts(): void
    {
        $admin = $this->userWithRole('registration', ['password' => 'ValidPass#123']);
        $this->grantReports($admin);
        $exportUrl = route('reports.export', ['report' => 'appointments', 'format' => 'csv']);

        $this->actingAs($admin)->get($exportUrl)->assertRedirect(route('password.confirm'));

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->actingAs($admin)->post(route('password.confirm'), ['password' => 'incorrect'])
                ->assertSessionHasErrors('password');
        }
        $this->assertSame(5, \App\Models\AuditLog::query()->where('action', 'REPORT_PASSWORD_CONFIRM_FAILED')->count());
        $this->actingAs($admin)->post(route('password.confirm'), ['password' => 'incorrect'])->assertTooManyRequests();
    }

    public function test_pdf_export_is_generated_and_staff_dashboard_uses_inline_charts(): void
    {
        $staff = $this->userWithRole('registration');
        $this->grantReports($staff);

        $pdf = $this->withSession(['auth.password_confirmed_at' => time()])
            ->actingAs($staff)
            ->get(route('reports.export', ['report' => 'appointments', 'format' => 'pdf']))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());

        $this->actingAs($staff)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Appointments per week by status')
            ->assertSee('Triage urgency distribution')
            ->assertSee('Virtual vs in-person appointments');
    }

    public function test_doctor_export_is_limited_to_the_doctors_own_appointments(): void
    {
        $doctor = $this->userWithRole('doctor');
        $this->grantReports($doctor);
        $ownProvider = Provider::factory()->create(['user_id' => $doctor->id, 'display_name' => 'Dr Own Scope']);
        $otherProvider = Provider::factory()->create(['display_name' => 'Dr Outside Scope']);
        $own = Appointment::factory()->create([
            'provider_id' => $ownProvider->id,
            'starts_at' => now(),
            'ends_at' => now()->addMinutes(30),
        ]);
        $other = Appointment::factory()->create([
            'provider_id' => $otherProvider->id,
            'starts_at' => now(),
            'ends_at' => now()->addMinutes(30),
        ]);

        $response = $this->withSession(['auth.password_confirmed_at' => time()])
            ->actingAs($doctor)
            ->get(route('reports.export', [
                'report' => 'appointments',
                'format' => 'csv',
                'start' => today()->toDateString(),
                'end' => today()->toDateString(),
            ]))
            ->assertOk();

        $csv = $response->streamedContent();
        $this->assertStringContainsString('Dr Own Scope', $csv);
        $this->assertStringNotContainsString('Dr Outside Scope', $csv);
    }

    public function test_patient_cannot_access_ai_evaluation_or_report_exports(): void
    {
        $patient = $this->userWithRole('patient');

        $this->actingAs($patient)->get(route('reports.ai-evaluation'))->assertForbidden();
        $this->withSession(['auth.password_confirmed_at' => time()])
            ->actingAs($patient)
            ->get(route('reports.export', ['report' => 'appointments', 'format' => 'csv']))
            ->assertForbidden();
    }

    public function test_off_topic_symptom_checker_input_is_blocked_before_external_ai_call(): void
    {
        Http::fake();
        [$user, $patient] = $this->patientWithAiConsent();

        $response = $this->actingAs($user)->post(route('symptom-checker.store'), [
            'patient_id' => $patient->id,
            'age' => 34,
            'symptoms' => 'Write code in Python to sort a list of numbers.',
        ]);

        $response->assertOk()->assertViewIs('symptom-checker.out-of-scope')
            ->assertSee('symptom triage and appointment booking');
        Http::assertNothingSent();
        $this->assertDatabaseCount('symptom_checks', 0);
    }

    public function test_out_of_scope_ai_response_is_discarded_without_saving_a_triage_record(): void
    {
        config([
            'services.ai_triage.provider' => 'gemini',
            'services.ai_triage.gemini.api_key' => 'unit-test-key',
            'services.ai_triage.gemini.model' => 'test-model',
        ]);
        $outOfScopeResult = [
            'scope' => 'out_of_scope',
            'urgency_level' => 'self_care',
            'recommended_specialty' => 'General Medicine',
            'summary' => 'This is unrelated.',
            'red_flags' => [],
            'advice' => ['Please use symptom triage or booking.', 'This is not a medical diagnosis.'],
            'follow_up_questions' => [],
        ];
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [['text' => json_encode($outOfScopeResult, JSON_THROW_ON_ERROR)]],
                    ],
                ]],
            ], 200),
        ]);
        [$user, $patient] = $this->patientWithAiConsent();

        $this->actingAs($user)->post(route('symptom-checker.store'), [
            'patient_id' => $patient->id,
            'age' => 34,
            'symptoms' => 'I have had a cough for three days.',
        ])->assertOk()->assertViewIs('symptom-checker.out-of-scope');

        Http::assertSentCount(1);
        $this->assertDatabaseCount('symptom_checks', 0);
    }

    public function test_clinician_review_is_saved_and_evaluation_metrics_are_correct(): void
    {
        $reviewer = $this->userWithRole('super-admin');
        $patient = Patient::factory()->create();
        $checks = [
            $this->symptomCheck($patient, 'gemini', false, 'Emergency'),
            $this->symptomCheck($patient, 'rules', true, 'Urgent'),
            $this->symptomCheck($patient, 'openai', false, 'Routine'),
        ];

        $this->actingAs($reviewer)->post(route('reports.ai-evaluation.review', $checks[0]), [
            'clinician_final_urgency' => 'Emergency',
            'clinician_agreement' => 'agree',
        ])->assertRedirect();
        $this->actingAs($reviewer)->post(route('reports.ai-evaluation.review', $checks[1]), [
            'clinician_final_urgency' => 'Emergency',
            'clinician_agreement' => 'too_low',
        ])->assertRedirect();

        $metrics = app(AiEvaluationService::class)->metrics();
        $this->assertSame(3, $metrics['triages']);
        $this->assertSame(1, $metrics['rules_count']);
        $this->assertSame(2, $metrics['ai_count']);
        $this->assertSame(1, $metrics['override_count']);
        $this->assertSame(50.0, $metrics['agreement_rate']);
        $this->assertSame(1, $metrics['under_triage_count']);
        $this->assertSame(0, $metrics['over_triage_count']);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'REVIEW_AI_TRIAGE',
            'resource_id' => $checks[0]->id,
            'user_id' => $reviewer->id,
        ]);
    }

    public function test_doctor_cannot_submit_an_evaluation_for_a_patient_outside_their_care(): void
    {
        $doctor = $this->userWithRole('doctor');
        $this->grantReports($doctor);
        $patient = Patient::factory()->create();
        $check = $this->symptomCheck($patient, 'gemini', false, 'Routine');

        $this->actingAs($doctor)->post(route('reports.ai-evaluation.review', $check), [
            'clinician_final_urgency' => 'Routine',
            'clinician_agreement' => 'agree',
        ])->assertForbidden();
        $this->assertNull($check->fresh()->clinician_reviewed_by);
    }

    public function test_evaluation_csv_contains_only_approved_aggregate_fields_and_no_identifiers(): void
    {
        Carbon::setTestNow('2026-11-03 12:00:00');

        try {
            $admin = $this->userWithRole('super-admin');
            $patient = Patient::factory()->create([
                'id' => 86753091,
                'first_name' => 'Sensitive',
                'last_name' => 'Person',
                'mrn' => 'MRN-EVAL-PRIVATE',
                'reference_code' => 'REF-EVAL-PRIVATE-86753091',
                'sex' => 'Female',
            ]);
            $check = $this->symptomCheck($patient, 'gemini', false, 'Emergency');
            $check->forceFill([
                'id' => 97531864,
                'clinician_final_urgency' => 'Urgent',
                'clinician_agreement' => 'too_low',
                'clinician_reviewed_by' => $admin->id,
                'clinician_reviewed_at' => now(),
            ])->save();

            $response = $this->withSession(['auth.password_confirmed_at' => time()])
                ->actingAs($admin)
                ->get(route('reports.export', ['report' => 'ai-evaluation-dataset', 'format' => 'csv']))
                ->assertOk();
            $csv = $response->streamedContent();
            $lines = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));
            $this->assertSame([
                'urgency', 'specialty', 'age range', 'sex', 'clinician agreement', 'rule override flag', 'week',
            ], $lines[0]);
            $this->assertCount(7, $lines[1]);
            $this->assertStringNotContainsString('Sensitive', $csv);
            $this->assertStringNotContainsString('MRN-EVAL-PRIVATE', $csv);
            $this->assertStringNotContainsString((string) $patient->id, $csv);
            $this->assertStringNotContainsString((string) $check->id, $csv);
            $this->assertStringNotContainsString($patient->reference_code, $csv);
        } finally {
            Carbon::setTestNow();
        }
    }

    private function patientWithAiConsent(): array
    {
        $user = $this->userWithRole('patient');
        $patient = Patient::factory()->create(['user_id' => $user->id]);
        app(PatientConsentService::class)->record($patient, 'DATA_PRIVACY', true, $user, 'self');
        app(PatientConsentService::class)->record($patient, 'TERMS_OF_USE', true, $user, 'self');
        app(PatientConsentService::class)->record($patient, 'AI_ASSISTED', true, $user, 'self');

        return [$user, $patient];
    }

    private function symptomCheck(Patient $patient, string $provider, bool $override, string $urgency): SymptomCheck
    {
        return SymptomCheck::query()->create([
            'patient_id' => $patient->id,
            'symptoms' => ['private symptom text'],
            'age' => 38,
            'urgency_level' => $urgency,
            'urgency_score' => 2,
            'recommended_department' => 'General Medicine',
            'recommended_consult_type' => 'OPD',
            'ai_provider' => $provider,
            'ai_result' => ['recommended_specialty' => 'General Medicine'],
            'rule_overrode_ai' => $override,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function userWithRole(string $roleName, array $attributes = []): User
    {
        $user = User::factory()->create([
            'email' => $roleName.'-'.Str::uuid().'@example.test',
            ...$attributes,
        ]);
        $user->roles()->attach(Role::where('name', $roleName)->firstOrFail());

        return $user;
    }

    private function grantReports(User $user): void
    {
        $permission = Permission::firstOrCreate(
            ['name' => 'view-reports'],
            ['label' => 'View Reports']
        );
        $user->roles()->firstOrFail()->permissions()->syncWithoutDetaching([$permission->id]);
    }
}
