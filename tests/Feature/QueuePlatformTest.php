<?php

namespace Tests\Feature;

use App\Events\EmergencyQueueAlert;
use App\Models\Appointment;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\QueueDisplayEntry;
use App\Models\User;
use App\Services\AppointmentService;
use App\Services\PrescriptionService;
use App\Services\TriageService;
use Database\Seeders\HimsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class QueuePlatformTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(HimsSeeder::class);
    }

    public function test_public_queue_board_and_data_endpoint_only_expose_queue_numbers_and_statuses(): void
    {
        QueueDisplayEntry::create([
            'service' => QueueDisplayEntry::SERVICE_OPD,
            'status' => QueueDisplayEntry::STATUS_WAITING,
        ]);
        QueueDisplayEntry::create([
            'service' => QueueDisplayEntry::SERVICE_PHARMACY,
            'status' => QueueDisplayEntry::STATUS_CALLED,
        ]);

        $response = $this->getJson(route('queue.display.data'));
        $response->assertOk()
            ->assertExactJson([
                'opd' => [['queue_number' => 'O-000001', 'status' => 'WAITING']],
                'pharmacy' => [['queue_number' => 'P-000002', 'status' => 'CALLED']],
            ]);
        $this->assertSame(['queue_number', 'status'], array_keys($response->json('opd.0')));

        $this->get(route('queue.display'))
            ->assertOk()
            ->assertSee('OPD')
            ->assertSee('Pharmacy')
            ->assertSee('O-000001')
            ->assertSee('P-000002')
            ->assertDontSee('Maria Santos')
            ->assertDontSee('patient_id')
            ->assertDontSee('MRN-');
    }

    public function test_opd_queue_tracks_appointment_check_in_and_clinical_status_transitions(): void
    {
        $doctor = User::where('email', 'doctor@coor.test')->firstOrFail();
        $patient = Patient::where('user_id', User::where('email', 'patient@coor.test')->value('id'))->firstOrFail();
        $appointment = Appointment::create([
            'appointment_number' => 'APT-QUEUE-'.uniqid(),
            'patient_id' => $patient->id,
            'provider_id' => $doctor->provider->id,
            'starts_at' => now()->addHour(),
            'ends_at' => now()->addHour()->addMinutes(30),
            'status' => Appointment::STATUS_CONFIRMED,
        ]);
        $appointments = app(AppointmentService::class);

        $appointments->checkIn($appointment);
        $entry = QueueDisplayEntry::where('appointment_id', $appointment->id)->firstOrFail();
        $this->assertSame(QueueDisplayEntry::SERVICE_OPD, $entry->service);
        $this->assertSame(QueueDisplayEntry::STATUS_WAITING, $entry->status);

        $appointments->transitionStatus($appointment, Appointment::STATUS_IN_CONSULTATION);
        $this->assertDatabaseHas('queue_display_entries', [
            'appointment_id' => $appointment->id,
            'status' => QueueDisplayEntry::STATUS_IN_SERVICE,
        ]);

        $appointments->transitionStatus($appointment, Appointment::STATUS_COMPLETED);
        $this->assertDatabaseHas('queue_display_entries', [
            'appointment_id' => $appointment->id,
            'status' => QueueDisplayEntry::STATUS_COMPLETED,
        ]);
    }

    public function test_encounter_prescription_enters_pharmacy_queue_without_patient_fields_on_public_board(): void
    {
        $doctor = User::where('email', 'doctor@coor.test')->firstOrFail();
        $patient = Patient::where('user_id', User::where('email', 'patient@coor.test')->value('id'))->firstOrFail();
        $encounter = $patient->encounters()->create([
            'encounter_number' => 'ENC-QUEUE-'.uniqid(),
            'provider_id' => $doctor->provider->id,
            'type' => Encounter::TYPE_OUTPATIENT,
            'started_at' => now(),
            'status' => 'OPEN',
        ]);

        $prescription = app(PrescriptionService::class)->createForEncounter($encounter, [
            'medication_name' => 'Test medication',
            'dosage' => 'One tablet',
            'instructions' => 'Take as directed.',
        ], $doctor);

        $entry = QueueDisplayEntry::where('prescription_id', $prescription->id)->firstOrFail();
        $this->assertSame(QueueDisplayEntry::SERVICE_PHARMACY, $entry->service);
        $this->assertSame(QueueDisplayEntry::STATUS_WAITING, $entry->status);

        $this->getJson(route('queue.display.data'))
            ->assertOk()
            ->assertJsonFragment(['queue_number' => $entry->queue_number, 'status' => 'WAITING'])
            ->assertJsonMissing(['patient_id' => $patient->id])
            ->assertJsonMissing(['medication_name' => 'Test medication']);
    }

    public function test_emergency_alerts_are_private_and_only_severe_triage_is_broadcast_as_critical(): void
    {
        Event::fake([EmergencyQueueAlert::class]);
        $nurse = User::where('email', 'nurse@coor.test')->firstOrFail();
        $patient = Patient::firstOrFail();
        $this->actingAs($nurse);
        $triage = app(TriageService::class);

        $criticalVisit = $triage->registerErVisit([
            'patient_id' => $patient->id,
            'chief_complaint' => 'Urgent presentation',
        ]);
        $triage->triage($criticalVisit, ['priority' => 'Level 1']);

        $routineVisit = $triage->registerErVisit([
            'patient_id' => $patient->id,
            'chief_complaint' => 'Routine presentation',
        ]);
        $triage->triage($routineVisit, ['priority' => 'Level 3']);

        Event::assertDispatchedTimes(EmergencyQueueAlert::class, 3);
        Event::assertDispatched(EmergencyQueueAlert::class, fn (EmergencyQueueAlert $event) => $event->priority === 'Level 1');

        $payload = (new EmergencyQueueAlert('Critical or severe triage assessment recorded.', 'Level 1'))->broadcastWith();
        $this->assertSame(['message', 'priority', 'occurred_at'], array_keys($payload));
        $this->assertStringNotContainsString('patient', strtolower(json_encode($payload)));
        $this->assertSame('private-er-alerts', (new EmergencyQueueAlert('Alert'))->broadcastOn()[0]->name);
    }

    public function test_emergency_alert_listener_marker_is_hidden_from_registration_users(): void
    {
        $nurse = User::where('email', 'nurse@coor.test')->firstOrFail();
        $doctor = User::where('email', 'doctor@coor.test')->firstOrFail();
        $registration = User::where('email', 'registration@coor.test')->firstOrFail();
        $channel = Broadcast::connection()->getChannels()->get('er-alerts');

        $this->assertTrue($channel($nurse));
        $this->assertTrue($channel($doctor));
        $this->assertFalse($channel($registration));

        $this->actingAs($nurse)->get(route('emergency.index'))
            ->assertOk()
            ->assertSee('data-emergency-alerts', false);
        $this->actingAs($nurse)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-emergency-alerts', false);
        $this->actingAs(User::where('email', 'doctor@coor.test')->firstOrFail())->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-emergency-alerts', false);

        $this->actingAs($registration)->get(route('emergency.index'))
            ->assertOk()
            ->assertDontSee('data-emergency-alerts', false);
        $this->actingAs($registration)->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('data-emergency-alerts', false);
    }
}
