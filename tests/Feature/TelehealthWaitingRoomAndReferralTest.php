<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Patient;
use App\Models\Provider;
use App\Models\Role;
use App\Models\TelehealthReferral;
use App\Models\TelehealthSession;
use App\Models\User;
use App\Services\PatientConsentService;
use Database\Seeders\HimsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TelehealthWaitingRoomAndReferralTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(HimsSeeder::class);
    }

    public function test_patient_waits_in_provider_queue_and_can_join_only_after_start_and_consent(): void
    {
        [$patientUser, $doctor, $session] = $this->telehealthSession();
        $this->telehealthSession(null, $session->appointment->provider, today()->setTime(9, 0));
        $session->update(['start_time' => today()->setTime(10, 0)]);

        $waitingRoom = route('patients.portal.telehealth.waiting-room', $session);
        $this->actingAs($patientUser)->get($waitingRoom)
            ->assertOk()
            ->assertSee('Queue position: 2 of 2')
            ->assertSee('Your doctor has not started the session yet.')
            ->assertSee('Test camera and microphone')
            ->assertSee('getUserMedia')
            ->assertSee('id="device-check"', false)
            ->assertSee('Join virtual consultation')
            ->assertSee('Current telehealth consent is required before joining.')
            ->assertSee('disabled', false);

        app(PatientConsentService::class)->record(
            $patientUser->patient,
            'TELEHEALTH',
            true,
            $patientUser,
            'self'
        );

        $joinUrl = route('telehealth.join', [
            'session' => $session,
            'token' => $session->secureJoinToken(),
        ]);
        $this->actingAs($patientUser)->get($joinUrl)->assertStatus(409);
        $this->actingAs($patientUser)->getJson("/api/v1/telehealth/{$session->id}/waiting-room")
            ->assertOk()
            ->assertJsonPath('data.queue_position', 2)
            ->assertJsonPath('data.doctor_started', false)
            ->assertJsonPath('data.telehealth_consent', true)
            ->assertJsonPath('data.can_join', false)
            ->assertJsonPath('data.join_url', null);

        $otherDoctor = $this->userWithRole('doctor');
        Provider::create([
            'user_id' => $otherDoctor->id,
            'display_name' => 'Dr. Not Assigned',
            'active' => true,
        ]);
        $this->actingAs($otherDoctor)->postJson("/api/v1/telehealth/{$session->id}/start")
            ->assertForbidden();
        $this->actingAs($doctor)->postJson("/api/v1/telehealth/{$session->id}/start")
            ->assertOk();
        $session = $session->fresh();

        $this->actingAs($patientUser)->getJson("/api/v1/telehealth/{$session->id}/waiting-room")
            ->assertOk()
            ->assertJsonPath('data.doctor_started', true)
            ->assertJsonPath('data.telehealth_consent', true)
            ->assertJsonPath('data.can_join', true)
            ->assertJsonMissingPath('data.host_start_url');
        $this->actingAs($patientUser)->getJson("/api/v1/telehealth/{$session->id}")
            ->assertOk()
            ->assertJsonPath('data.join_url', $joinUrl)
            ->assertJsonMissingPath('data.host_start_url');
        $this->actingAs($patientUser)->get($waitingRoom)
            ->assertOk()
            ->assertSee(route('telehealth.join', [
                'session' => $session,
                'token' => $session->secureJoinToken(),
            ]), false);
        $this->actingAs($patientUser)->get($joinUrl)->assertOk();

        $otherPatient = $this->userWithRole('patient');
        Patient::factory()->create(['user_id' => $otherPatient->id]);
        $this->actingAs($otherPatient)->get($waitingRoom)->assertForbidden();
        $this->actingAs($otherPatient)->getJson("/api/v1/telehealth/{$session->id}/waiting-room")
            ->assertForbidden();
    }

    public function test_doctor_telehealth_list_and_details_are_scoped_to_their_provider(): void
    {
        [, $doctor, $session] = $this->telehealthSession();
        [, , $otherSession] = $this->telehealthSession();

        $this->actingAs($doctor)
            ->getJson('/api/v1/telehealth')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $session->id);

        $this->actingAs($doctor)
            ->getJson('/api/v1/telehealth/' . $session->id)
            ->assertOk();
        $this->actingAs($doctor)
            ->getJson('/api/v1/telehealth/' . $otherSession->id)
            ->assertForbidden();

        $this->actingAs($doctor)
            ->get(route('telehealth.show', $session))
            ->assertOk();
        $this->actingAs($doctor)
            ->get(route('telehealth.show', $otherSession))
            ->assertNotFound();
    }

    public function test_assigned_doctor_creates_audited_encrypted_referral_visible_to_patient(): void
    {
        [$patientUser, $doctor, $session, $department] = $this->telehealthSession();
        $reason = 'Patient needs an in-person abdominal examination.';

        $this->actingAs($doctor)
            ->get(route('telehealth.show', $session))
            ->assertOk()
            ->assertSee('Refer to face-to-face OPD visit');

        $this->actingAs($doctor)
            ->post(route('telehealth.face-to-face-referral', $session), ['reason' => $reason])
            ->assertRedirect(route('telehealth.show', $session));

        $referral = TelehealthReferral::where('telehealth_session_id', $session->id)->firstOrFail();
        $this->assertSame($session->appointment->patient_id, $referral->patient_id);
        $this->assertSame($department->id, $referral->department_id);
        $this->assertSame($doctor->id, $referral->created_by);
        $this->assertSame('REQUESTED', $referral->status);
        $this->assertSame($reason, $referral->reason);
        $this->assertNotSame($reason, DB::table('telehealth_referrals')->where('id', $referral->id)->value('reason'));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'CREATE_TELEHEALTH_FACE_TO_FACE_REFERRAL',
            'resource_type' => 'telehealth_referral',
            'resource_id' => $referral->id,
            'user_id' => $doctor->id,
        ]);
        $this->assertArrayNotHasKey('reason', AuditLog::where('action', 'CREATE_TELEHEALTH_FACE_TO_FACE_REFERRAL')->firstOrFail()->metadata);

        $this->actingAs($patientUser)
            ->get(route('patients.portal.telehealth'))
            ->assertOk()
            ->assertSee('Face-to-face OPD referral request')
            ->assertSee($department->name)
            ->assertSee($reason);
    }

    public function test_referral_api_reuses_validation_and_restricts_creation_to_assigned_doctor(): void
    {
        [, $doctor, $session] = $this->telehealthSession();
        $url = "/api/v1/telehealth/{$session->id}/face-to-face-referral";

        $this->actingAs($doctor)->postJson($url, ['reason' => 'short'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
        $this->assertSame(0, TelehealthReferral::count());

        $otherDoctor = $this->userWithRole('doctor');
        Provider::create([
            'user_id' => $otherDoctor->id,
            'display_name' => 'Dr. Other',
            'active' => true,
        ]);
        $this->actingAs($otherDoctor)->postJson($url, [
            'reason' => 'Needs a physical examination.',
        ])->assertForbidden();

        $nurse = $this->userWithRole('nurse');
        $this->actingAs($nurse)->postJson($url, [
            'reason' => 'Needs a physical examination.',
        ])->assertForbidden();

        $this->actingAs($doctor)->postJson($url, [
            'reason' => 'Needs a physical examination.',
        ])->assertCreated()
            ->assertJsonPath('data.patient_id', $session->appointment->patient_id)
            ->assertJsonPath('data.department_id', $session->appointment->department_id);

        $patientUser = $this->userWithRole('patient');
        $session->appointment->patient->update(['user_id' => $patientUser->id]);
        $this->actingAs($patientUser)->getJson("/api/v1/telehealth/{$session->id}/waiting-room")
            ->assertOk()
            ->assertJsonPath('data.can_join', false);
    }

    private function telehealthSession(
        ?User $patientUser = null,
        ?Provider $provider = null,
        mixed $startsAt = null
    ): array {
        $patientUser ??= $this->userWithRole('patient');
        $patient = Patient::factory()->create(['user_id' => $patientUser->id]);
        $doctor = $provider?->user ?? $this->userWithRole('doctor');
        $department = Department::query()->firstOrFail();
        $provider ??= Provider::create([
            'user_id' => $doctor->id,
            'department_id' => $department->id,
            'display_name' => 'Dr. Assigned',
            'active' => true,
        ]);
        $appointmentType = AppointmentType::where('telehealth', true)->firstOrFail();
        $startsAt ??= today()->setTime(11, 0);
        $appointment = Appointment::create([
            'appointment_number' => 'APT-WAITING-'.Str::upper(Str::random(8)),
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
            'department_id' => $department->id,
            'appointment_type_id' => $appointmentType->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes(30),
            'status' => Appointment::STATUS_CONFIRMED,
        ]);
        $session = TelehealthSession::create([
            'appointment_id' => $appointment->id,
            'start_time' => $startsAt,
            'duration' => 30,
            'status' => TelehealthSession::STATUS_SCHEDULED,
            'join_url' => 'https://meet.example.test/session',
            'host_start_url' => 'https://meet.example.test/host',
        ]);

        return [$patientUser, $doctor, $session, $department];
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create(['email' => $roleName.'-'.Str::uuid().'@example.test']);
        $user->roles()->attach(Role::where('name', $roleName)->firstOrFail());

        return $user;
    }
}
