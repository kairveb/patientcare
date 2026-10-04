<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\ChargeRate;
use App\Models\Encounter;
use App\Models\EncounterBill;
use App\Models\ErQueue;
use App\Models\ErVisit;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\User;
use App\Services\AdmissionService;
use App\Services\BillingAggregationService;
use App\Services\DischargeSummaryService;
use App\Services\TriageService;
use Database\Seeders\HimsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BillingAndDischargeWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(HimsSeeder::class);
    }

    public function test_billing_requires_active_rates_and_snapshots_only_dispensed_prescriptions(): void
    {
        $doctor = User::where('email', 'doctor@coor.test')->firstOrFail();
        $patient = Patient::query()->firstOrFail();
        $encounter = $this->createEncounter($patient, $doctor);
        $prescription = Prescription::create([
            'telehealth_session_id' => null,
            'encounter_id' => $encounter->id,
            'patient_id' => $patient->id,
            'medication_name' => 'Amoxicillin',
            'dosage' => '500 mg',
            'instructions' => 'Take as directed.',
            'quantity' => 3,
            'prescribed_by' => $doctor->id,
            'prescribed_at' => now(),
        ]);
        $billing = app(BillingAggregationService::class);

        try {
            $billing->previewForEncounter($encounter);
            $this->fail('A missing active encounter rate must stop billing.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('encounter:OUTPATIENT', $exception->errors()['billing'][0]);
        }

        $this->createRate('encounter:OUTPATIENT', 'ENCOUNTER', 250);
        $this->createRate('prescription:amoxicillin', 'PRESCRIPTION', 12.50);
        $preview = $billing->previewForEncounter($encounter);
        $this->assertSame('250.00', $preview['total']);
        $this->assertCount(1, $preview['items']);

        $billing->dispense($prescription, $doctor);
        $preview = $billing->previewForEncounter($encounter);
        $this->assertSame('287.50', $preview['total']);
        $this->assertCount(2, $preview['items']);

        $bill = $billing->generateForEncounter($encounter, $doctor);
        $this->assertSame('287.50', $bill->total);
        $this->assertCount(2, $bill->items);
        $this->assertDatabaseHas('bill_items', [
            'encounter_bill_id' => $bill->id,
            'prescription_id' => $prescription->id,
            'quantity' => 3,
            'line_total' => '37.50',
        ]);
    }

    public function test_cashier_api_is_role_gated_and_payment_must_match_exact_statement_total(): void
    {
        $doctor = User::where('email', 'doctor@coor.test')->firstOrFail();
        $nurse = User::where('email', 'nurse@coor.test')->firstOrFail();
        $admin = User::where('email', 'super-admin@coor.test')->firstOrFail();
        $encounter = $this->createEncounter(Patient::query()->firstOrFail(), $doctor);
        $this->createRate('encounter:OUTPATIENT', 'ENCOUNTER', 250);

        $this->actingAs($nurse)
            ->postJson("/api/v1/encounters/{$encounter->id}/billing-statements")
            ->assertForbidden();

        $this->actingAs($doctor)
            ->getJson("/api/v1/encounters/{$encounter->id}/billing-summary")
            ->assertForbidden();
        $this->actingAs($doctor)
            ->postJson("/api/v1/encounters/{$encounter->id}/billing-statements")
            ->assertForbidden();

        $bill = $this->actingAs($admin)
            ->postJson("/api/v1/encounters/{$encounter->id}/billing-statements")
            ->assertCreated()
            ->json('data');
        $this->actingAs($admin)
            ->getJson("/api/v1/encounters/{$encounter->id}/billing-summary")
            ->assertOk()
            ->assertJsonPath('data.total', '250.00');
        $this->actingAs($admin)
            ->postJson("/api/v1/billing-statements/{$bill['id']}/payment", [
                'payment_reference' => 'CASH-TEST-001',
                'amount_paid' => '249.99',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount_paid');

        $this->actingAs($admin)
            ->postJson("/api/v1/billing-statements/{$bill['id']}/payment", [
                'payment_reference' => 'CASH-TEST-001',
                'amount_paid' => '250.00',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', EncounterBill::STATUS_PAID);
    }

    public function test_doctor_can_read_only_payment_status_for_discharge_readiness(): void
    {
        $doctor = User::where('email', 'doctor@coor.test')->firstOrFail();
        $patient = Patient::query()->firstOrFail();
        $admission = Admission::create([
            'admission_number' => 'ADM-DOCTOR-STATUS',
            'patient_id' => $patient->id,
            'attending_provider_id' => $doctor->provider->id,
            'status' => Admission::STATUS_ADMITTED,
            'admitted_at' => now()->subDay(),
        ]);
        $this->createRate('bed:default', 'BED', 100);
        $admission->dischargeSummary()->create([
            'attending_provider_id' => $doctor->provider->id,
            'authored_by' => $doctor->id,
            'approved_by' => $doctor->id,
            'status' => \App\Models\DischargeSummary::STATUS_APPROVED,
            'final_diagnosis' => 'Stable for discharge',
            'clinical_course' => 'Improved.',
            'discharge_instructions' => 'Follow up as scheduled.',
            'approved_at' => now(),
        ]);

        $billing = app(BillingAggregationService::class);
        $bill = $billing->generateForAdmission($admission, $doctor);
        $billing->markPaid($bill, 'DOCTOR-STATUS-TEST', (string) $bill->total);

        $this->actingAs($doctor)
            ->get("/admissions/{$admission->id}")
            ->assertOk()
            ->assertViewHas('canDischarge', true)
            ->assertViewHas('paymentStatus', EncounterBill::STATUS_PAID)
            ->assertViewHas('billingSummary', null)
            ->assertDontSee('Itemized Billing')
            ->assertDontSee('PHP '.number_format((float) $bill->total, 2));

        $this->actingAs($doctor)
            ->getJson("/api/v1/admissions/{$admission->id}/payment-status")
            ->assertOk()
            ->assertExactJson(['success' => true, 'data' => ['status' => EncounterBill::STATUS_PAID]]);

        $encounter = $this->createEncounter($patient, $doctor);
        $this->actingAs($doctor)
            ->getJson("/api/v1/admissions/{$admission->id}/billing-summary")
            ->assertForbidden();
        $this->actingAs($doctor)
            ->getJson("/api/v1/encounters/{$encounter->id}/billing-summary")
            ->assertForbidden();
        $this->actingAs($doctor)->get('/reports')->assertForbidden();
    }

    public function test_inpatient_discharge_requires_assigned_physician_approval_and_current_paid_bill(): void
    {
        $doctor = User::where('email', 'doctor@coor.test')->firstOrFail();
        $nurse = User::where('email', 'nurse@coor.test')->firstOrFail();
        $patient = Patient::query()->firstOrFail();
        $admission = Admission::create([
            'admission_number' => 'ADM-BILL-'.uniqid(),
            'patient_id' => $patient->id,
            'attending_provider_id' => $doctor->provider->id,
            'status' => Admission::STATUS_ADMITTED,
            'reason' => 'Observation',
            'admitted_at' => now()->subDay(),
            'created_by' => $nurse->id,
        ]);
        $encounter = $this->createEncounter($patient, $doctor, Encounter::TYPE_OUTPATIENT, now()->subDay());
        $this->createRate('encounter:OUTPATIENT', 'ENCOUNTER', 250);
        $this->createRate('bed:default', 'BED_DAILY', 800);

        $response = $this->actingAs($nurse)->get(route('admissions.show', $admission));
        $response->assertOk()
            ->assertDontSee('Confirm Discharge')
            ->assertSee('Discharge is unavailable until the attending physician authors and approves the summary.');

        try {
            app(AdmissionService::class)->discharge($admission, []);
            $this->fail('Discharge must require a physician-approved summary.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('discharge_summary', $exception->errors());
        }

        $summary = app(DischargeSummaryService::class)->saveAdmission($admission, $doctor, [
            'final_diagnosis' => 'Community-acquired pneumonia',
            'clinical_course' => 'Improved with treatment.',
            'discharge_instructions' => 'Continue medication and return if symptoms worsen.',
        ]);
        app(DischargeSummaryService::class)->approve($summary, $doctor);
        $billing = app(BillingAggregationService::class);
        $bill = $billing->generateForAdmission($admission, $nurse);
        $billing->markPaid($bill, 'CASH-ADM-001', $bill->total);

        $this->actingAs($nurse)->get(route('admissions.show', $admission))
            ->assertOk()
            ->assertSee('Confirm Discharge');
        $this->actingAs($nurse)->post(route('admissions.discharge', $admission), [
            'reason' => 'Discharged home',
        ])->assertRedirect();
        $this->assertDatabaseHas('admissions', [
            'id' => $admission->id,
            'status' => Admission::STATUS_DISCHARGED,
        ]);
    }

    public function test_rate_catalog_controls_are_visible_only_to_super_admin(): void
    {
        $doctor = User::where('email', 'doctor@coor.test')->firstOrFail();
        $admin = User::where('email', 'super-admin@coor.test')->firstOrFail();

        $this->actingAs($doctor)->get(route('billing.rates.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('billing.rates.index'))
            ->assertOk()
            ->assertSee('Billing Charge Rates')
            ->assertSee('Create rate');
    }

    public function test_er_completion_control_is_hidden_until_physician_summary_and_paid_billing_are_ready(): void
    {
        $doctor = User::where('email', 'doctor@coor.test')->firstOrFail();
        $nurse = User::where('email', 'nurse@coor.test')->firstOrFail();
        $visit = ErVisit::create([
            'visit_number' => 'ER-BILL-'.uniqid(),
            'patient_id' => Patient::query()->firstOrFail()->id,
            'arrived_at' => now(),
            'chief_complaint' => 'Severe headache',
            'status' => ErVisit::STATUS_IN_TREATMENT,
            'created_by' => $nurse->id,
        ]);
        ErQueue::create([
            'er_visit_id' => $visit->id,
            'priority' => 'Level 2',
            'status' => ErQueue::STATUS_IN_TREATMENT,
            'provider_id' => $doctor->provider->id,
            'queued_at' => now(),
        ]);

        $this->actingAs($nurse)->get(route('emergency.show', $visit))
            ->assertOk()
            ->assertDontSee('<option value="DONE"', false)
            ->assertSee('ER completion is blocked until');

        try {
            app(TriageService::class)->updateQueueStatus($visit->queue, ErQueue::STATUS_DONE);
            $this->fail('ER completion must be blocked until its discharge requirements are met.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('discharge_summary', $exception->errors());
        }
    }

    private function createEncounter(Patient $patient, User $doctor, string $type = Encounter::TYPE_OUTPATIENT, mixed $startedAt = null): Encounter
    {
        return Encounter::create([
            'encounter_number' => 'ENC-BILL-'.strtoupper(bin2hex(random_bytes(5))),
            'patient_id' => $patient->id,
            'provider_id' => $doctor->provider->id,
            'type' => $type,
            'started_at' => $startedAt ?? now(),
            'status' => 'OPEN',
        ]);
    }

    private function createRate(string $code, string $category, float $amount): ChargeRate
    {
        return ChargeRate::create([
            'charge_code' => $code,
            'category' => $category,
            'description' => $code,
            'unit_amount' => $amount,
            'currency' => 'PHP',
            'active' => true,
        ]);
    }
}
