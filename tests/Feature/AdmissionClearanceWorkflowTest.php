<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\BillingClearanceRequest;
use App\Models\Patient;
use App\Models\User;
use App\Services\DischargeSummaryService;
use Database\Seeders\HimsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AdmissionClearanceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(HimsSeeder::class);
    }

    public function test_registration_staff_can_update_guarantor_details_and_audit_the_change(): void
    {
        $admission = $this->admission();
        $registration = $this->user('registration@coor.test');

        $this->actingAs($registration)
            ->from(route('admissions.show', $admission))
            ->patch(route('admissions.guarantee-details.update', $admission), [
                'guarantor_name' => 'Maria Santos',
                'guarantor_relationship' => 'Spouse',
                'guarantor_contact' => '09171234567',
                'loa_reference' => 'LOA-PRIVATE-001',
            ])
            ->assertRedirect(route('admissions.show', $admission))
            ->assertSessionHas('success');

        $storedAdmission = $admission->fresh();
        $this->assertSame('Maria Santos', $storedAdmission->guarantor_name);
        $this->assertSame('Spouse', $storedAdmission->guarantor_relationship);
        $this->assertSame('09171234567', $storedAdmission->guarantor_contact);
        $this->assertSame('LOA-PRIVATE-001', $storedAdmission->loa_reference);
        $this->assertNotSame('LOA-PRIVATE-001', $storedAdmission->getRawOriginal('loa_reference'));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'UPDATE_ADMISSION_GUARANTEE_DETAILS',
            'resource_type' => 'admission',
            'resource_id' => $admission->id,
            'user_id' => $registration->id,
        ]);
    }

    public function test_guarantor_details_api_validates_input_and_denies_doctors(): void
    {
        $admission = $this->admission();
        $registration = $this->user('registration@coor.test');
        $registrationToken = $registration->createToken('admission-guarantee-test')->plainTextToken;

        $this->withToken($registrationToken)
            ->patchJson("/api/v1/admissions/{$admission->id}/guarantee-details", [
                'guarantor_name' => str_repeat('x', 151),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('guarantor_name');

        $this->withToken($registrationToken)
            ->patchJson("/api/v1/admissions/{$admission->id}/guarantee-details", [
                'loa_reference' => 'LOA-API-001',
            ])
            ->assertOk()
            ->assertJsonPath('data.loa_reference', 'LOA-API-001');

        Auth::forgetGuards();
        $doctorToken = $this->user('doctor@coor.test')->createToken('admission-guarantee-denied')->plainTextToken;
        $this->withToken($doctorToken)
            ->patchJson("/api/v1/admissions/{$admission->id}/guarantee-details", [
                'loa_reference' => 'LOA-UNAUTHORIZED',
            ])
            ->assertForbidden();
    }

    public function test_api_admission_capture_persists_guarantee_details_but_limits_reading_to_registration_staff(): void
    {
        $doctor = $this->user('doctor@coor.test');
        $doctorToken = $doctor->createToken('admission-create-guarantee')->plainTextToken;
        $response = $this->withToken($doctorToken)
            ->postJson('/api/v1/admissions', [
                'patient_id' => Patient::query()->firstOrFail()->id,
                'attending_provider_id' => $doctor->provider->id,
                'reason' => 'Inpatient evaluation',
                'guarantor_name' => 'Maria Santos',
                'guarantor_relationship' => 'Spouse',
                'guarantor_contact' => '09171234567',
                'loa_reference' => 'LOA-API-PRIVATE',
            ])
            ->assertCreated()
            ->assertJsonMissingPath('data.guarantor_name');
        $admissionId = $response->json('data.id');

        $this->assertSame('LOA-API-PRIVATE', Admission::findOrFail($admissionId)->loa_reference);

        Auth::forgetGuards();
        $nurseToken = $this->user('nurse@coor.test')->createToken('admission-read-limited')->plainTextToken;
        $this->withToken($nurseToken)
            ->getJson("/api/v1/admissions/{$admissionId}")
            ->assertOk()
            ->assertJsonMissingPath('data.guarantor_name');

        Auth::forgetGuards();
        $registrationToken = $this->user('registration@coor.test')->createToken('admission-read-registration')->plainTextToken;
        $this->withToken($registrationToken)
            ->getJson("/api/v1/admissions/{$admissionId}")
            ->assertOk()
            ->assertJsonPath('data.guarantor_name', 'Maria Santos')
            ->assertJsonPath('data.loa_reference', 'LOA-API-PRIVATE');
    }

    public function test_nurse_can_request_clearance_after_attending_doctor_approval_and_duplicate_requests_are_idempotent(): void
    {
        $admission = $this->admission(Admission::STATUS_ADMITTED);
        $doctor = $this->user('doctor@coor.test');
        $summary = app(DischargeSummaryService::class)->saveAdmission($admission, $doctor, [
            'final_diagnosis' => 'Resolved condition',
            'clinical_course' => 'Patient improved.',
            'discharge_instructions' => 'Follow up as advised.',
        ]);
        app(DischargeSummaryService::class)->approve($summary, $doctor);

        $nurse = $this->user('nurse@coor.test');
        $token = $nurse->createToken('billing-clearance-test')->plainTextToken;
        $first = $this->withToken($token)
            ->postJson("/api/v1/admissions/{$admission->id}/billing-clearance-request")
            ->assertCreated()
            ->assertJsonPath('data.status', 'REQUESTED');
        $requestId = $first->json('data.id');

        $this->withToken($token)
            ->postJson("/api/v1/admissions/{$admission->id}/billing-clearance-request")
            ->assertOk()
            ->assertJsonPath('data.id', $requestId);

        $this->assertSame(1, BillingClearanceRequest::where('admission_id', $admission->id)->count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'REQUEST_BILLING_CLEARANCE',
            'resource_type' => 'billing_clearance_request',
            'resource_id' => $requestId,
            'user_id' => $nurse->id,
        ]);
    }

    public function test_clearance_request_requires_admission_and_approved_discharge_summary_and_denies_doctor(): void
    {
        $admission = $this->admission(Admission::STATUS_ADMITTED);
        $nurseToken = $this->user('nurse@coor.test')->createToken('billing-clearance-validation')->plainTextToken;

        $this->withToken($nurseToken)
            ->postJson("/api/v1/admissions/{$admission->id}/billing-clearance-request")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('discharge_summary');

        $requestedAdmission = $this->admission(Admission::STATUS_REQUESTED);
        $this->withToken($nurseToken)
            ->postJson("/api/v1/admissions/{$requestedAdmission->id}/billing-clearance-request")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('admission');

        Auth::forgetGuards();
        $doctorToken = $this->user('doctor@coor.test')->createToken('billing-clearance-denied')->plainTextToken;
        $this->withToken($doctorToken)
            ->postJson("/api/v1/admissions/{$admission->id}/billing-clearance-request")
            ->assertForbidden();
    }

    public function test_clearance_request_is_available_in_web_for_nurse_after_discharge_order(): void
    {
        $admission = $this->admission(Admission::STATUS_ADMITTED);
        $doctor = $this->user('doctor@coor.test');
        $summary = app(DischargeSummaryService::class)->saveAdmission($admission, $doctor, [
            'final_diagnosis' => 'Resolved condition',
            'clinical_course' => 'Patient improved.',
            'discharge_instructions' => 'Follow up as advised.',
        ]);
        app(DischargeSummaryService::class)->approve($summary, $doctor);

        $this->actingAs($this->user('nurse@coor.test'))
            ->post(route('admissions.billing-clearance.request', $admission))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('billing_clearance_requests', [
            'admission_id' => $admission->id,
            'discharge_summary_id' => $summary->id,
            'status' => 'REQUESTED',
        ]);
    }

    private function admission(string $status = Admission::STATUS_REQUESTED): Admission
    {
        $doctor = $this->user('doctor@coor.test');

        return Admission::create([
            'admission_number' => 'ADM-CLEARANCE-'.uniqid(),
            'patient_id' => Patient::query()->firstOrFail()->id,
            'attending_provider_id' => $doctor->provider->id,
            'status' => $status,
            'admitted_at' => $status === Admission::STATUS_ADMITTED ? now() : null,
            'created_by' => $doctor->id,
        ]);
    }

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }
}
