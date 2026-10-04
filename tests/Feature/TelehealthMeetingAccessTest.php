<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\IntegrationLog;
use App\Models\Patient;
use App\Models\Provider;
use App\Models\Role;
use App\Models\TelehealthSession;
use App\Models\User;
use App\Services\PatientConsentService;
use App\Services\ZoomService;
use Database\Seeders\HimsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

class TelehealthMeetingAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(HimsSeeder::class);
        Cache::forget('zoom_access_token');
    }

    public function test_only_assigned_doctor_and_super_admin_see_meeting_urls_on_web_and_api(): void
    {
        [$doctor, $patient, $appointment, $session] = $this->makeSession();
        $session->update([
            'zoom_meeting_id' => 'zoom-meeting-private',
            'join_url' => 'https://zoom.example.test/j/meeting?pwd=join-secret',
            'host_start_url' => 'https://zoom.example.test/s/meeting?zak=host-secret',
        ]);
        $superAdmin = $this->userWithRole('super-admin');
        $nurse = $this->userWithRole('nurse');
        $registration = $this->userWithRole('registration');

        foreach ([$doctor, $superAdmin] as $authorizedUser) {
            $this->actingAs($authorizedUser)->get(route('telehealth.show', $session))
                ->assertOk()
                ->assertSee('zoom.example.test/j/meeting')
                ->assertSee('zoom.example.test/s/meeting')
                ->assertSee('zoom-meeting-private');

            $response = $this->actingAs($authorizedUser)->getJson('/api/v1/telehealth/'.$session->id);
            $response->assertOk()
                ->assertJsonPath('data.join_url', $session->join_url)
                ->assertJsonPath('data.host_start_url', $session->host_start_url)
                ->assertJsonPath('data.zoom_meeting_id', $session->zoom_meeting_id);
        }

        $this->actingAs($nurse)->get(route('telehealth.show', $session))
            ->assertOk()
            ->assertDontSee('join-secret')
            ->assertDontSee('host-secret')
            ->assertDontSee('zoom-meeting-private');
        $this->actingAs($nurse)->get(route('telehealth.index'))
            ->assertOk()
            ->assertDontSee('join-secret')
            ->assertDontSee('host-secret')
            ->assertDontSee('zoom-meeting-private');
        $nurseApiData = $this->actingAs($nurse)
            ->getJson('/api/v1/telehealth/'.$session->id)
            ->assertOk()
            ->json('data');
        $this->assertArrayNotHasKey('join_url', $nurseApiData);
        $this->assertArrayNotHasKey('host_start_url', $nurseApiData);
        $this->assertArrayNotHasKey('zoom_meeting_id', $nurseApiData);

        $this->actingAs($registration)->get(route('telehealth.show', $session))->assertForbidden();
        $this->actingAs($registration)->getJson('/api/v1/telehealth/'.$session->id)->assertForbidden();

        $patientUser = $this->userWithRole('patient');
        $patient->update(['user_id' => $patientUser->id]);
        app(PatientConsentService::class)->record($patient, 'TELEHEALTH', true, $doctor, 'staff');
        $patientData = $this->actingAs($patientUser)
            ->getJson('/api/v1/telehealth/'.$session->id)
            ->assertOk()
            ->json('data');
        $this->assertSame(route('telehealth.join', [
            'session' => $session,
            'token' => $session->secureJoinToken(),
        ]), $patientData['join_url']);
        $this->assertArrayNotHasKey('host_start_url', $patientData);
        $this->assertArrayNotHasKey('zoom_meeting_id', $patientData);
    }

    public function test_unassigned_doctor_cannot_retrieve_or_change_another_doctors_meeting(): void
    {
        [, , , $session] = $this->makeSession();
        $unassignedDoctor = $this->userWithRole('doctor');
        Provider::create([
            'user_id' => $unassignedDoctor->id,
            'display_name' => 'Dr. Unassigned',
            'active' => true,
        ]);

        $this->actingAs($unassignedDoctor)
            ->getJson('/api/v1/telehealth/'.$session->id)
            ->assertForbidden();
        $this->actingAs($unassignedDoctor)
            ->post(route('telehealth.create-meeting', $session), ['duration' => 30])
            ->assertForbidden();
    }

    public function test_zoom_success_uses_fake_api_and_persists_meeting_details(): void
    {
        [, , $appointment] = $this->makeSession();
        $this->configureZoom();
        Http::fake([
            'https://zoom.us/oauth/token' => Http::response(['access_token' => 'fake-access-token'], 200),
            'https://api.zoom.us/v2/users/me/meetings' => Http::response([
                'id' => 'fake-meeting-id',
                'join_url' => 'https://zoom.example.test/j/fake?pwd=fake-passcode',
                'start_url' => 'https://zoom.example.test/s/fake?zak=fake-host-token',
            ], 201),
        ]);

        $superAdmin = $this->userWithRole('super-admin');
        $response = $this->actingAs($superAdmin)->postJson('/api/v1/telehealth', [
            'appointment_id' => $appointment->id,
        ]);
        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Telehealth session created successfully.');
        $session = TelehealthSession::findOrFail($response->json('data.id'));

        $this->assertSame('fake-meeting-id', $session->zoom_meeting_id);
        $this->assertSame('https://zoom.example.test/j/fake?pwd=fake-passcode', $session->join_url);
        $this->assertSame('https://zoom.example.test/s/fake?zak=fake-host-token', $session->host_start_url);
        $this->assertSame(TelehealthSession::STATUS_SCHEDULED, $session->status);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.zoom.us/v2/users/me/meetings'
            && $request->hasHeader('Authorization', 'Bearer fake-access-token'));
    }

    public function test_missing_zoom_configuration_uses_protected_local_room_without_http_calls(): void
    {
        [, , $appointment] = $this->makeSession();
        config([
            'services.zoom.enabled' => true,
            'services.zoom.account_id' => null,
            'services.zoom.client_id' => null,
            'services.zoom.client_secret' => null,
        ]);
        Http::fake();
        app()->forgetInstance(ZoomService::class);

        $superAdmin = $this->userWithRole('super-admin');
        $response = $this->actingAs($superAdmin)->postJson('/api/v1/telehealth', [
            'appointment_id' => $appointment->id,
        ]);
        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Telehealth session created with a protected local room because a Zoom meeting is unavailable.');
        $session = TelehealthSession::findOrFail($response->json('data.id'));

        $this->assertSame(TelehealthSession::STATUS_SCHEDULED, $session->status);
        $this->assertStringContainsString('/telehealth/'.$session->id.'/join?token=', $session->join_url);
        $this->assertSame($session->join_url, $session->host_start_url);
        Http::assertNothingSent();
    }

    public function test_zoom_api_failure_falls_back_and_never_logs_sensitive_response_content(): void
    {
        [, , $appointment] = $this->makeSession();
        $this->configureZoom();
        $secretFragments = [
            'fake-access-token',
            'fake-client-secret',
            'fake-passcode',
            'fake-join-url',
            'fake-host-url',
            'raw-zoom-response-secret',
        ];
        Http::fake([
            'https://zoom.us/oauth/token' => Http::response(['access_token' => 'fake-access-token'], 200),
            'https://api.zoom.us/v2/users/me/meetings' => Http::response([
                'code' => 124,
                'message' => implode(' ', $secretFragments),
            ], 503),
        ]);
        Log::spy();

        $superAdmin = $this->userWithRole('super-admin');
        $response = $this->actingAs($superAdmin)->postJson('/api/v1/telehealth', [
            'appointment_id' => $appointment->id,
        ]);
        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('integration.status', 'FALLBACK_LOCAL');
        $session = TelehealthSession::findOrFail($response->json('data.id'));

        $this->assertSame(TelehealthSession::STATUS_SCHEDULED, $session->status);
        $this->assertNull($session->zoom_meeting_id);
        $this->assertStringContainsString('/telehealth/'.$session->id.'/join?token=', $session->join_url);
        $this->assertDatabaseHas('telehealth_sessions', ['id' => $session->id, 'status' => TelehealthSession::STATUS_SCHEDULED]);
        $metadata = json_encode(IntegrationLog::where('integration', 'zoom')->get()->pluck('metadata')->all());
        foreach ($secretFragments as $secret) {
            $this->assertStringNotContainsString($secret, $metadata);
        }
        Log::shouldNotHaveReceived('warning');
    }

    private function configureZoom(): void
    {
        config([
            'services.zoom.enabled' => true,
            'services.zoom.account_id' => 'fake-account-id',
            'services.zoom.client_id' => 'fake-client-id',
            'services.zoom.client_secret' => 'fake-client-secret',
        ]);
        app()->forgetInstance(ZoomService::class);
        Cache::forget('zoom_access_token');
    }

    private function makeSession(): array
    {
        $doctor = $this->userWithRole('doctor');
        $patient = Patient::factory()->create();
        $provider = Provider::create([
            'user_id' => $doctor->id,
            'display_name' => 'Dr. Assigned',
            'active' => true,
        ]);
        $appointment = Appointment::create([
            'appointment_number' => 'APT-ZOOM-'.Str::upper(Str::random(8)),
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
            'appointment_type_id' => AppointmentType::where('telehealth', true)->firstOrFail()->id,
            'starts_at' => now(),
            'ends_at' => now()->addMinutes(30),
            'status' => Appointment::STATUS_IN_CONSULTATION,
        ]);
        $session = TelehealthSession::create([
            'appointment_id' => $appointment->id,
            'start_time' => now(),
            'duration' => 30,
            'status' => TelehealthSession::STATUS_ACTIVE,
        ]);

        return [$doctor, $patient, $appointment, $session];
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create([
            'email' => $roleName.'-'.Str::uuid().'@example.test',
            'email_verified_at' => now(),
        ]);
        $user->roles()->attach(Role::where('name', $roleName)->firstOrFail());

        return $user;
    }
}
