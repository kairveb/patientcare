<?php

namespace Tests\Feature;

use App\Jobs\DeliverIntegrationOutbox;
use App\Models\Admission;
use App\Models\ErQueue;
use App\Models\ErVisit;
use App\Models\IntegrationDisclosure;
use App\Models\IntegrationEvent;
use App\Models\IntegrationOutbox;
use App\Models\IntegrationPartner;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TransportRequest;
use App\Models\User;
use App\Services\IntegrationPayloadFactory;
use App\Services\TransportRequestService;
use Database\Seeders\AdminRoleSeeder;
use Database\Seeders\IntegrationPartnerSeeder;
use Database\Seeders\IntegrationRbacSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class VRDSTransportIntegrationTest extends TestCase
{
    private const ROLES = ['patient', 'doctor', 'nurse', 'registration', 'admin', 'super-admin'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(AdminRoleSeeder::class);
        $this->seed(IntegrationRbacSeeder::class);
        config()->set('integrations.mode', 'mock');
        $this->seed(IntegrationPartnerSeeder::class);
        IntegrationPartner::query()->where('code', 'vrds')->firstOrFail()->update([
            'active' => true,
            'api_key_hash' => Hash::make('test-vrds-secret'),
            'webhook_signing_secret' => 'webhook-secret-vrds',
            'allowed_abilities' => ['dispatch-status'],
        ]);
        Queue::fake();
    }

    public function test_mock_dispatch_payload_is_identifier_free_and_records_null_patient_disclosure(): void
    {
        $requester = $this->userWithRole('nurse');
        [$visit] = $this->sources();
        ErQueue::query()->create([
            'er_visit_id' => $visit->id,
            'priority' => 'Level 1',
            'status' => 'WAITING',
            'queued_at' => now(),
        ]);

        $transport = app(TransportRequestService::class)->create([
            'source_type' => 'er_visit',
            'source_id' => $visit->id,
            'pickup_zone' => 'ER',
            'destination_zone' => 'WARD',
            'needs' => ['stretcher_required' => true],
        ], $requester);

        $outbox = IntegrationOutbox::query()->where('entity_type', 'vrds_dispatch_request')->firstOrFail();
        $payload = app(IntegrationPayloadFactory::class)->make($outbox, 'vrds-test-send-1');
        $this->assertSame('EMERGENCY', $payload['priority']);
        $this->assertSame(['request_ref', 'priority', 'pickup_zone', 'destination_zone', 'needs', 'passenger_count'], array_keys($payload));
        $this->assertStringNotContainsString($visit->patient->first_name, json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertDatabaseHas('integration_disclosures', [
            'integration_partner_id' => $outbox->integration_partner_id,
            'patient_id' => null,
            'purpose' => 'patient transport dispatch',
            'request_id' => 'vrds-test-send-1',
        ]);
        $this->assertInstanceOf(IntegrationDisclosure::class, IntegrationDisclosure::query()->where('request_id', 'vrds-test-send-1')->first());

        (new DeliverIntegrationOutbox((int) $outbox->id))->handle(app(IntegrationPayloadFactory::class));
        $this->assertSame('COMPLETED', $transport->fresh()->status);
        $this->assertSame(5, $transport->histories()->count());
        $this->assertNotNull($requester->notifications()->first());
        $this->assertStringNotContainsString($visit->patient->first_name, $requester->notifications()->first()->data['message']);
    }

    public function test_vrds_signed_events_are_authenticated_idempotent_and_transition_checked(): void
    {
        $requester = $this->userWithRole('nurse');
        $transport = TransportRequest::query()->create([
            'source_type' => 'er_visit',
            'source_id' => $this->sources()[0]->id,
            'priority' => 'URGENT',
            'pickup_zone' => 'ER',
            'destination_zone' => 'WARD',
            'needs' => [],
            'passenger_count' => 1,
            'status' => 'REQUESTED',
            'requested_by' => $requester->id,
        ]);
        $transport->update(['external_id' => 'VRDS-TEST-1']);
        $url = '/api/v1/integrations/vrds/webhooks/dispatch-status';
        $payload = [
            'event_id' => 'vrds-event-1',
            'external_id' => 'VRDS-TEST-1',
            'status' => 'ACCEPTED',
        ];

        $this->signedRequest($url, $payload)->assertOk();
        $this->signedRequest($url, $payload)->assertOk();
        $this->assertSame('ACCEPTED', $transport->fresh()->status);
        $this->assertSame(1, IntegrationEvent::query()->where('event_id', 'vrds-event-1')->count());
        $this->assertSame(1, $transport->histories()->count());

        $this->signedRequest($url, [
            ...$payload,
            'event_id' => 'vrds-invalid-transition',
            'status' => 'COMPLETED',
        ])->assertStatus(409)->assertJsonPath('code', 'invalid_status_transition');
        $this->assertDatabaseHas('integration_logs', ['event' => 'DISPATCH_STATUS_REJECTED', 'status' => 'FAILURE']);
        $this->signedRequest($url, [...$payload, 'event_id' => 'unknown-id', 'external_id' => 'unknown'])
            ->assertNotFound();

        $this->withHeaders(['X-Partner-Code' => 'vrds', 'X-Integration-Key' => 'invalid-key'])
            ->postJson($url, $payload)->assertUnauthorized();
        $partner = IntegrationPartner::query()->where('code', 'vrds')->firstOrFail();
        $partner->update(['allowed_abilities' => ['other-ability']]);
        $this->signedRequest($url, [...$payload, 'event_id' => 'wrong-ability'])->assertUnauthorized();
        $partner->update(['allowed_abilities' => ['dispatch-status']]);

        foreach (self::ROLES as $roleName) {
            $human = $this->userWithRole($roleName);
            $this->withHeaders(['Authorization' => 'Bearer '.$human->createToken('human-api-token')->plainTextToken])
                ->postJson($url, [...$payload, 'event_id' => 'human-token-'.$roleName])->assertUnauthorized();
        }
        $this->withHeaders([])->call('POST', $url, [], [], [], [
            'HTTP_X_PARTNER_CODE' => 'vrds',
            'HTTP_X_INTEGRATION_KEY' => 'test-vrds-secret',
            'HTTP_X_WEBHOOK_TIMESTAMP' => (string) now()->timestamp,
            'HTTP_X_WEBHOOK_SIGNATURE' => 'invalid-signature',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([...$payload, 'event_id' => 'bad-signature'], JSON_THROW_ON_ERROR))->assertUnauthorized();
    }

    public function test_api_transport_roles_match_the_web_controls_for_all_six_roles(): void
    {
        [$visit, $admission] = $this->sources();
        $list = '/api/v1/transport-requests';
        foreach (self::ROLES as $roleName) {
            $user = $this->userWithRole($roleName);
            $allowedToView = in_array($roleName, ['doctor', 'nurse', 'registration', 'super-admin'], true);
            $this->actingAs($user)->getJson($list)->assertStatus($allowedToView ? 200 : 403);
            $this->actingAs($user)->get(route('emergency.show', $visit))->assertStatus($allowedToView ? 200 : 403);
            $this->actingAs($user)->get(route('admissions.show', $admission))->assertStatus($allowedToView ? 200 : 403);
            $this->actingAs($user)->getJson('/api/v1/emergency/queue')->assertStatus($allowedToView ? 200 : 403);
            $this->actingAs($user)->getJson('/api/v1/emergency/visits/'.$visit->id)->assertStatus($allowedToView ? 200 : 403);
            $this->actingAs($user)->getJson('/api/v1/admissions')->assertStatus($allowedToView ? 200 : 403);
            $this->actingAs($user)->getJson('/api/v1/admissions/'.$admission->id)->assertStatus($allowedToView ? 200 : 403);

            $allowedToRequest = in_array($roleName, ['nurse', 'registration', 'super-admin'], true);
            $payload = [
                'source_type' => 'er_visit',
                'source_id' => $visit->id,
                'pickup_zone' => 'ER',
                'destination_zone' => 'WARD',
            ];
            $this->actingAs($user)->postJson($list, $payload)->assertStatus($allowedToRequest ? 201 : 403);
            $webRequest = $this->actingAs($user)->post(route('emergency.transport-requests.store', $visit), [
                'pickup_zone' => 'ER',
                'destination_zone' => 'WARD',
            ]);
            $webRequest->assertStatus($allowedToRequest ? 302 : 403);

            $admissionPayload = [
                'source_type' => 'admission',
                'source_id' => $admission->id,
                'priority' => 'ROUTINE',
                'pickup_zone' => 'WARD',
                'destination_zone' => 'MAIN_ENTRANCE',
            ];
            $this->actingAs($user)->postJson($list, $admissionPayload)->assertStatus($allowedToRequest ? 201 : 403);
            $webAdmissionRequest = $this->actingAs($user)->post(route('admissions.transport-requests.store', $admission), [
                'priority' => 'ROUTINE',
                'pickup_zone' => 'WARD',
                'destination_zone' => 'MAIN_ENTRANCE',
            ]);
            $webAdmissionRequest->assertStatus($allowedToRequest ? 302 : 403);
        }

        $patient = $this->userWithRole('patient');
        $this->actingAs($patient)->getJson($list)->assertForbidden();
        $this->actingAs($patient)->postJson($list, [
            'source_type' => 'admission',
            'source_id' => $admission->id,
            'priority' => 'ROUTINE',
            'pickup_zone' => 'WARD',
            'destination_zone' => 'MAIN_ENTRANCE',
        ])->assertForbidden();
    }

    public function test_transport_show_and_cancel_api_and_web_routes_use_the_same_role_rules(): void
    {
        [$visit, $admission] = $this->sources();
        $transport = TransportRequest::query()->create([
            'source_type' => 'er_visit',
            'source_id' => $visit->id,
            'priority' => 'ROUTINE',
            'pickup_zone' => 'ER',
            'destination_zone' => 'WARD',
            'needs' => [],
            'passenger_count' => 1,
            'status' => 'REQUESTED',
        ]);
        foreach (self::ROLES as $roleName) {
            $user = $this->userWithRole($roleName);
            $allowedToView = in_array($roleName, ['doctor', 'nurse', 'registration', 'super-admin'], true);
            $allowedToCancel = in_array($roleName, ['nurse', 'registration', 'super-admin'], true);
            $this->actingAs($user)->getJson('/api/v1/transport-requests/'.$transport->id)
                ->assertStatus($allowedToView ? 200 : 403);
            $this->actingAs($user)->postJson('/api/v1/transport-requests/'.$transport->id.'/cancel')
                ->assertStatus($allowedToCancel ? 200 : 403);
            $this->actingAs($user)->post(route('transport-requests.cancel', $transport))
                ->assertStatus($allowedToCancel ? 302 : 403);
        }

        $permission = Permission::query()->where('name', 'request-patient-transport')->firstOrFail();
        Role::query()->where('name', 'super-admin')->firstOrFail()->permissions()->detach($permission->id);
        $superAdminWithoutTransportPermission = $this->userWithRole('super-admin');
        $this->actingAs($superAdminWithoutTransportPermission)->get(route('emergency.show', $visit))
            ->assertOk()
            ->assertDontSeeText('Request transport');
        $this->actingAs($superAdminWithoutTransportPermission)->get(route('admissions.show', $admission))
            ->assertOk()
            ->assertDontSeeText('Request transport');
        $this->actingAs($superAdminWithoutTransportPermission)->postJson('/api/v1/transport-requests', [
            'source_type' => 'er_visit',
            'source_id' => $visit->id,
            'pickup_zone' => 'ER',
            'destination_zone' => 'WARD',
        ])->assertForbidden();
        $this->actingAs($superAdminWithoutTransportPermission)
            ->postJson('/api/v1/transport-requests/'.$transport->id.'/cancel')
            ->assertForbidden();
        $this->actingAs($superAdminWithoutTransportPermission)
            ->post(route('transport-requests.cancel', $transport))
            ->assertForbidden();
    }

    public function test_only_authorized_roles_see_request_controls_on_existing_er_and_admission_pages(): void
    {
        [$visit, $admission] = $this->sources();
        foreach (self::ROLES as $roleName) {
            $user = $this->userWithRole($roleName);
            $canRequest = in_array($roleName, ['nurse', 'registration', 'super-admin'], true);
            $erPage = $this->actingAs($user)->get(route('emergency.show', $visit));
            $admissionPage = $this->actingAs($user)->get(route('admissions.show', $admission));
            if (in_array($roleName, ['patient', 'admin'], true)) {
                $erPage->assertForbidden();
                $admissionPage->assertForbidden();

                continue;
            }
            $erPage->assertOk();
            $admissionPage->assertOk();
            if ($canRequest) {
                $erPage->assertSeeText('Request transport');
                $admissionPage->assertSeeText('Request transport');
            } else {
                $erPage->assertDontSeeText('Request transport');
                $admissionPage->assertDontSeeText('Request transport');
            }
        }
    }

    public function test_transport_status_blocks_are_visible_only_to_the_existing_page_view_roles(): void
    {
        [$visit, $admission] = $this->sources();
        foreach ([
            ['source_type' => 'er_visit', 'source_id' => $visit->id],
            ['source_type' => 'admission', 'source_id' => $admission->id],
        ] as $source) {
            TransportRequest::query()->create($source + [
                'priority' => 'ROUTINE',
                'pickup_zone' => $source['source_type'] === 'er_visit' ? 'ER' : 'WARD',
                'destination_zone' => 'MAIN_ENTRANCE',
                'needs' => [],
                'passenger_count' => 1,
                'status' => 'REQUESTED',
            ]);
        }

        foreach (self::ROLES as $roleName) {
            $user = $this->userWithRole($roleName);
            $allowed = in_array($roleName, ['doctor', 'nurse', 'registration', 'super-admin'], true);
            $erPage = $this->actingAs($user)->get(route('emergency.show', $visit));
            $admissionPage = $this->actingAs($user)->get(route('admissions.show', $admission));
            if ($allowed) {
                $erPage->assertOk()->assertSeeText('Status: REQUESTED');
                $admissionPage->assertOk()->assertSeeText('Status: REQUESTED');
            } else {
                $erPage->assertForbidden();
                $admissionPage->assertForbidden();
            }
        }
    }

    public function test_request_role_without_transport_permission_cannot_see_or_use_the_control(): void
    {
        [$visit] = $this->sources();
        $nurse = $this->userWithRole('nurse');
        $permission = \App\Models\Permission::query()->where('name', 'request-patient-transport')->firstOrFail();
        $nurse->roles()->firstOrFail()->permissions()->detach($permission->id);

        $this->actingAs($nurse)->get(route('emergency.show', $visit))
            ->assertOk()
            ->assertDontSeeText('Request transport');
        $this->actingAs($nurse)->post(route('emergency.transport-requests.store', $visit), [
            'pickup_zone' => 'ER',
            'destination_zone' => 'WARD',
        ])->assertForbidden();
        $this->actingAs($nurse)->postJson('/api/v1/transport-requests', [
            'source_type' => 'er_visit',
            'source_id' => $visit->id,
            'pickup_zone' => 'ER',
            'destination_zone' => 'WARD',
        ])->assertForbidden();
    }

    public function test_free_text_or_unknown_need_flags_are_rejected_and_never_sent(): void
    {
        $nurse = $this->userWithRole('nurse');
        [$visit] = $this->sources();

        $this->actingAs($nurse)->postJson('/api/v1/transport-requests', [
            'source_type' => 'er_visit',
            'source_id' => $visit->id,
            'pickup_zone' => 'OUTSIDE',
            'destination_zone' => 'ER',
            'needs' => ['address' => 'Synthetic outside address'],
        ])->assertUnprocessable();

        $transport = app(TransportRequestService::class)->create([
            'source_type' => 'er_visit',
            'source_id' => $visit->id,
            'pickup_zone' => 'OUTSIDE',
            'destination_zone' => 'ER',
            'needs' => ['address' => 'Synthetic outside address', 'wheelchair' => true],
        ], $nurse);
        $this->assertSame(['wheelchair' => true], $transport->needs);
    }

    public function test_partner_outage_does_not_block_local_er_or_admission_transport_requests(): void
    {
        IntegrationPartner::query()->where('code', 'vrds')->update(['active' => false]);
        $nurse = $this->userWithRole('nurse');
        [$visit, $admission] = $this->sources();

        $this->actingAs($nurse)->postJson('/api/v1/transport-requests', [
            'source_type' => 'er_visit',
            'source_id' => $visit->id,
            'pickup_zone' => 'ER',
            'destination_zone' => 'WARD',
        ])->assertCreated();
        $this->actingAs($nurse)->postJson('/api/v1/transport-requests', [
            'source_type' => 'admission',
            'source_id' => $admission->id,
            'priority' => 'ROUTINE',
            'pickup_zone' => 'WARD',
            'destination_zone' => 'MAIN_ENTRANCE',
        ])->assertCreated();

        $this->assertSame(2, TransportRequest::query()->where('status', 'REQUESTED')->count());
        $this->assertSame(0, IntegrationOutbox::query()->count());
    }

    public function test_vrds_outbound_delivery_retries_and_dead_letters_and_local_cancel_uses_the_outbox(): void
    {
        $nurse = $this->userWithRole('nurse');
        [$visit] = $this->sources();
        $transport = app(TransportRequestService::class)->create([
            'source_type' => 'er_visit',
            'source_id' => $visit->id,
            'pickup_zone' => 'ER',
            'destination_zone' => 'WARD',
        ], $nurse);
        $dispatch = IntegrationOutbox::query()->where('entity_type', 'vrds_dispatch_request')->firstOrFail();
        config()->set('integrations.mode', 'live');
        config()->set('integrations.max_attempts', 2);
        IntegrationPartner::query()->where('code', 'vrds')->update(['base_url' => null, 'outbound_token' => null]);

        $job = new DeliverIntegrationOutbox((int) $dispatch->id);
        $job->handle(app(IntegrationPayloadFactory::class));
        $this->assertSame('FAILED', $dispatch->fresh()->status);
        $this->assertNotNull($dispatch->fresh()->next_retry_at);
        $job->handle(app(IntegrationPayloadFactory::class));
        $this->assertSame('DEAD', $dispatch->fresh()->status);
        $this->assertSame('REQUESTED', $transport->fresh()->status);

        config()->set('integrations.mode', 'mock');
        IntegrationPartner::query()->where('code', 'vrds')->update(['active' => true]);
        $transport->update(['external_id' => 'VRDS-CANCEL-1']);
        app(TransportRequestService::class)->cancel($transport, $nurse, 'Transport no longer needed');
        $cancel = IntegrationOutbox::query()->where('entity_type', 'vrds_dispatch_cancel')->firstOrFail();
        $cancelPayload = app(IntegrationPayloadFactory::class)->make($cancel, 'vrds-cancel-send');
        $this->assertSame(['request_ref'], array_keys($cancelPayload));
        (new DeliverIntegrationOutbox((int) $cancel->id))->handle(app(IntegrationPayloadFactory::class));
        $this->assertSame('SENT', $cancel->fresh()->status);
        $this->assertSame('CANCELLED', $transport->fresh()->status);
    }

    private function sources(): array
    {
        $patient = Patient::factory()->create();
        $visit = ErVisit::query()->create([
            'visit_number' => 'ER-'.str()->uuid(),
            'patient_id' => $patient->id,
            'arrived_at' => now(),
            'chief_complaint' => 'Synthetic test case',
            'status' => 'ARRIVED',
        ]);
        $admission = Admission::query()->create([
            'admission_number' => 'ADM-'.str()->uuid(),
            'patient_id' => $patient->id,
            'status' => 'REQUESTED',
        ]);

        return [$visit, $admission];
    }

    private function userWithRole(string $name): User
    {
        $role = Role::query()->firstOrCreate(['name' => $name], ['label' => ucfirst($name)]);
        $user = User::factory()->create();
        $user->roles()->attach($role);

        return $user;
    }

    private function signedRequest(string $url, array $payload)
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->timestamp;

        return $this->call('POST', $url, [], [], [], [
            'HTTP_X_PARTNER_CODE' => 'vrds',
            'HTTP_X_INTEGRATION_KEY' => 'test-vrds-secret',
            'HTTP_X_WEBHOOK_TIMESTAMP' => $timestamp,
            'HTTP_X_WEBHOOK_SIGNATURE' => hash_hmac('sha256', $timestamp.'.'.$body, 'webhook-secret-vrds'),
            'HTTP_X_EVENT_ID' => $payload['event_id'],
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }
}
