<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\QueueDisplayEntry;
use App\Models\User;
use App\Models\Vital;
use App\Services\AppointmentService;
use App\Services\EncounterService;
use Database\Seeders\HimsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpdWorkflowIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(HimsSeeder::class);
    }

    public function test_nurse_records_validated_opd_vitals_and_server_calculates_bmi(): void
    {
        $nurse = User::where('email', 'nurse@coor.test')->firstOrFail();
        $appointment = $this->checkedInAppointment();

        $this->actingAs(User::where('email', 'doctor@coor.test')->firstOrFail())
            ->postJson("/api/v1/appointments/{$appointment->id}/vitals", [
                'height_cm' => 170,
                'weight' => 68,
            ])->assertForbidden();

        $this->actingAs($nurse)->postJson("/api/v1/appointments/{$appointment->id}/vitals", [
            'height_cm' => 170,
            'weight' => 68,
            'blood_pressure' => '120/80',
            'heart_rate' => 72,
            'respiratory_rate' => 16,
            'temperature' => 36.6,
            'spo2' => 98,
            'pain_score' => 2,
        ])->assertOk()->assertJsonPath('data.bmi', '23.53');

        $this->assertDatabaseHas('vitals', [
            'appointment_id' => $appointment->id,
            'patient_id' => $appointment->patient_id,
            'height_cm' => 170,
            'weight' => 68,
            'bmi' => 23.53,
        ]);

        $this->actingAs($nurse)->postJson("/api/v1/appointments/{$appointment->id}/vitals", [
            'height_cm' => 0,
            'weight' => 68,
        ])->assertUnprocessable()->assertJsonValidationErrors(['height_cm']);

        $this->actingAs($nurse)->postJson("/api/v1/appointments/{$appointment->id}/vitals", [
            'height_cm' => 55,
            'weight' => 300,
        ])->assertOk()->assertJsonPath('data.bmi', '991.74');

        $this->assertSame(1, Vital::where('appointment_id', $appointment->id)->count());
        $this->assertInstanceOf(Vital::class, $appointment->fresh()->vitals);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'RECORD_OPD_VITALS',
            'resource_type' => 'vital',
            'resource_id' => $appointment->vitals->id,
        ]);
        $metadata = AuditLog::where('action', 'RECORD_OPD_VITALS')->latest('id')->firstOrFail()->metadata;
        $this->assertSame(['appointment_id' => $appointment->id], $metadata);
    }

    public function test_nurse_cannot_record_vitals_outside_assigned_department_through_web_or_api(): void
    {
        $nurse = User::where('email', 'nurse@coor.test')->firstOrFail();
        $appointment = $this->checkedInAppointment();
        $otherDepartmentId = Department::where('code', 'PED')->value('id');
        $appointment->update(['department_id' => $otherDepartmentId]);
        $payload = ['height_cm' => 170, 'weight' => 68];

        $this->actingAs($nurse)
            ->postJson("/api/v1/appointments/{$appointment->id}/vitals", $payload)
            ->assertForbidden();
        $this->actingAs($nurse)
            ->from(route('appointments.index'))
            ->post(route('appointments.vitals.store', $appointment), $payload)
            ->assertForbidden();

        $this->assertDatabaseMissing('vitals', ['appointment_id' => $appointment->id]);
    }

    public function test_encounter_creation_validates_nested_vitals_on_api_and_web(): void
    {
        $doctor = User::where('email', 'doctor@coor.test')->firstOrFail();
        $patient = Patient::where('user_id', User::where('email', 'patient@coor.test')->value('id'))->firstOrFail();
        $payload = [
            'patient_id' => $patient->id,
            'provider_id' => $doctor->provider->id,
            'type' => Encounter::TYPE_OUTPATIENT,
            'chief_complaint' => 'Fever',
            'vitals' => [
                'height_cm' => 170,
                'weight' => 68,
                'heart_rate' => 300,
            ],
        ];

        $this->actingAs($doctor)->postJson('/api/v1/encounters', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['vitals.heart_rate']);

        $this->actingAs($doctor)
            ->from(route('encounters.index'))
            ->post(route('encounters.store'), $payload)
            ->assertRedirect(route('encounters.index'))
            ->assertSessionHasErrors(['vitals.heart_rate']);
    }

    public function test_doctor_opd_lifecycle_syncs_queue_links_vitals_and_audits_clinical_actions(): void
    {
        $doctor = User::where('email', 'doctor@coor.test')->firstOrFail();
        $nurse = User::where('email', 'nurse@coor.test')->firstOrFail();
        $appointment = $this->checkedInAppointment();
        $this->actingAs($nurse)->postJson("/api/v1/appointments/{$appointment->id}/vitals", [
            'height_cm' => 170,
            'weight' => 68,
        ])->assertOk();

        $this->actingAs($doctor)->postJson('/api/v1/encounters', [
            'patient_id' => $appointment->patient_id,
            'provider_id' => $doctor->provider->id,
            'appointment_id' => $appointment->id,
            'type' => Encounter::TYPE_OUTPATIENT,
            'chief_complaint' => 'Headache',
            'history_of_present_illness' => 'Headache for two days.',
            'physical_examination' => 'Alert with no focal deficit.',
            'primary_diagnosis_code' => 'R51.9',
            'primary_diagnosis_description' => 'Headache, unspecified',
        ])->assertCreated()->assertJsonPath('data.appointment.status', Appointment::STATUS_IN_CONSULTATION);

        $encounter = Encounter::where('appointment_id', $appointment->id)->firstOrFail();
        $this->actingAs($doctor)->get(route('encounters.show', $encounter))
            ->assertOk()
            ->assertSee('Request Inpatient Admission')
            ->assertSee('History of Present Illness *')
            ->assertSee('Primary Diagnosis — ICD-10 Code *');
        $this->actingAs($nurse)->get(route('encounters.show', $encounter))
            ->assertOk()
            ->assertDontSee('Request Inpatient Admission')
            ->assertDontSee('id="encounter-completion-form"');

        $this->assertDatabaseHas('vitals', [
            'appointment_id' => $appointment->id,
            'encounter_id' => $encounter->id,
        ]);
        $this->assertDatabaseHas('queue_display_entries', [
            'appointment_id' => $appointment->id,
            'status' => QueueDisplayEntry::STATUS_IN_SERVICE,
        ]);

        $this->actingAs($doctor)->postJson("/api/v1/encounters/{$encounter->id}/prescriptions", [
            'medication_name' => 'Paracetamol',
            'dosage' => '500 mg',
            'instructions' => 'Take as directed.',
        ])->assertCreated();
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ISSUE_PRESCRIPTION',
            'resource_type' => 'prescription',
        ]);

        $this->actingAs($doctor)->postJson("/api/v1/encounters/{$encounter->id}/admission-request", [
            'reason' => 'Requires inpatient observation.',
        ])->assertCreated();
        $this->actingAs($doctor)->postJson("/api/v1/encounters/{$encounter->id}/admission-request", [
            'reason' => 'Duplicate admission request.',
        ])->assertUnprocessable()->assertJsonValidationErrors(['encounter']);
        $admission = Admission::where('encounter_id', $encounter->id)->firstOrFail();
        $this->assertSame(Admission::STATUS_REQUESTED, $admission->status);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'REQUEST_ADMISSION',
            'resource_id' => $admission->id,
        ]);

        $this->actingAs($doctor)->postJson("/api/v1/encounters/{$encounter->id}/complete", [
            'history_of_present_illness' => 'Headache for two days.',
            'physical_examination' => 'Alert with no focal deficit.',
            'primary_diagnosis_code' => 'R51.9',
            'primary_diagnosis_description' => 'Headache, unspecified',
            'assessment' => 'Stable outpatient presentation.',
            'plan' => 'Continue treatment and return if symptoms worsen.',
        ])->assertOk()->assertJsonPath('data.status', Encounter::STATUS_COMPLETED);

        $this->assertSame(Appointment::STATUS_COMPLETED, $appointment->fresh()->status);
        $this->assertDatabaseHas('queue_display_entries', [
            'appointment_id' => $appointment->id,
            'status' => QueueDisplayEntry::STATUS_COMPLETED,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'COMPLETE_ENCOUNTER',
            'resource_id' => $encounter->id,
        ]);

        $this->actingAs($doctor)->postJson("/api/v1/encounters/{$encounter->id}/complete", [
            'history_of_present_illness' => 'Headache for two days.',
            'physical_examination' => 'Alert with no focal deficit.',
            'primary_diagnosis_code' => 'R51.9',
            'primary_diagnosis_description' => 'Headache, unspecified',
            'assessment' => 'Stable outpatient presentation.',
            'plan' => 'Continue treatment and return if symptoms worsen.',
        ])->assertUnprocessable()->assertJsonValidationErrors(['status']);
    }

    public function test_opd_vitals_controls_are_only_rendered_for_nursing_staff(): void
    {
        $nurse = User::where('email', 'nurse@coor.test')->firstOrFail();
        $doctor = User::where('email', 'doctor@coor.test')->firstOrFail();
        $appointment = $this->checkedInAppointment();

        $this->actingAs($nurse)->get(route('appointments.index'))
            ->assertOk()
            ->assertSee('Record vitals')
            ->assertSee('opdVitalsModal-'.$appointment->id)
            ->assertSee('min="55" max="250"', false)
            ->assertSee('min="1" max="300"', false);

        $this->actingAs($doctor)->get(route('appointments.index'))
            ->assertOk()
            ->assertDontSee('Record vitals')
            ->assertDontSee('opdVitalsModal-'.$appointment->id);
    }

    public function test_nurse_appointment_list_is_limited_to_assigned_department(): void
    {
        $nurse = User::where('email', 'nurse@coor.test')->firstOrFail();
        $assignedAppointment = $this->checkedInAppointment();
        $otherAppointment = $this->checkedInAppointment(1);
        $otherAppointment->update([
            'department_id' => Department::where('code', 'PED')->value('id'),
        ]);

        $response = $this->actingAs($nurse)->get(route('appointments.index'));
        $response->assertOk()
            ->assertSee($assignedAppointment->appointment_number)
            ->assertDontSee('opdVitalsModal-'.$otherAppointment->id);
    }

    public function test_completing_legacy_encounter_repairs_a_stale_waiting_queue_entry(): void
    {
        $doctor = User::where('email', 'doctor@coor.test')->firstOrFail();
        $appointment = $this->checkedInAppointment();
        $appointment->update(['status' => Appointment::STATUS_COMPLETED]);
        $encounter = Encounter::create([
            'encounter_number' => 'ENC-LEGACY-'.strtoupper(bin2hex(random_bytes(5))),
            'patient_id' => $appointment->patient_id,
            'provider_id' => $appointment->provider_id,
            'appointment_id' => $appointment->id,
            'type' => Encounter::TYPE_OUTPATIENT,
            'started_at' => now(),
            'status' => Encounter::STATUS_OPEN,
        ]);
        $this->actingAs($doctor);
        app(EncounterService::class)->complete($encounter, [
            'history_of_present_illness' => 'Symptoms for two days.',
            'physical_examination' => 'No acute findings.',
            'primary_diagnosis_code' => 'R51.9',
            'primary_diagnosis_description' => 'Headache, unspecified',
            'assessment' => 'Stable.',
            'plan' => 'Follow up as needed.',
        ], $doctor);

        $this->assertDatabaseHas('queue_display_entries', [
            'appointment_id' => $appointment->id,
            'status' => QueueDisplayEntry::STATUS_COMPLETED,
        ]);
    }

    private function checkedInAppointment(int $minutesOffset = 0): Appointment
    {
        $doctor = User::where('email', 'doctor@coor.test')->firstOrFail();
        $patient = Patient::where('user_id', User::where('email', 'patient@coor.test')->value('id'))->firstOrFail();
        $appointment = Appointment::create([
            'appointment_number' => 'APT-OPD-'.strtoupper(bin2hex(random_bytes(5))),
            'patient_id' => $patient->id,
            'provider_id' => $doctor->provider->id,
            'starts_at' => now()->addMinutes($minutesOffset),
            'ends_at' => now()->addMinutes($minutesOffset + 30),
            'status' => Appointment::STATUS_CONFIRMED,
        ]);

        app(AppointmentService::class)->checkIn($appointment);

        return $appointment->fresh();
    }
}
