<?php

namespace Tests\Feature;

use App\Models\ErQueue;
use App\Models\ErVisit;
use App\Models\Patient;
use App\Models\Provider;
use App\Models\TriageAssessment;
use App\Models\TriageVital;
use App\Models\User;
use Database\Seeders\HimsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DoctorQueueAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(HimsSeeder::class);
    }

    public function test_registration_staff_can_manage_queue_without_clinical_details(): void
    {
        $registration = User::where('email', 'registration@coor.test')->firstOrFail();
        $assessment = $this->createQueueAssessment('REGISTRATION', Provider::query()->firstOrFail()->id);

        $response = $this->actingAs($registration)->get(route('doctors.queue'));

        $response->assertOk()
            ->assertSee('Queue Registration')
            ->assertSee('Mark seen')
            ->assertDontSee('Clinical-only cough details')
            ->assertDontSee('QUEUE-SECRET-BP');

        $this->actingAs($registration)
            ->post(route('doctors.queue.status', $assessment), ['status' => 'SEEN'])
            ->assertRedirect();

        $this->assertSame('SEEN', $assessment->fresh()->status);
    }

    public function test_nurse_can_read_queue_but_cannot_change_status(): void
    {
        $nurse = User::where('email', 'nurse@coor.test')->firstOrFail();
        $assessment = $this->createQueueAssessment('NURSE', Provider::query()->firstOrFail()->id);

        $this->actingAs($nurse)
            ->get(route('doctors.queue'))
            ->assertOk()
            ->assertSee('Queue Nurse')
            ->assertSee('Read-only')
            ->assertDontSee('Clinical-only cough details')
            ->assertDontSee('QUEUE-SECRET-BP')
            ->assertDontSee('Mark seen');

        $this->actingAs($nurse)
            ->post(route('doctors.queue.status', $assessment), ['status' => 'SEEN'])
            ->assertForbidden();

        $this->assertSame('WAITING', $assessment->fresh()->status);
    }

    public function test_patient_cannot_access_the_opd_queue(): void
    {
        $patient = User::where('email', 'patient@coor.test')->firstOrFail();

        $this->actingAs($patient)
            ->get(route('doctors.queue'))
            ->assertForbidden();
    }

    public function test_super_admin_can_change_queue_status(): void
    {
        $admin = User::where('email', 'super-admin@coor.test')->firstOrFail();
        $assessment = $this->createQueueAssessment('ADMIN', Provider::query()->firstOrFail()->id);

        $this->actingAs($admin)
            ->post(route('doctors.queue.status', $assessment), ['status' => 'IN_CONSULT'])
            ->assertRedirect();

        $this->assertSame('IN_CONSULT', $assessment->fresh()->status);
    }

    public function test_doctor_queue_is_limited_to_its_own_provider(): void
    {
        $doctor = User::where('email', 'doctor@coor.test')->firstOrFail();
        $ownProvider = $doctor->provider()->firstOrFail();
        $otherDoctor = User::factory()->create(['email' => 'other-queue-doctor@example.test']);
        $otherProvider = Provider::create([
            'user_id' => $otherDoctor->id,
            'department_id' => $ownProvider->department_id,
            'license_number' => 'LIC-OTHER-QUEUE',
            'display_name' => 'Other Queue Doctor',
            'active' => true,
        ]);
        $ownAssessment = $this->createQueueAssessment('DOCTOR_A', $ownProvider->id);
        $otherAssessment = $this->createQueueAssessment('DOCTOR_B', $otherProvider->id);

        $this->actingAs($doctor)
            ->get(route('doctors.queue'))
            ->assertOk()
            ->assertSee('Queue Doctor A')
            ->assertDontSee('Queue Doctor B')
            ->assertDontSee('SECRET-DOCTOR_B');

        $this->actingAs($doctor)
            ->post(route('doctors.queue.status', $otherAssessment), ['status' => 'SEEN'])
            ->assertNotFound();

        $this->actingAs($doctor)
            ->post(route('doctors.queue.status', $ownAssessment), ['status' => 'SEEN'])
            ->assertRedirect();

        $this->assertSame('WAITING', $otherAssessment->fresh()->status);
        $this->assertSame('SEEN', $ownAssessment->fresh()->status);
    }

    private function createQueueAssessment(string $tag, int $providerId): TriageAssessment
    {
        $tag = strtoupper($tag);
        $patient = Patient::create([
            'mrn' => 'MRN-QUEUE-'.$tag,
            'first_name' => 'Queue',
            'last_name' => match ($tag) {
                'DOCTOR_A' => 'Doctor A',
                'DOCTOR_B' => 'Doctor B',
                default => ucfirst(strtolower($tag)),
            },
            'date_of_birth' => '1990-01-01',
            'sex' => 'Female',
            'phone' => '09170000001',
            'email' => 'queue-'.$tag.'@example.test',
            'verified' => true,
        ]);
        $visit = ErVisit::create([
            'visit_number' => 'ER-QUEUE-'.$tag,
            'patient_id' => $patient->id,
            'chief_complaint' => 'Clinical-only cough details',
            'arrived_at' => now(),
            'status' => 'TRIAGED',
        ]);
        $assessment = TriageAssessment::create([
            'patient_id' => $patient->id,
            'er_visit_id' => $visit->id,
            'triage_nurse_id' => User::where('email', 'nurse@coor.test')->value('id'),
            'chief_complaint' => 'Clinical-only cough details',
            'symptoms' => ['SECRET-'.$tag],
            'triaged_at' => now(),
            'status' => 'WAITING',
            'priority' => 'Urgent',
            'priority_score' => 2,
            'pain_score' => 7,
        ]);

        TriageVital::create([
            'triage_assessment_id' => $assessment->id,
            'patient_id' => $patient->id,
            'blood_pressure' => 'QUEUE-SECRET-BP',
        ]);

        ErQueue::create([
            'er_visit_id' => $visit->id,
            'priority' => 'Urgent',
            'status' => 'WAITING',
            'provider_id' => $providerId,
            'queued_at' => now(),
        ]);

        return $assessment;
    }
}
