<?php

namespace Tests\Feature;

use App\Events\EmergencyQueueAlert;
use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\AuditLog;
use App\Models\ErVisit;
use App\Models\Patient;
use App\Models\Provider;
use App\Models\Role;
use App\Models\TelehealthSession;
use App\Models\User;
use Database\Seeders\HimsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class TelehealthEmergencyEscalationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(HimsSeeder::class);
    }

    public function test_assigned_doctor_escalates_live_session_to_er_once_and_triggers_alert_and_audit(): void
    {
        Event::fake([EmergencyQueueAlert::class]);
        [$doctor, $session, $patient] = $this->telehealthSession();

        $this->actingAs($doctor)
            ->get(route('telehealth.show', $session))
            ->assertOk()
            ->assertSee('Escalate to ER');

        $response = $this->actingAs($doctor)->post(route('telehealth.escalate-to-er', $session));
        $visit = ErVisit::where('telehealth_session_id', $session->id)->firstOrFail();

        $response->assertRedirect(route('emergency.show', $visit));
        $this->assertSame($patient->id, $visit->patient_id);
        $this->assertSame('TELEHEALTH', $visit->arrival_method);
        $this->assertSame(TelehealthSession::STATUS_ESCALATED, $session->fresh()->status);
        $this->assertNotNull($session->fresh()->escalated_at);
        $this->assertSame(1, ErVisit::where('telehealth_session_id', $session->id)->count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ESCALATE_TELEHEALTH_TO_ER',
            'resource_type' => 'telehealth_session',
            'resource_id' => $session->id,
            'user_id' => $doctor->id,
        ]);
        $this->assertSame($visit->id, AuditLog::where('action', 'ESCALATE_TELEHEALTH_TO_ER')->firstOrFail()->metadata['er_visit_id']);
        Event::assertDispatched(EmergencyQueueAlert::class, fn (EmergencyQueueAlert $event): bool =>
            str_contains($event->message, 'telehealth patient')
        );

        $patientUser = $this->userWithRole('patient');
        $patient->update(['user_id' => $patientUser->id]);
        $this->actingAs($patientUser)
            ->get(route('telehealth.join', ['session' => $session, 'token' => $session->secureJoinToken()]))
            ->assertForbidden();

        $this->actingAs($doctor)->post(route('telehealth.escalate-to-er', $session))->assertStatus(409);
        $this->assertSame(1, ErVisit::where('telehealth_session_id', $session->id)->count());
        $this->assertSame(1, AuditLog::where('action', 'ESCALATE_TELEHEALTH_TO_ER')->count());
    }

    public function test_only_the_assigned_doctor_can_escalate_and_only_while_session_is_live(): void
    {
        [$doctor, $session] = $this->telehealthSession();
        $otherDoctor = $this->userWithRole('doctor');
        Provider::create(['user_id' => $otherDoctor->id, 'display_name' => 'Dr. Other', 'active' => true]);

        $this->actingAs($otherDoctor)
            ->post(route('telehealth.escalate-to-er', $session))
            ->assertForbidden();
        $this->assertSame(0, ErVisit::where('telehealth_session_id', $session->id)->count());

        $session->update(['status' => TelehealthSession::STATUS_SCHEDULED]);
        $this->actingAs($doctor)
            ->post(route('telehealth.escalate-to-er', $session))
            ->assertStatus(409);
        $this->assertSame(0, ErVisit::where('telehealth_session_id', $session->id)->count());

        $nurse = $this->userWithRole('nurse');
        $this->actingAs($nurse)
            ->post(route('telehealth.escalate-to-er', $session))
            ->assertForbidden();
    }

    private function telehealthSession(): array
    {
        $doctor = $this->userWithRole('doctor');
        $patient = Patient::factory()->create();
        $provider = Provider::create([
            'user_id' => $doctor->id,
            'display_name' => 'Dr. Assigned',
            'active' => true,
        ]);
        $appointmentType = AppointmentType::where('telehealth', true)->firstOrFail();
        $appointment = Appointment::create([
            'appointment_number' => 'APT-ESCALATION-'.Str::upper(Str::random(8)),
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
            'appointment_type_id' => $appointmentType->id,
            'starts_at' => now(),
            'ends_at' => now()->addMinutes(30),
            'status' => Appointment::STATUS_IN_CONSULTATION,
        ]);
        $session = TelehealthSession::create([
            'appointment_id' => $appointment->id,
            'start_time' => now(),
            'duration' => 30,
            'status' => TelehealthSession::STATUS_ONGOING,
        ]);

        return [$doctor, $session, $patient];
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create(['email' => $roleName.'-'.Str::uuid().'@example.test']);
        $user->roles()->attach(Role::where('name', $roleName)->firstOrFail());

        return $user;
    }
}
