<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\DischargeSummary;
use App\Models\Patient;
use App\Models\Provider;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\HimsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PatientDischargeSummaryPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(HimsSeeder::class);
    }

    public function test_patient_portal_page_and_api_return_only_own_approved_summary_without_billing_data(): void
    {
        [$patientUser, $patient] = $this->patient();
        $doctor = User::where('email', 'doctor@coor.test')->firstOrFail();
        $approved = $this->summary($patient, $doctor, DischargeSummary::STATUS_APPROVED, [
            'final_diagnosis' => 'Approved diagnosis unique marker',
            'clinical_course' => 'Approved course unique marker',
            'discharge_instructions' => 'Approved instructions unique marker',
            'follow_up_plan' => 'Follow up in one week.',
        ]);
        $this->summary($patient, $doctor, DischargeSummary::STATUS_DRAFT, [
            'final_diagnosis' => 'Draft diagnosis secret marker',
            'discharge_instructions' => 'Draft instructions secret marker',
        ]);
        [$otherUser, $otherPatient] = $this->patient();
        $otherSummary = $this->summary($otherPatient, $doctor, DischargeSummary::STATUS_APPROVED, [
            'final_diagnosis' => 'Other patient diagnosis marker',
            'discharge_instructions' => 'Other patient instructions marker',
        ]);

        $dashboard = $this->actingAs($patientUser)->get(route('patients.portal'));
        $dashboard->assertOk()
            ->assertSee('Discharge summaries')
            ->assertSee(route('patients.portal.discharge-summaries'), false);

        $this->get(route('patients.portal.discharge-summaries'))
            ->assertOk()
            ->assertSee('Approved diagnosis unique marker')
            ->assertSee('Approved instructions unique marker')
            ->assertDontSee('Draft diagnosis secret marker')
            ->assertDontSee('Draft instructions secret marker')
            ->assertDontSee('Other patient diagnosis marker')
            ->assertDontSee('Other patient instructions marker')
            ->assertDontSee('999999.99');

        $listResponse = $this->getJson('/api/v1/me/discharge-summaries')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $approved->id)
            ->assertJsonPath('data.0.final_diagnosis', 'Approved diagnosis unique marker')
            ->assertJsonPath('data.0.discharge_instructions', 'Approved instructions unique marker')
            ->assertJsonMissingPath('data.0.status')
            ->assertJsonMissingPath('data.0.billing_amount');
        $this->assertCount(1, $listResponse->json('data'));

        $this->assertSame(404, $this->getJson('/api/v1/me/discharge-summaries/'.$otherSummary->id)->getStatusCode());
        $draft = DischargeSummary::where('status', DischargeSummary::STATUS_DRAFT)->firstOrFail();
        $this->getJson('/api/v1/me/discharge-summaries/'.$draft->id)->assertNotFound();

        $logs = \App\Models\AuditLog::where('user_id', $patientUser->id)
            ->where('action', 'VIEW_OWN_DISCHARGE_SUMMARY')
            ->get();
        $this->assertGreaterThanOrEqual(2, $logs->count());
        $this->assertSame([$approved->id], $logs->pluck('resource_id')->unique()->values()->all());
        $this->assertTrue($logs->every(fn ($log): bool => $log->metadata === null));
        $this->assertStringNotContainsString('Approved diagnosis unique marker', json_encode($logs));

        $this->actingAs($otherUser)->get(route('patients.portal.discharge-summaries'))
            ->assertOk()
            ->assertSee('Other patient diagnosis marker')
            ->assertDontSee('Approved diagnosis unique marker');
    }

    public function test_non_patient_cannot_use_patient_discharge_summary_api(): void
    {
        $doctor = User::where('email', 'doctor@coor.test')->firstOrFail();

        $this->actingAs($doctor)
            ->getJson('/api/v1/me/discharge-summaries')
            ->assertForbidden();
    }

    private function patient(): array
    {
        $user = User::factory()->create([
            'email' => 'patient-'.Str::uuid().'@example.test',
            'email_verified_at' => now(),
        ]);
        $user->roles()->attach(Role::where('name', 'patient')->firstOrFail());
        $patient = Patient::factory()->create(['user_id' => $user->id]);
        $this->acceptRequiredPrivacyDocuments($patient, $user);

        return [$user, $patient];
    }

    private function summary(Patient $patient, User $doctor, string $status, array $overrides = []): DischargeSummary
    {
        $provider = $doctor->provider ?? Provider::create([
            'user_id' => $doctor->id,
            'display_name' => 'Dr. Discharge Summary',
            'active' => true,
        ]);
        $admission = Admission::create([
            'admission_number' => 'ADM-PORTAL-'.Str::upper(Str::random(8)),
            'patient_id' => $patient->id,
            'attending_provider_id' => $provider->id,
            'status' => Admission::STATUS_DISCHARGED,
            'admitted_at' => now()->subDay(),
        ]);

        return $admission->dischargeSummary()->create(array_merge([
            'attending_provider_id' => $provider->id,
            'authored_by' => $doctor->id,
            'approved_by' => $status === DischargeSummary::STATUS_APPROVED ? $doctor->id : null,
            'status' => $status,
            'final_diagnosis' => 'Routine diagnosis',
            'clinical_course' => 'Routine clinical course.',
            'discharge_instructions' => 'Follow discharge instructions.',
            'follow_up_plan' => null,
            'approved_at' => $status === DischargeSummary::STATUS_APPROVED ? now() : null,
        ], $overrides));
    }
}
