<?php

namespace Tests\Feature;

use App\Jobs\DeliverIntegrationOutbox;
use App\Models\Admission;
use App\Models\BillingClearanceRequest;
use App\Models\DischargeSummary;
use App\Models\EncounterBill;
use App\Models\IntegrationDisclosure;
use App\Models\IntegrationEvent;
use App\Models\IntegrationOutbox;
use App\Models\IntegrationPartner;
use App\Models\Patient;
use App\Models\Provider;
use App\Models\Role;
use App\Models\User;
use App\Services\IntegrationDisclosureService;
use App\Services\IntegrationPayloadFactory;
use App\Services\MockPartnerClient;
use App\Services\PatientConsentService;
use Database\Seeders\AdminRoleSeeder;
use Database\Seeders\IntegrationPartnerSeeder;
use Database\Seeders\IntegrationRbacSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class IntegrationRoundOneTest extends TestCase
{
    private const HUMAN_ROLES = ['patient', 'doctor', 'nurse', 'registration', 'admin', 'super-admin'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(AdminRoleSeeder::class);
        $this->seed(IntegrationRbacSeeder::class);
        $this->seed(IntegrationPartnerSeeder::class);

        config()->set('integrations.mode', 'mock');
        foreach (['bdms', 'hics'] as $code) {
            $partner = IntegrationPartner::query()->where('code', $code)->firstOrFail();
            $partner->update([
                'active' => true,
                'api_key_hash' => Hash::make('test-'.$code.'-secret'),
                'webhook_signing_secret' => 'webhook-secret-'.$code,
            ]);
        }
    }

    public function test_partner_seeder_renames_existing_rows_without_changing_configuration_or_credentials(): void
    {
        $partner = IntegrationPartner::query()->where('code', 'bdms')->firstOrFail();
        $partner->forceFill([
            'name' => 'Legacy Billing Partner',
            'active' => false,
            'mode' => 'live',
            'api_key_hash' => 'existing-api-key-hash',
            'webhook_signing_secret' => 'existing-webhook-secret',
            'allowed_abilities' => ['custom-ability'],
            'base_url' => 'https://existing.example.test',
            'outbound_token' => 'existing-outbound-token',
            'last_used_at' => now()->subDay(),
        ])->save();
        $before = $partner->fresh()->getAttributes();

        $this->seed(IntegrationPartnerSeeder::class);

        $partner = $partner->fresh();
        $this->assertSame('Billing and Discharge Management System (BDMS)', $partner->name);
        foreach ([
            'active',
            'mode',
            'api_key_hash',
            'webhook_signing_secret',
            'allowed_abilities',
            'base_url',
            'outbound_token',
            'last_used_at',
        ] as $attribute) {
            $this->assertSame($before[$attribute], $partner->getAttributes()[$attribute], $attribute);
        }
    }

    public function test_official_partner_names_are_displayed_in_admin_web_and_api_outputs(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('name', 'admin')->firstOrFail());
        $names = [
            'bdms' => 'Billing and Discharge Management System (BDMS)',
            'hics' => 'HMO and Insurance Claims System (HICS)',
            'emrs' => 'Electronic Medical Records System (EMRS)',
            'lis' => 'Laboratory Information System (LIS)',
            'ris' => 'Radiology and Imaging System (RIS)',
            'pms' => 'Pharmacy Management System (PMS)',
            'hads' => 'Healthcare Analytics and Dashboard System (HADS)',
            'sas' => 'Security and Administration System (SAS)',
            'hrms' => 'Human Resource Management System (HRMS)',
            'vrds' => 'Vehicle Reservation and Dispatch System (VRDS)',
        ];

        $webResponse = $this->actingAs($admin)->get('/admin/integrations')->assertOk();
        $apiResponse = $this->actingAs($admin)->getJson('/api/v1/integrations/admin')->assertOk();

        foreach ($names as $code => $name) {
            $this->assertSame($name, config("integrations.partners.{$code}.name"));
            $webResponse->assertSee($name);
            $apiResponse->assertJsonFragment(['code' => $code, 'name' => $name]);
        }
    }

    public function test_partner_auth_accepts_keys_rejects_bad_keys_missing_abilities_and_human_tokens(): void
    {
        $this->withHeaders($this->partnerHeaders('bdms'))
            ->getJson('/api/v1/integrations/partner/status')
            ->assertOk()
            ->assertJsonPath('data.partner', 'bdms');

        $this->withHeaders(['X-Partner-Code' => 'bdms', 'X-Integration-Key' => 'wrong'])
            ->getJson('/api/v1/integrations/partner/status')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'partner_auth_invalid');

        IntegrationPartner::where('code', 'bdms')->update(['allowed_abilities' => []]);
        $this->withHeaders($this->partnerHeaders('bdms'))
            ->getJson('/api/v1/integrations/partner/status')
            ->assertForbidden()
            ->assertJsonPath('code', 'partner_ability_forbidden');

        IntegrationPartner::where('code', 'bdms')->update(['allowed_abilities' => ['integration-status-read']]);
        $human = User::factory()->create();
        $token = $human->createToken('human-api-token')->plainTextToken;
        $this->withHeaders($this->partnerHeaders('bdms') + ['Authorization' => 'Bearer '.$token])
            ->getJson('/api/v1/integrations/partner/status')
            ->assertUnauthorized();
    }

    public function test_signed_webhooks_accept_valid_signatures_and_reject_bad_signatures_and_replays(): void
    {
        $payload = ['event_id' => 'sig-event-1', 'external_id' => 'missing', 'status' => 'UNPAID'];
        $this->sendWebhook('bdms', 'payment-status', $payload)
            ->assertNotFound()
            ->assertJsonStructure(['success', 'code', 'message', 'request_id']);

        $this->sendWebhook('bdms', 'payment-status', $payload, ['HTTP_X_WEBHOOK_SIGNATURE' => str_repeat('0', 64)])
            ->assertUnauthorized()
            ->assertJsonPath('code', 'webhook_signature_invalid');

        $this->sendWebhook('bdms', 'payment-status', $payload, [], now()->subMinutes(10)->timestamp)
            ->assertUnauthorized()
            ->assertJsonPath('code', 'webhook_signature_invalid');
    }

    public function test_duplicate_payment_events_are_idempotent_and_invalid_transitions_are_rejected(): void
    {
        $bill = $this->billingStatement('UNPAID', null, 'bdms-statement-1');
        $payload = [
            'event_id' => 'payment-event-1',
            'external_id' => 'bdms-statement-1',
            'status' => 'PAID',
            'payment_ref' => 'payment-001',
        ];

        $this->sendWebhook('bdms', 'payment-status', $payload)->assertOk();
        $this->sendWebhook('bdms', 'payment-status', $payload)->assertOk();
        $this->assertSame(1, IntegrationEvent::where('event_id', 'payment-event-1')->count());
        $this->assertSame('PAID', $bill->fresh()->status);
        $this->assertSame('payment-001', $bill->fresh()->payment_reference);

        $this->sendWebhook('bdms', 'payment-status', [
            'event_id' => 'payment-event-2',
            'external_id' => 'bdms-statement-1',
            'status' => 'UNPAID',
        ])->assertStatus(409)->assertJsonPath('code', 'invalid_status_transition');
        $this->assertSame('PAID', $bill->fresh()->integration_status);
    }

    public function test_loa_webhook_updates_admission_and_rejects_terminal_transitions(): void
    {
        $admission = $this->admission(['integration_external_id' => 'hics-loa-1', 'loa_status' => 'PENDING']);
        $this->sendWebhook('hics', 'loa-status', [
            'event_id' => 'loa-event-1',
            'external_id' => 'hics-loa-1',
            'status' => 'APPROVED',
            'approved_amount' => 750.25,
            'payer_reference' => 'payer-1',
        ])->assertOk();
        $this->assertSame('APPROVED', $admission->fresh()->loa_status);
        $this->assertSame('750.25', $admission->fresh()->loa_approved_amount);

        $this->sendWebhook('hics', 'loa-status', [
            'event_id' => 'loa-event-2',
            'external_id' => 'hics-loa-1',
            'status' => 'DENIED',
        ])->assertStatus(409)->assertJsonPath('code', 'invalid_status_transition');
    }

    public function test_outbound_disclosure_requires_current_patient_and_partner_consent_and_uses_opaque_refs(): void
    {
        $patient = Patient::factory()->create(['first_name' => 'SensitiveName']);
        $partner = IntegrationPartner::where('code', 'bdms')->firstOrFail();
        $disclosures = app(IntegrationDisclosureService::class);

        try {
            $disclosures->record($partner, $patient, 'billing_statement', ['patient_ref']);
            $this->fail('Outbound disclosure should require current privacy and partner consent.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('integration_disclosures', 0);
        }

        $consents = app(PatientConsentService::class);
        $consents->record($patient, 'DATA_PRIVACY', true, null, 'self');
        $consents->record($patient, 'BDMS_SHARING', true, null, 'self');
        $bill = $this->billingStatement('UNPAID', $patient);
        $outbox = IntegrationOutbox::create([
            'integration_partner_id' => $partner->id,
            'entity_type' => 'billing_statement',
            'entity_id' => $bill->id,
            'payload_reference' => ['purpose' => 'billing_statement', 'version' => 'test'],
            'status' => 'PENDING',
            'attempts' => 0,
            'idempotency_key' => 'outbound-test-key',
        ]);
        $payload = app(IntegrationPayloadFactory::class)->make($outbox);

        $this->assertArrayNotHasKey('name', $payload);
        $this->assertStringNotContainsString('SensitiveName', json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertNotSame((string) $patient->id, $payload['patient_ref']);
        $this->assertDatabaseHas('integration_disclosures', [
            'integration_partner_id' => $partner->id,
            'patient_id' => $patient->id,
        ]);
        app(MockPartnerClient::class)->send($partner, '/billing-statements', $payload, $outbox->idempotency_key, 'test-request');
    }

    public function test_outbox_retries_with_backoff_and_dead_letters_after_limit(): void
    {
        Queue::fake();
        config()->set('integrations.mode', 'live');
        config()->set('integrations.max_attempts', 2);
        $patient = Patient::factory()->create();
        $consents = app(PatientConsentService::class);
        $consents->record($patient, 'DATA_PRIVACY', true, null, 'self');
        $consents->record($patient, 'BDMS_SHARING', true, null, 'self');
        $bill = $this->billingStatement('UNPAID', $patient);
        $partner = IntegrationPartner::where('code', 'bdms')->firstOrFail();
        $partner->update(['base_url' => null, 'outbound_token' => null]);
        $outbox = IntegrationOutbox::create([
            'integration_partner_id' => $partner->id,
            'entity_type' => 'billing_statement',
            'entity_id' => $bill->id,
            'payload_reference' => ['purpose' => 'billing_statement', 'version' => 'retry-test'],
            'status' => 'PENDING',
            'idempotency_key' => 'retry-test-key',
        ]);

        $job = new DeliverIntegrationOutbox($outbox->id);
        $job->handle(app(IntegrationPayloadFactory::class));
        $this->assertSame('FAILED', $outbox->fresh()->status);
        $this->assertSame(1, $outbox->fresh()->attempts);
        $this->assertNotNull($outbox->fresh()->next_retry_at);

        $job->handle(app(IntegrationPayloadFactory::class));
        $this->assertSame('DEAD', $outbox->fresh()->status);
        $this->assertSame(2, $outbox->fresh()->attempts);
    }

    public function test_all_six_human_roles_obey_admin_view_retry_and_clearance_authorization(): void
    {
        $actors = $this->usersByRole();

        foreach (self::HUMAN_ROLES as $role) {
            $user = $actors[$role];
            $outbox = $this->pendingOutbox();
            $this->actingAs($user)->get('/admin/integrations')
                ->assertStatus(in_array($role, ['admin', 'super-admin'], true) ? 200 : 403);
            $this->actingAs($user)->getJson('/api/v1/integrations/admin')
                ->assertStatus(in_array($role, ['admin', 'super-admin'], true) ? 200 : 403);
            $this->actingAs($user)->postJson("/api/v1/integrations/admin/outbox/{$outbox->id}/retry")
                ->assertStatus($role === 'super-admin' ? 200 : 403);
            $this->actingAs($user)->getJson('/api/v1/integrations/partner/status')
                ->assertUnauthorized();
        }

    }

    public function test_all_six_human_roles_are_denied_signed_partner_callbacks(): void
    {
        $actors = $this->usersByRole();
        $payload = ['event_id' => 'role-check', 'external_id' => 'not-found', 'status' => 'UNPAID'];
        foreach (self::HUMAN_ROLES as $role) {
            $this->actingAs($actors[$role]);
            $this->sendWebhook('bdms', 'payment-status', $payload)->assertUnauthorized();
            $this->sendWebhook('hics', 'loa-status', $payload)->assertUnauthorized();
        }
    }

    public function test_clearance_decision_routes_preserve_super_admin_only_access_for_all_roles(): void
    {
        $actors = $this->usersByRole();
        foreach (self::HUMAN_ROLES as $role) {
            $clearance = $this->clearance($actors['super-admin']);
            $this->actingAs($actors[$role])
                ->postJson("/api/v1/admissions/{$clearance->admission_id}/billing-clearance/decision", ['decision' => 'CLEARED'])
                ->assertStatus($role === 'super-admin' ? 200 : 403);
        }

        foreach (self::HUMAN_ROLES as $role) {
            $clearance = $this->clearance($actors['super-admin']);
            $this->actingAs($actors[$role])
                ->post(route('admissions.billing-clearance.decision', $clearance->admission_id), ['decision' => 'CLEARED'])
                ->assertStatus($role === 'super-admin' ? 302 : 403);
        }
    }

    private function usersByRole(): array
    {
        $users = [];
        foreach (self::HUMAN_ROLES as $roleName) {
            $user = User::factory()->create();
            $user->roles()->attach(Role::where('name', $roleName)->firstOrFail());
            $users[$roleName] = $user;
        }

        return $users;
    }

    private function partnerHeaders(string $code): array
    {
        return ['X-Partner-Code' => $code, 'X-Integration-Key' => 'test-'.$code.'-secret'];
    }

    private function sendWebhook(
        string $partner,
        string $event,
        array $payload,
        array $overrides = [],
        ?int $timestamp = null
    ) {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $time = (string) ($timestamp ?? now()->timestamp);
        $secret = 'webhook-secret-'.$partner;
        $headers = array_merge([
            'HTTP_X_PARTNER_CODE' => $partner,
            'HTTP_X_INTEGRATION_KEY' => 'test-'.$partner.'-secret',
            'HTTP_X_WEBHOOK_TIMESTAMP' => $time,
            'HTTP_X_WEBHOOK_SIGNATURE' => hash_hmac('sha256', $time.'.'.$body, $secret),
            'HTTP_X_EVENT_ID' => (string) ($payload['event_id'] ?? 'event-id'),
            'CONTENT_TYPE' => 'application/json',
        ], $overrides);

        return $this->call(
            'POST',
            "/api/v1/integrations/{$partner}/webhooks/{$event}",
            [],
            [],
            [],
            $headers,
            $body
        );
    }

    private function billingStatement(string $integrationStatus, ?Patient $patient = null, ?string $externalId = null): EncounterBill
    {
        $patient ??= Patient::factory()->create();

        return EncounterBill::create([
            'statement_number' => 'BILL-'.fake()->unique()->numerify('########'),
            'patient_id' => $patient->id,
            'status' => 'UNPAID',
            'currency' => 'PHP',
            'subtotal' => '100.00',
            'total' => '100.00',
            'source_fingerprint' => hash('sha256', fake()->uuid()),
            'generated_by' => User::factory()->create()->id,
            'generated_at' => now(),
            'integration_external_id' => $externalId ?? 'bdms-statement-'.fake()->unique()->numerify('########'),
            'integration_status' => $integrationStatus,
        ]);
    }

    private function admission(array $attributes = []): Admission
    {
        return Admission::create(array_merge([
            'admission_number' => 'ADM-'.fake()->unique()->numerify('########'),
            'patient_id' => Patient::factory()->create()->id,
            'status' => 'ADMITTED',
            'reason' => 'Test admission',
        ], $attributes));
    }

    private function clearance(User $actor): BillingClearanceRequest
    {
        $patient = Patient::factory()->create();
        $admission = $this->admission(['patient_id' => $patient->id]);
        $provider = Provider::create([
            'user_id' => User::factory()->create()->id,
            'display_name' => 'Test Provider',
        ]);
        $summary = DischargeSummary::create([
            'admission_id' => $admission->id,
            'attending_provider_id' => $provider->id,
            'authored_by' => $actor->id,
            'status' => 'APPROVED',
            'final_diagnosis' => 'Test diagnosis',
            'clinical_course' => 'Test course',
            'discharge_instructions' => 'Test instructions',
        ]);

        return BillingClearanceRequest::create([
            'admission_id' => $admission->id,
            'discharge_summary_id' => $summary->id,
            'requested_by' => $actor->id,
            'status' => 'REQUESTED',
            'requested_at' => now(),
        ]);
    }

    private function pendingOutbox(): IntegrationOutbox
    {
        $partner = IntegrationPartner::where('code', 'bdms')->firstOrFail();
        return IntegrationOutbox::create([
            'integration_partner_id' => $partner->id,
            'entity_type' => 'billing_statement',
            'entity_id' => $this->billingStatement('RECEIVED')->id,
            'payload_reference' => ['purpose' => 'billing_statement', 'version' => 'role-test'],
            'status' => 'FAILED',
            'attempts' => 1,
            'idempotency_key' => 'role-test-'.fake()->uuid(),
        ]);
    }
}
