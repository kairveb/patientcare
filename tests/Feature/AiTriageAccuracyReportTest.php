<?php

namespace Tests\Feature;

use App\Models\ErVisit;
use App\Models\Patient;
use App\Models\Role;
use App\Models\TriageAssessment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\HimsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiTriageAccuracyReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_view_filtered_accuracy_metrics_and_export_csv(): void
    {
        $this->seed(HimsSeeder::class);
        $admin = $this->userWithRole('super-admin');
        $this->addAssessment('Emergency', 'Emergency', false, today()->toDateString());
        $this->addAssessment('Emergency', 'Urgent', true, today()->toDateString());
        $this->addAssessment('Prompt', 'Prompt', false, today()->subDay()->toDateString());
        $this->addAssessment('Urgent', 'Non-Urgent', true, today()->toDateString());
        $this->addAssessment('Routine', 'Routine', false, today()->toDateString());
        $this->addAssessment(null, null, false, today()->toDateString());
        $this->addAssessment('Routine', 'Routine', false, today()->subDays(4)->toDateString());

        $query = ['start' => today()->subDay()->toDateString(), 'end' => today()->toDateString()];
        $response = $this->actingAs($admin)->get(route('reports.ai-triage-accuracy', $query));

        $response->assertOk()
            ->assertSee('Total triage assessments')
            ->assertSee('6')
            ->assertSee('With recognized AI and final levels')
            ->assertSee('60.00%')
            ->assertSee('40.00%')
            ->assertSee('50.00%')
            ->assertSee('excluded from accuracy metrics');

        $export = $this->actingAs($admin)->get(route('reports.ai-triage-accuracy.export', $query));
        $export->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('Confusion matrix (rows: final level; columns: AI-suggested level)', $export->streamedContent());
        $this->assertStringContainsString('Emergency,2,1,1,50,100', $export->streamedContent());
    }

    public function test_report_rejects_invalid_dates_and_reversed_ranges(): void
    {
        $this->seed(HimsSeeder::class);
        $admin = $this->userWithRole('super-admin');

        $this->actingAs($admin)
            ->get(route('reports.ai-triage-accuracy', ['start' => 'not-a-date']))
            ->assertSessionHasErrors('start');
        $this->actingAs($admin)
            ->get(route('reports.ai-triage-accuracy', ['start' => today()->toDateString(), 'end' => today()->subDay()->toDateString()]))
            ->assertSessionHasErrors('end');
    }

    public function test_doctor_cannot_access_super_admin_accuracy_report_or_export(): void
    {
        $this->seed(HimsSeeder::class);
        $doctor = $this->userWithRole('doctor');

        $this->actingAs($doctor)->get(route('reports.ai-triage-accuracy'))->assertForbidden();
        $this->actingAs($doctor)->get(route('reports.ai-triage-accuracy.export'))->assertForbidden();
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create(['email' => $roleName.'-'.Str::uuid().'@example.test']);
        $user->roles()->attach(Role::where('name', $roleName)->firstOrFail());

        return $user;
    }

    private function addAssessment(?string $aiLevel, ?string $finalLevel, bool $overridden, string $date): void
    {
        $patient = Patient::factory()->create();
        $nurse = User::query()->whereHas('roles', fn ($query) => $query->where('name', 'nurse'))->firstOrFail();
        $visit = ErVisit::create([
            'visit_number' => 'ER-'.Str::upper(Str::random(12)),
            'patient_id' => $patient->id,
            'arrived_at' => CarbonImmutable::parse($date)->startOfDay(),
            'chief_complaint' => 'Test complaint',
        ]);

        TriageAssessment::create([
            'er_visit_id' => $visit->id,
            'triage_nurse_id' => $nurse->id,
            'triaged_at' => CarbonImmutable::parse($date)->setTime(12, 0),
            'priority' => $finalLevel ?? 'Routine',
            'ai_suggested_level' => $aiLevel,
            'final_level' => $finalLevel,
            'is_overridden' => $overridden,
            'status' => 'COMPLETE',
        ]);
    }
}
