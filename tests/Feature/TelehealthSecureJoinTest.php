<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\PatientConsent;
use App\Models\Provider;
use App\Models\Role;
use App\Models\TelehealthJoinToken;
use App\Models\TelehealthSession;
use App\Models\User;
use App\Services\TelehealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TelehealthSecureJoinTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'telehealth.provider' => 'jitsi_demo',
            'telehealth.join_early_minutes' => 15,
            'telehealth.join_late_minutes' => 30,
        ]);
        Http::preventStrayRequests();
    }

    public function test_wrong_patient_is_denied_and_reason_is_audited(): void
    {
        [$session] = $this->sessionFixture();
        $wrongUser = $this->userWithRole('patient');
        $wrongPatient = Patient::factory()->create(['user_id' => $wrongUser->id]);
        $this->acceptRequiredPrivacyDocuments($wrongPatient, $wrongUser);

        $this->actingAs($wrongUser)
            ->get(route('telehealth.join', $session))
            ->assertForbidden();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'TELEHEALTH_JOIN_DENIED',
            'resource_id' => $session->id,
            'result' => 'FAILURE',
        ]);
        $this->assertSame('not_appointment_patient_or_assigned_doctor', AuditLog::query()
            ->where('action', 'TELEHEALTH_JOIN_DENIED')->latest('id')->firstOrFail()->metadata['reason']);
    }

    public function test_join_is_denied_without_current_patient_telehealth_consent(): void
    {
        [$session, $patientUser] = $this->sessionFixture();

        $this->actingAs($patientUser)
            ->get(route('telehealth.join', $session))
            ->assertRedirect(route('patient-consents.portal', ['required' => 'TELEHEALTH']));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'TELEHEALTH_JOIN_DENIED',
            'resource_id' => $session->id,
            'result' => 'FAILURE',
        ]);
    }

    public function test_join_outside_configured_time_window_is_denied(): void
    {
        [$session, $patientUser] = $this->sessionFixture(startOffsetMinutes: 60);
        $this->grantTelehealthConsent($session->appointment->patient, $patientUser);

        $this->actingAs($patientUser)
            ->get(route('telehealth.join', $session))
            ->assertForbidden()
            ->assertSee('starting 15 minutes before', false);

        $this->assertSame('too_early', AuditLog::query()
            ->where('action', 'TELEHEALTH_JOIN_DENIED')->latest('id')->firstOrFail()->metadata['reason']);
    }

    public function test_join_token_expires_and_is_single_use(): void
    {
        [$session, $patientUser] = $this->sessionFixture();
        $service = app(TelehealthService::class);
        $token = $service->issueJoinToken($session, $patientUser);
        $service->consumeJoinToken($session, $patientUser, $token);

        try {
            $service->consumeJoinToken($session, $patientUser, $token);
            $this->fail('A consumed join token must not be accepted again.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('token', $exception->errors());
        }

        $expiredToken = $service->issueJoinToken($session, $patientUser);
        $this->travel(6)->minutes();
        try {
            $service->consumeJoinToken($session, $patientUser, $expiredToken);
            $this->fail('An expired join token must not be accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('token', $exception->errors());
        }
    }

    public function test_zoom_meeting_uses_private_settings_and_audit_contains_no_meeting_secrets(): void
    {
        [$session, , $doctorUser] = $this->sessionFixture();
        $this->grantTelehealthConsent($session->appointment->patient, $session->appointment->patient->user);
        config([
            'telehealth.provider' => 'zoom',
            'services.zoom.enabled' => true,
            'services.zoom.account_id' => 'test-account',
            'services.zoom.client_id' => 'test-client',
            'services.zoom.client_secret' => 'test-secret',
        ]);
        Cache::forget('zoom_access_token');
        Http::fake([
            'https://zoom.us/oauth/token' => Http::response(['access_token' => 'fake-access-token'], 200),
            'https://api.zoom.us/v2/users/me/meetings' => Http::response([
                'id' => '998877',
                'join_url' => 'https://zoom.example.test/j/998877',
                'start_url' => 'https://zoom.example.test/s/998877',
            ], 201),
        ]);

        $this->actingAs($doctorUser)
            ->post(route('telehealth.connect', $session), [
                'privacy_acknowledged' => '1',
                'join_token' => app(TelehealthService::class)->issueJoinToken($session, $doctorUser),
            ])
            ->assertOk()
            ->assertSee('Join consultation in Zoom')
            ->assertSee('Meeting passcode');

        Http::assertSent(function ($request): bool {
            if ($request->url() !== 'https://api.zoom.us/v2/users/me/meetings') {
                return false;
            }
            $settings = $request['settings'];

            return $request['password'] !== ''
                && $settings['waiting_room'] === true
                && $settings['join_before_host'] === false
                && $settings['participant_video'] === true
                && $settings['auto_recording'] === 'none';
        });

        $session->refresh();
        $passcode = $session->meeting_passcode;
        $this->assertNotEmpty($passcode);
        $this->assertDatabaseHas('telehealth_sessions', ['id' => $session->id]);
        $this->assertStringNotContainsString($passcode, json_encode(AuditLog::query()->pluck('metadata')));
        $this->assertStringNotContainsString('https://zoom.example.test', json_encode(AuditLog::query()->pluck('metadata')));
        $this->assertStringNotContainsString($passcode, $session->getRawOriginal('meeting_passcode'));
    }

    public function test_jitsi_demo_room_name_is_opaque_and_demo_warning_is_visible(): void
    {
        [$session, $patientUser] = $this->sessionFixture();
        $service = app(TelehealthService::class);
        $details = $service->providerJoinDetails($session, $patientUser);

        $this->assertSame('jitsi_demo', $details['provider']);
        $this->assertStringStartsWith('HIMS-', $details['room_name']);
        $this->assertNotSame('HIMS-'.$session->appointment_id, $details['room_name']);
        $this->assertMatchesRegularExpression('/^HIMS-[A-F0-9]{28}$/', $details['room_name']);

        $this->grantTelehealthConsent($session->appointment->patient, $patientUser);
        $session->update(['status' => TelehealthSession::STATUS_ACTIVE]);
        $this->actingAs($patientUser)
            ->get(route('telehealth.join', $session))
            ->assertOk()
            ->assertSee('Demo video mode: not suitable for real patient consultations.');
    }

    public function test_doctor_join_transitions_appointment_and_records_participation(): void
    {
        [$session, , $doctorUser] = $this->sessionFixture();
        $this->grantTelehealthConsent($session->appointment->patient, $session->appointment->patient->user);

        $this->actingAs($doctorUser)
            ->post(route('telehealth.connect', $session), [
                'privacy_acknowledged' => '1',
                'join_token' => app(TelehealthService::class)->issueJoinToken($session, $doctorUser),
            ])
            ->assertOk()
            ->assertSee('jitsi-demo-frame');

        $this->assertSame(Appointment::STATUS_IN_CONSULTATION, $session->appointment->fresh()->status);
        $participant = $session->participants()->where('user_id', $doctorUser->id)->firstOrFail();
        $this->assertNotNull($participant->joined_at);
        $this->assertNotNull($participant->privacy_acknowledged_at);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'TELEHEALTH_DOCTOR_JOINED',
            'resource_id' => $session->id,
        ]);
    }

    public function test_join_acknowledgement_and_audit_never_store_urls_passcodes_or_tokens(): void
    {
        [$session, $patientUser] = $this->sessionFixture();
        $this->grantTelehealthConsent($session->appointment->patient, $patientUser);
        $session->update(['status' => TelehealthSession::STATUS_ACTIVE]);

        $this->actingAs($patientUser)
            ->post(route('telehealth.connect', $session), [
                'privacy_acknowledged' => '1',
                'join_token' => app(TelehealthService::class)->issueJoinToken($session, $patientUser),
            ])
            ->assertOk();

        $auditJson = json_encode(AuditLog::query()->pluck('metadata'));
        $this->assertStringNotContainsString('join_url', strtolower($auditJson));
        $this->assertStringNotContainsString('room_name', strtolower($auditJson));
        $this->assertStringNotContainsString('token', strtolower($auditJson));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'TELEHEALTH_CONSENT_ACKNOWLEDGED',
            'resource_id' => $session->id,
        ]);
        $this->assertDatabaseHas('telehealth_join_tokens', [
            'telehealth_session_id' => $session->id,
            'user_id' => $patientUser->id,
        ]);
        $this->assertNotNull(TelehealthJoinToken::query()->firstOrFail()->used_at);
    }

    private function sessionFixture(int $startOffsetMinutes = 0): array
    {
        $patientUser = $this->userWithRole('patient');
        $patient = Patient::factory()->create(['user_id' => $patientUser->id]);
        $this->acceptRequiredPrivacyDocuments($patient, $patientUser);
        $doctorUser = $this->userWithRole('doctor');
        $provider = Provider::factory()->create(['user_id' => $doctorUser->id]);
        $appointmentType = AppointmentType::query()->firstOrCreate(
            ['name' => 'Secure telehealth '.Str::random(6)],
            ['default_duration' => 30, 'telehealth' => true, 'active' => true]
        );
        $startsAt = now()->addMinutes($startOffsetMinutes);
        $appointment = Appointment::factory()->create([
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
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
        ]);

        return [$session->fresh(['appointment.patient.user', 'appointment.provider']), $patientUser, $doctorUser];
    }

    private function grantTelehealthConsent(Patient $patient, User $user): void
    {
        PatientConsent::create([
            'patient_id' => $patient->id,
            'type' => 'TELEHEALTH',
            'version' => config('consents.types.TELEHEALTH.version'),
            'granted' => true,
            'recorded_at' => now(),
            'recorded_by' => $user->id,
            'channel' => 'self',
        ]);
    }

    private function userWithRole(string $name): User
    {
        $role = Role::firstOrCreate(['name' => $name], ['label' => ucfirst($name)]);
        $user = User::factory()->create();
        $user->roles()->attach($role);

        return $user;
    }
}
