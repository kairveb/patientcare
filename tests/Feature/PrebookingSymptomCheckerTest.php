<?php

namespace Tests\Feature;

use App\Models\AppointmentType;
use App\Models\Patient;
use App\Models\Role;
use App\Models\SymptomCheck;
use App\Models\User;
use Database\Seeders\HimsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use App\Services\PatientConsentService;
use Tests\TestCase;

class PrebookingSymptomCheckerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(HimsSeeder::class);
    }

    public function test_registration_staff_can_save_ai_result_and_booking_prefills_opd_type(): void
    {
        $registration = $this->userWithRole('registration');
        $patient = Patient::factory()->create();
        app(PatientConsentService::class)->record($patient, 'AI_ASSISTED', true, $registration, 'staff');

        $response = $this->actingAs($registration)->post(route('symptom-checker.store'), [
            'patient_id' => $patient->id,
            'symptoms' => 'cough',
            'age' => 41,
            'vitals' => ['gcs' => 15, 'spo2' => 98],
        ]);

        $check = SymptomCheck::firstOrFail();
        $this->assertSame($patient->id, $check->patient_id);
        $this->assertSame($registration->id, $check->created_by);
        $this->assertSame(41, $check->age);
        $this->assertSame(15, $check->vitals['gcs']);
        $this->assertSame(3, $check->urgency_score);
        $this->assertSame('OPD', $check->recommended_consult_type);
        $this->assertFalse($check->requires_er);
        $response->assertRedirect(route('symptom-checker.show', $check));

        $this->actingAs($registration)
            ->get(route('symptom-checker.show', $check))
            ->assertOk()
            ->assertSee('decision support only, not a diagnosis')
            ->assertSee('for clinical validation')
            ->assertSee('Continue to booking');

        $outpatientType = AppointmentType::where('name', 'Outpatient')->firstOrFail();
        $this->actingAs($registration)
            ->get(route('appointments.create', ['symptom_check_id' => $check->id]))
            ->assertOk()
            ->assertSee('value="'.$outpatientType->id.'" selected', false);
    }

    public function test_patient_checker_is_scoped_to_own_record_and_red_flags_divert_to_er(): void
    {
        $patientUser = $this->userWithRole('patient');
        $patient = Patient::factory()->create(['user_id' => $patientUser->id]);
        $otherPatient = Patient::factory()->create();
        app(PatientConsentService::class)->record($patient, 'AI_ASSISTED', true, $patientUser, 'self');

        $response = $this->actingAs($patientUser)->post(route('symptom-checker.store'), [
            'patient_id' => $otherPatient->id,
            'symptoms' => 'chest pain',
            'age' => 35,
        ]);

        $check = SymptomCheck::firstOrFail();
        $this->assertSame($patient->id, $check->patient_id);
        $this->assertSame('ER', $check->recommended_consult_type);
        $this->assertContains('chest pain', $check->red_flags);
        $this->assertTrue($check->requires_er);
        $response->assertRedirect(route('symptom-checker.emergency', $check));

        $this->actingAs($patientUser)
            ->get(route('symptom-checker.emergency', $check))
            ->assertOk()
            ->assertSee('Do not continue with routine appointment booking')
            ->assertSee('call 911')
            ->assertDontSee('Continue to booking');

        $otherCheck = SymptomCheck::create([
            'patient_id' => $otherPatient->id,
            'symptoms' => ['follow-up'],
            'age' => 52,
            'urgency_level' => 'Routine',
            'urgency_score' => 5,
            'ai_reasons' => [],
            'red_flags' => [],
            'recommended_department' => 'Virtual Consultation',
            'recommended_consult_type' => 'Virtual',
            'requires_er' => false,
        ]);
        $this->actingAs($patientUser)->get(route('symptom-checker.show', $otherCheck))->assertForbidden();
    }

    public function test_checker_rejects_invalid_age_or_vitals_and_nurse_is_forbidden(): void
    {
        $registration = $this->userWithRole('registration');
        $patient = Patient::factory()->create();

        $this->actingAs($registration)
            ->from(route('symptom-checker.create'))
            ->post(route('symptom-checker.store'), [
                'patient_id' => $patient->id,
                'symptoms' => 'cough',
                'age' => 121,
                'vitals' => ['gcs' => 2],
            ])
            ->assertSessionHasErrors(['age', 'vitals.gcs']);
        $this->assertSame(0, SymptomCheck::count());

        $nurse = $this->userWithRole('nurse');
        $this->actingAs($nurse)->get(route('symptom-checker.create'))->assertForbidden();
        $this->actingAs($nurse)->post(route('symptom-checker.store'), [])->assertForbidden();
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create(['email' => $roleName.'-'.Str::uuid().'@example.test']);
        $user->roles()->attach(Role::where('name', $roleName)->firstOrFail());

        return $user;
    }
}
