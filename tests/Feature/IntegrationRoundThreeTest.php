<?php

namespace Tests\Feature;

use App\Jobs\DeliverIntegrationOutbox;
use App\Models\Appointment;
use App\Models\IntegrationDisclosure;
use App\Models\IntegrationEvent;
use App\Models\IntegrationExport;
use App\Models\IntegrationOutbox;
use App\Models\IntegrationPartner;
use App\Models\IntegrationReferenceMap;
use App\Models\Permission;
use App\Models\Provider;
use App\Models\ProviderUnavailability;
use App\Models\Role;
use App\Models\User;
use App\Services\IntegrationExportService;
use App\Services\IntegrationPayloadFactory;
use App\Services\IntegrationReferenceMapService;
use App\Services\AuditLogService;
use App\Services\UserAdministrationService;
use Database\Seeders\AdminRoleSeeder;
use Database\Seeders\IntegrationPartnerSeeder;
use Database\Seeders\IntegrationRbacSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IntegrationRoundThreeTest extends TestCase
{
    private const PARTNERS = ['hrms', 'hads', 'sas'];
    private const ROLES = ['patient', 'doctor', 'nurse', 'registration', 'admin', 'super-admin'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(AdminRoleSeeder::class);
        $this->seed(IntegrationRbacSeeder::class);
        $this->seed(IntegrationPartnerSeeder::class);
        config()->set('integrations.mode', 'mock');
        Queue::fake();

        foreach (self::PARTNERS as $code) {
            IntegrationPartner::query()->where('code', $code)->firstOrFail()->update([
                'active' => true,
                'api_key_hash' => Hash::make('test-'.$code.'-secret'),
                'webhook_signing_secret' => 'webhook-secret-'.$code,
                'base_url' => 'https://'.$code.'.example.test',
                'outbound_token' => 'test-outbound-'.$code,
            ]);
        }
    }

    public function test_round_three_partner_authentication_abilities_and_human_tokens_are_separate(): void
    {
        foreach (self::PARTNERS as $code) {
            $this->withHeaders($this->partnerHeaders($code))
                ->getJson('/api/v1/integrations/partner/status')
                ->assertOk()
                ->assertJsonPath('data.partner', $code);

            $this->withHeaders([
                'X-Partner-Code' => $code,
                'X-Integration-Key' => 'invalid-key',
            ])->getJson('/api/v1/integrations/partner/status')->assertUnauthorized();

            IntegrationPartner::query()->where('code', $code)->firstOrFail()
                ->update(['allowed_abilities' => ['unrelated-ability']]);
            $this->withHeaders($this->partnerHeaders($code))
                ->getJson('/api/v1/integrations/partner/status')
                ->assertForbidden();
            IntegrationPartner::query()->where('code', $code)->firstOrFail()
                ->update(['allowed_abilities' => ['integration-status-read']]);

            $human = User::factory()->create();
            $this->withHeaders($this->partnerHeaders($code) + [
                'Authorization' => 'Bearer '.$human->createToken('human-api-token')->plainTextToken,
            ])->getJson('/api/v1/integrations/partner/status')->assertUnauthorized();
            $this->withoutHeader('Authorization');
        }

        $staleBody = json_encode(['event_id' => 'stale-signature'], JSON_THROW_ON_ERROR);
        $staleTimestamp = (string) now()->subMinutes(10)->timestamp;
        $this->call('POST', '/api/v1/integrations/hads/webhooks/export-status', [], [], [], [
            'HTTP_X_PARTNER_CODE' => 'hads',
            'HTTP_X_INTEGRATION_KEY' => 'test-hads-secret',
            'HTTP_X_WEBHOOK_TIMESTAMP' => $staleTimestamp,
            'HTTP_X_WEBHOOK_SIGNATURE' => hash_hmac('sha256', $staleTimestamp.'.'.$staleBody, 'webhook-secret-hads'),
            'CONTENT_TYPE' => 'application/json',
        ], $staleBody)->assertUnauthorized()->assertJsonPath('code', 'webhook_signature_invalid');
    }

    public function test_hrms_availability_updates_schedules_reassigns_appointments_and_rejects_stale_versions(): void
    {
        $doctor = $this->userWithRole('doctor');
        $provider = Provider::factory()->create(['user_id' => $doctor->id]);
        $partner = IntegrationPartner::query()->where('code', 'hrms')->firstOrFail();
        $providerReference = app(IntegrationReferenceMapService::class)->for($partner, 'provider', (int) $provider->id);
        $startsAt = now()->addDays(5)->setTime(10, 0);
        $appointment = Appointment::factory()->create([
            'provider_id' => $provider->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes(30),
            'status' => Appointment::STATUS_CONFIRMED,
        ]);
        $payload = [
            'event_id' => 'hrms-availability-v1',
            'provider_ref' => $providerReference,
            'version' => 1,
            'schedule' => [['day' => 1, 'start' => '09:00', 'end' => '17:00']],
            'unavailable' => [[
                'starts_at' => $startsAt->toIso8601String(),
                'ends_at' => $startsAt->copy()->addHour()->toIso8601String(),
                'reason_code' => 'SICK_LEAVE',
            ]],
        ];
        $path = '/api/v1/integrations/hrms/providers/'.$providerReference.'/availability';

        $this->signedRequest('PUT', 'hrms', $path, $payload)->assertOk()
            ->assertJsonPath('data.status', 'APPLIED');
        $this->signedRequest('POST', 'hrms', $path, $payload)->assertOk();
        $this->assertSame(1, IntegrationEvent::query()->where('event_id', 'hrms-availability-v1')->count());
        $this->assertSame(1, ProviderUnavailability::query()->where('provider_id', $provider->id)->count());
        $this->assertDatabaseHas('provider_schedules', [
            'provider_id' => $provider->id,
            'day_of_week' => 1,
            'start_time' => '09:00',
            'end_time' => '17:00',
        ]);
        $this->assertDatabaseHas('appointment_reassignments', [
            'appointment_id' => $appointment->id,
            'status' => 'WAITLISTED',
        ]);
        $this->assertSame(Appointment::STATUS_CANCELLED, $appointment->fresh()->status);
        $this->assertDatabaseHas('integration_logs', [
            'integration' => 'hrms',
            'event' => 'PROVIDER_AVAILABILITY_APPLIED',
            'status' => 'SUCCESS',
        ]);

        $this->signedRequest('POST', 'hrms', $path, [
            ...$payload,
            'event_id' => 'hrms-availability-stale',
        ])->assertStatus(409)->assertJsonPath('code', 'stale_source_version');
        $this->assertSame(1, ProviderUnavailability::query()->where('provider_id', $provider->id)->count());
    }

    public function test_hads_export_is_aggregate_only_audited_neutralized_and_permission_gated(): void
    {
        $this->grantPermission('view-reports', ['nurse', 'registration', 'admin', 'super-admin']);
        $registration = $this->userWithRole('registration');
        Appointment::factory()->create([
            'starts_at' => now(),
            'ends_at' => now()->addMinutes(30),
            'status' => '=1+1',
        ]);

        $export = app(IntegrationExportService::class)->request(
            'appointments',
            today()->toDateString(),
            today()->toDateString(),
            'csv',
            $registration
        );
        $this->assertSame('QUEUED', $export->status);
        $this->assertSame(1, $export->row_count);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'HADS_EXPORT_REQUESTED',
            'resource_type' => 'integration_export',
        ]);
        $this->assertDatabaseHas('integration_disclosures', [
            'request_id' => $export->export_ref,
            'patient_id' => null,
            'purpose' => 'hads_aggregate_operational_analytics',
        ]);

        $outbox = IntegrationOutbox::query()
            ->where('entity_type', 'analytics_export')
            ->where('entity_id', $export->id)
            ->firstOrFail();
        $payload = app(IntegrationPayloadFactory::class)->make($outbox);
        $serialized = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertSame($export->export_ref, $payload['export_id']);
        $this->assertSame('base64', $payload['content_encoding']);
        $this->assertStringNotContainsString('patient_ref', $serialized);
        $this->assertStringNotContainsString('patient_id', $serialized);
        $this->assertStringNotContainsString('clinical note', strtolower($serialized));
        $this->assertStringNotContainsString('email', strtolower($serialized));
        $this->assertStringNotContainsString('reason', strtolower($serialized));
        $this->assertStringContainsString("'=1+1", base64_decode($payload['content'], true));
        (new DeliverIntegrationOutbox((int) $outbox->id))->handle(app(IntegrationPayloadFactory::class));
        $this->assertSame('SENT', $outbox->fresh()->status);
        $this->assertSame('QUEUED', $export->fresh()->status);

        foreach (['pdf', 'txt', 'xls'] as $format) {
            $formattedExport = app(IntegrationExportService::class)->request(
                'appointments',
                today()->toDateString(),
                today()->toDateString(),
                $format,
                $registration
            );
            $formattedOutbox = IntegrationOutbox::query()
                ->where('entity_type', 'analytics_export')
                ->where('entity_id', $formattedExport->id)
                ->firstOrFail();
            $formattedPayload = app(IntegrationPayloadFactory::class)->make($formattedOutbox);
            $content = base64_decode($formattedPayload['content'], true);
            $this->assertIsString($content);
            $this->assertStringContainsString(match ($format) {
                'pdf' => '%PDF-',
                'txt' => "\t",
                'xls' => 'urn:schemas-microsoft-com:office:spreadsheet',
            }, $content);
        }

        foreach (self::ROLES as $roleName) {
            $user = $roleName === 'registration' ? $registration : $this->userWithRole($roleName);
            $response = $this->actingAs($user)->get(route('reports.index'));
            $response->assertStatus(in_array($roleName, ['nurse', 'registration', 'admin', 'super-admin'], true) ? 200 : 403);
            if ($roleName === 'registration') {
                $response->assertSee('HADS aggregate export');
            }
            $this->actingAs($user)->get(route('admin.integrations.index'))
                ->assertStatus(in_array($roleName, ['admin', 'super-admin'], true) ? 200 : 403);
        }

        $patient = $this->userWithRole('patient');
        $this->actingAs($patient)->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('reports.hads-exports.store'), [
                'dataset' => 'appointments',
                'from' => today()->toDateString(),
                'to' => today()->toDateString(),
                'format' => 'csv',
            ])->assertForbidden();

        $this->actingAs($registration)->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('reports.hads-exports.store'), [
                'dataset' => 'patients',
                'from' => today()->toDateString(),
                'to' => today()->toDateString(),
                'format' => 'csv',
            ])->assertSessionHasErrors('dataset');

        foreach (self::ROLES as $roleName) {
            $user = $roleName === 'registration' ? $registration : $this->userWithRole($roleName);
            $response = $this->withSession(['auth.password_confirmed_at' => time()])
                ->actingAs($user)
                ->post(route('reports.hads-exports.store'), [
                    'dataset' => 'triage_levels',
                    'from' => today()->toDateString(),
                    'to' => today()->toDateString(),
                    'format' => 'txt',
                ]);
            $response->assertStatus(in_array($roleName, ['nurse', 'registration', 'admin', 'super-admin'], true) ? 302 : 403);
        }
    }

    public function test_hads_signed_export_status_is_idempotent_and_validates_transitions(): void
    {
        $export = app(IntegrationExportService::class)->request(
            'triage_levels',
            today()->toDateString(),
            today()->toDateString(),
            'csv'
        );
        $payload = [
            'event_id' => 'hads-processing-1',
            'export_id' => $export->export_ref,
            'status' => 'PROCESSING',
        ];
        $path = '/api/v1/integrations/hads/webhooks/export-status';

        $this->signedRequest('POST', 'hads', $path, $payload)->assertOk();
        $this->signedRequest('POST', 'hads', $path, $payload)->assertOk();
        $this->assertSame('PROCESSING', $export->fresh()->status);
        $this->assertSame(1, IntegrationEvent::query()->where('event_id', $payload['event_id'])->count());

        $this->signedRequest('POST', 'hads', $path, [
            ...$payload,
            'event_id' => 'hads-invalid-transition',
            'status' => 'QUEUED',
        ])->assertStatus(409)->assertJsonPath('code', 'invalid_status_transition');

        $export->update(['expires_at' => now()->subMinute()]);
        $this->assertSame(1, app(IntegrationExportService::class)->expireRetainedExports());
        $this->assertSame('EXPIRED', $export->fresh()->status);
        $this->assertNull($export->fresh()->payload_snapshot);
    }

    public function test_inactive_hads_partner_does_not_block_local_export_auditing(): void
    {
        IntegrationPartner::query()->where('code', 'hads')->update(['active' => false]);

        $export = app(IntegrationExportService::class)->request(
            'billing_totals',
            today()->toDateString(),
            today()->toDateString(),
            'csv'
        );

        $this->assertSame('FAILED', $export->status);
        $this->assertSame(1, IntegrationExport::query()->whereKey($export->id)->count());
        $this->assertDatabaseHas('integration_disclosures', [
            'request_id' => $export->export_ref,
            'purpose' => 'hads_aggregate_operational_analytics',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'HADS_EXPORT_REQUESTED',
            'resource_id' => $export->id,
        ]);
    }

    public function test_sas_suspension_revokes_tokens_and_only_sas_may_reactivate_its_suspension(): void
    {
        $user = $this->userWithRole('nurse');
        $partner = IntegrationPartner::query()->where('code', 'sas')->firstOrFail();
        $externalReference = app(IntegrationReferenceMapService::class)->for($partner, 'user', (int) $user->id);
        $user->createToken('test-token');
        $path = '/api/v1/integrations/sas/webhooks/account-status';

        $this->signedRequest('POST', 'sas', $path, [
            'event_id' => 'sas-suspend-1',
            'external_id' => $externalReference,
            'status' => 'SUSPENDED',
        ])->assertOk();
        $this->assertFalse($user->fresh()->is_active);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame('sas', $user->fresh()->account_status_source);

        $this->signedRequest('POST', 'sas', $path, [
            'event_id' => 'sas-reactivate-1',
            'external_id' => $externalReference,
            'status' => 'ACTIVE',
        ])->assertOk();
        $this->assertTrue($user->fresh()->is_active);

        $admin = $this->userWithRole('super-admin');
        $this->grantPermission('manage-users', ['super-admin']);
        app(UserAdministrationService::class)->setActive($user->fresh(), false, $admin);
        $this->signedRequest('POST', 'sas', $path, [
            'event_id' => 'sas-local-reactivate',
            'external_id' => $externalReference,
            'status' => 'ACTIVE',
        ])->assertStatus(409)->assertJsonPath('code', 'account_reactivation_conflict');
        $this->assertFalse($user->fresh()->is_active);
        $this->assertSame('local', $user->fresh()->account_status_source);

        $disabledUser = $this->userWithRole('nurse');
        $disabledReference = app(IntegrationReferenceMapService::class)->for($partner, 'user', (int) $disabledUser->id);
        $this->signedRequest('POST', 'sas', $path, [
            'event_id' => 'sas-disable-terminal',
            'external_id' => $disabledReference,
            'status' => 'DISABLED',
        ])->assertOk();
        $this->signedRequest('POST', 'sas', $path, [
            'event_id' => 'sas-enable-terminal',
            'external_id' => $disabledReference,
            'status' => 'ACTIVE',
        ])->assertStatus(409);
        $this->assertFalse($disabledUser->fresh()->is_active);
    }

    public function test_sas_role_mapping_requires_an_existing_user_and_an_explicit_nonprivileged_role(): void
    {
        $user = $this->userWithRole('nurse');
        $userCount = User::query()->count();
        $partner = IntegrationPartner::query()->where('code', 'sas')->firstOrFail();
        $externalReference = app(IntegrationReferenceMapService::class)->for($partner, 'user', (int) $user->id);
        $path = '/api/v1/integrations/sas/users/'.$externalReference;

        foreach (['admin', 'super-admin', 'patient', 'arbitrary-role'] as $index => $role) {
            $this->signedRequest('PUT', 'sas', $path, [
                'event_id' => 'sas-role-rejected-'.$index,
                'external_id' => $externalReference,
                'role' => $role,
            ])->assertStatus(422)->assertJsonPath('code', 'role_not_allowed');
        }
        $this->assertFalse($user->fresh()->hasAnyRole(['admin', 'super-admin']));
        $this->assertSame($userCount, User::query()->count());

        $this->signedRequest('PUT', 'sas', $path, [
            'event_id' => 'sas-role-doctor',
            'external_id' => $externalReference,
            'role' => 'doctor',
        ])->assertOk()->assertJsonPath('data.role', 'doctor');
        $this->assertTrue($user->fresh()->hasRole('doctor'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'SAS_ROLE_MAPPING_UPDATED']);
    }

    public function test_local_security_audit_events_are_forwarded_to_sas_without_user_details(): void
    {
        $actor = $this->userWithRole('registration');
        $entry = app(AuditLogService::class)->log('LOGIN_FAILURE', 'authentication', null, 'FAILURE', null, $actor->id);
        $outbox = IntegrationOutbox::query()
            ->where('entity_type', 'sas_audit_event')
            ->where('entity_id', $entry->id)
            ->firstOrFail();

        $payload = app(IntegrationPayloadFactory::class)->make($outbox);
        $this->assertSame(['event_type', 'actor_ref', 'timestamp', 'outcome'], array_keys($payload));
        $this->assertSame('LOGIN_FAILURE', $payload['event_type']);
        $this->assertSame('FAILURE', $payload['outcome']);
        $this->assertNotSame((string) $actor->id, $payload['actor_ref']);
        $this->assertStringNotContainsString($actor->email, json_encode($payload, JSON_THROW_ON_ERROR));

        (new DeliverIntegrationOutbox((int) $outbox->id))->handle(app(IntegrationPayloadFactory::class));
        $this->assertSame('SENT', $outbox->fresh()->status);
    }

    public function test_human_roles_are_rejected_from_all_round_three_partner_endpoints(): void
    {
        $partner = IntegrationPartner::query()->where('code', 'hrms')->firstOrFail();
        $provider = Provider::factory()->create();
        $providerReference = app(IntegrationReferenceMapService::class)->for($partner, 'provider', (int) $provider->id);
        $sas = IntegrationPartner::query()->where('code', 'sas')->firstOrFail();
        $user = User::factory()->create();
        $userReference = app(IntegrationReferenceMapService::class)->for($sas, 'user', (int) $user->id);

        foreach (self::ROLES as $roleName) {
            $human = $this->userWithRole($roleName);
            $requests = [
                ['PUT', 'hrms', '/api/v1/integrations/hrms/providers/'.$providerReference.'/availability', ['event_id' => 'human-hrms-'.$roleName]],
                ['POST', 'hads', '/api/v1/integrations/hads/webhooks/export-status', ['event_id' => 'human-hads-'.$roleName]],
                ['POST', 'sas', '/api/v1/integrations/sas/webhooks/account-status', ['event_id' => 'human-sas-status-'.$roleName]],
                ['PUT', 'sas', '/api/v1/integrations/sas/users/'.$userReference, ['event_id' => 'human-sas-role-'.$roleName]],
            ];
            foreach ($requests as [$method, $code, $path, $body]) {
                $this->actingAs($human)->signedRequest($method, $code, $path, $body)->assertUnauthorized();
            }
        }
    }

    public function test_new_partner_outboxes_retry_and_dead_letter_for_each_round_three_partner(): void
    {
        config()->set('integrations.max_attempts', 2);
        foreach (self::PARTNERS as $code) {
            $outbox = IntegrationOutbox::query()->create([
                'integration_partner_id' => IntegrationPartner::query()->where('code', $code)->value('id'),
                'entity_type' => 'unsupported_round_three_test',
                'entity_id' => 1,
                'payload_reference' => ['version' => 'test'],
                'status' => 'PENDING',
                'idempotency_key' => hash('sha256', $code.'-unsupported-test'),
            ]);

            (new DeliverIntegrationOutbox((int) $outbox->id))->handle(app(IntegrationPayloadFactory::class));
            $this->assertSame('FAILED', $outbox->fresh()->status, "{$code} delivery should be retryable.");
            (new DeliverIntegrationOutbox((int) $outbox->id))->handle(app(IntegrationPayloadFactory::class));
            $this->assertSame('DEAD', $outbox->fresh()->status, "{$code} delivery should eventually dead-letter.");
            $this->assertSame(2, $outbox->fresh()->attempts);
        }
    }

    private function userWithRole(string $name): User
    {
        $role = Role::query()->firstOrCreate(['name' => $name], ['label' => ucfirst($name)]);
        $user = User::factory()->create();
        $user->roles()->attach($role);

        return $user;
    }

    private function grantPermission(string $permissionName, array $roles): void
    {
        $permission = Permission::query()->firstOrCreate(
            ['name' => $permissionName],
            ['label' => ucwords(str_replace('-', ' ', $permissionName))]
        );
        foreach ($roles as $name) {
            Role::query()->where('name', $name)->firstOrFail()
                ->permissions()->syncWithoutDetaching([$permission->id]);
        }
    }

    private function partnerHeaders(string $code): array
    {
        return ['X-Partner-Code' => $code, 'X-Integration-Key' => 'test-'.$code.'-secret'];
    }

    private function signedRequest(string $method, string $partner, string $path, array $payload, array $overrides = [])
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->timestamp;
        $headers = array_merge([
            'HTTP_X_PARTNER_CODE' => $partner,
            'HTTP_X_INTEGRATION_KEY' => 'test-'.$partner.'-secret',
            'HTTP_X_WEBHOOK_TIMESTAMP' => $timestamp,
            'HTTP_X_WEBHOOK_SIGNATURE' => hash_hmac('sha256', $timestamp.'.'.$body, 'webhook-secret-'.$partner),
            'HTTP_X_EVENT_ID' => (string) ($payload['event_id'] ?? 'event-id'),
            'CONTENT_TYPE' => 'application/json',
        ], $overrides);

        return $this->call($method, $path, [], [], [], $headers, $body);
    }
}
