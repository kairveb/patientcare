<?php

namespace Tests\Feature;

use App\Jobs\DeliverIntegrationOutbox;
use App\Models\CareRequest;
use App\Models\Encounter;
use App\Models\IntegrationClinicalDocument;
use App\Models\IntegrationEvent;
use App\Models\IntegrationOutbox;
use App\Models\IntegrationPartner;
use App\Models\IntegrationReferenceMap;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Prescription;
use App\Models\Role;
use App\Models\User;
use App\Services\IntegrationPayloadFactory;
use App\Services\MockPartnerClient;
use App\Services\PatientConsentService;
use App\Services\CareRequestService;
use App\Services\BillingAggregationService;
use App\Services\PrescriptionService;
use Database\Seeders\AdminRoleSeeder;
use Database\Seeders\CareRequestRbacSeeder;
use Database\Seeders\IntegrationPartnerSeeder;
use Database\Seeders\IntegrationRbacSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IntegrationRoundTwoTest extends TestCase
{
    private const PARTNERS = ['lis', 'ris', 'pms', 'emrs'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(AdminRoleSeeder::class);
        $this->seed(CareRequestRbacSeeder::class);
        $this->seed(IntegrationRbacSeeder::class);
        $this->seed(IntegrationPartnerSeeder::class);
        config()->set('integrations.mode', 'mock');

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

    public function test_new_partner_auth_abilities_and_human_identity_separation(): void
    {
        foreach (self::PARTNERS as $code) {
            $this->withHeaders($this->partnerHeaders($code))
                ->getJson('/api/v1/integrations/partner/status')
                ->assertOk()
                ->assertJsonPath('data.partner', $code);
        }

        $partner = IntegrationPartner::query()->where('code', 'lis')->firstOrFail();
        $partner->update(['allowed_abilities' => []]);
        $this->withHeaders($this->partnerHeaders('lis'))
            ->getJson('/api/v1/integrations/partner/status')
            ->assertForbidden();

        $partner->update(['allowed_abilities' => ['integration-status-read']]);
        $human = User::factory()->create();
        $this->withHeaders($this->partnerHeaders('lis') + [
            'Authorization' => 'Bearer '.$human->createToken('human-api-token')->plainTextToken,
        ])->getJson('/api/v1/integrations/partner/status')->assertUnauthorized();
    }

    public function test_lis_and_ris_signed_result_callbacks_are_idempotent_and_enforce_transitions(): void
    {
        foreach (['lis' => 'lab', 'ris' => 'imaging'] as $partner => $type) {
            $request = $this->careRequest($type, [
                'external_id' => $partner.'-order-001',
                'external_partner_code' => $partner,
                'partner_status' => 'IN_PROGRESS',
            ]);
            $payload = [
                'event_id' => $partner.'-result-event',
                'external_id' => $request->external_id,
                'status' => 'RESULTED',
                'result_ref' => $partner.'-result-opaque-ref',
            ];
            $path = "/api/v1/integrations/{$partner}/webhooks/results";

            $this->signedPost($partner, $path, $payload)->assertOk();
            $this->signedPost($partner, $path, $payload)->assertOk();
            $this->assertSame(1, IntegrationEvent::query()->where('event_id', $payload['event_id'])->count());
            $this->assertSame('RESULTED', $request->fresh()->partner_status);
            $this->assertSame($payload['result_ref'], $request->fresh()->result_reference);
            $this->signedPost($partner, $path, [
                ...$payload,
                'event_id' => $partner.'-invalid-transition',
                'status' => 'IN_PROGRESS',
            ])->assertStatus(409)->assertJsonPath('code', 'invalid_status_transition');
        }
    }

    public function test_partner_result_webhook_signature_and_result_reference_are_required(): void
    {
        $request = $this->careRequest('lab', [
            'external_id' => 'lis-order-unsigned-test',
            'external_partner_code' => 'lis',
            'partner_status' => 'REQUESTED',
        ]);
        $path = '/api/v1/integrations/lis/webhooks/results';
        $payload = ['event_id' => 'bad-signature', 'external_id' => $request->external_id, 'status' => 'ACCEPTED'];
        $this->signedPost('lis', $path, $payload, ['HTTP_X_WEBHOOK_SIGNATURE' => str_repeat('0', 64)])
            ->assertUnauthorized()
            ->assertJsonPath('code', 'webhook_signature_invalid');
        $this->signedPost('lis', $path, [
            'event_id' => 'missing-result',
            'external_id' => $request->external_id,
            'status' => 'RESULTED',
        ])->assertStatus(422)->assertJsonPath('code', 'result_reference_required');
        $this->signedPost('lis', $path, [
            'event_id' => 'url-result-reference',
            'external_id' => $request->external_id,
            'status' => 'RESULTED',
            'result_ref' => 'https://untrusted.example/document',
        ])->assertStatus(422)->assertJsonPath('code', 'invalid_result_reference');
    }

    public function test_pms_receipt_does_not_dispense_but_partial_or_full_callback_does(): void
    {
        $prescription = $this->prescription(['external_id' => 'pms-rx-001', 'partner_status' => null]);
        foreach (['SENT', 'ACCEPTED'] as $index => $status) {
            $this->signedPost('pms', '/api/v1/integrations/pms/webhooks/dispense-status', [
                'event_id' => 'pms-receipt-'.$index,
                'external_id' => $prescription->external_id,
                'status' => $status,
            ])->assertOk();
            $this->assertNull($prescription->fresh()->dispensed_at);
        }

        $this->signedPost('pms', '/api/v1/integrations/pms/webhooks/dispense-status', [
            'event_id' => 'pms-partial',
            'external_id' => $prescription->external_id,
            'status' => 'PARTIALLY_DISPENSED',
        ])->assertOk();
        $this->assertNotNull($prescription->fresh()->dispensed_at);
        $this->assertSame('PARTIALLY_DISPENSED', $prescription->fresh()->partner_status);
        $this->signedPost('pms', '/api/v1/integrations/pms/webhooks/dispense-status', [
            'event_id' => 'pms-cancel-after-partial',
            'external_id' => $prescription->external_id,
            'status' => 'CANCELLED',
        ])->assertStatus(409);
    }

    public function test_existing_in_house_dispense_action_keeps_partner_status_consistent(): void
    {
        $prescription = $this->prescription();

        $dispensed = app(BillingAggregationService::class)->dispense($prescription, $prescription->prescribedBy);

        $this->assertNotNull($dispensed->dispensed_at);
        $this->assertSame('DISPENSED', $dispensed->partner_status);
        $this->assertNotNull($dispensed->partner_status_updated_at);
    }

    public function test_emrs_rejects_unknown_refs_links_metadata_and_keeps_conflicts_local(): void
    {
        $partner = IntegrationPartner::query()->where('code', 'emrs')->firstOrFail();
        $firstEncounter = Encounter::factory()->create();
        $secondEncounter = Encounter::factory()->create();
        $firstReference = $this->encounterReference($partner, $firstEncounter, 'opaque-encounter-a');
        $secondReference = $this->encounterReference($partner, $secondEncounter, 'opaque-encounter-b');
        $path = '/api/v1/integrations/emrs/webhooks/clinical-document';

        $this->signedPost('emrs', $path, [
            'event_id' => 'emrs-unknown',
            'encounter_ref' => 'unknown-reference',
            'document_ref' => 'external-doc-unknown',
            'status' => 'AVAILABLE',
        ])->assertNotFound();

        $firstEncounter->notes()->create(['author_id' => $firstEncounter->provider->user_id, 'body' => 'Local note remains unchanged.']);
        $payload = [
            'event_id' => 'emrs-document-1',
            'encounter_ref' => $firstReference,
            'document_ref' => 'external-document-opaque-1',
            'status' => 'AVAILABLE',
        ];
        $this->signedPost('emrs', $path, $payload)->assertOk();
        $this->assertSame(1, IntegrationClinicalDocument::query()->count());
        $this->assertSame('Local note remains unchanged.', $firstEncounter->notes()->firstOrFail()->body);

        $this->signedPost('emrs', $path, [
            ...$payload,
            'event_id' => 'emrs-document-conflict',
            'encounter_ref' => $secondReference,
        ])->assertStatus(409)->assertJsonPath('code', 'document_link_conflict');
        $this->assertSame(1, IntegrationClinicalDocument::query()->count());
    }

    public function test_all_four_outboxes_are_consent_gated_retryable_and_dead_lettered(): void
    {
        Queue::fake();
        config()->set('integrations.mode', 'live');
        config()->set('integrations.max_attempts', 2);
        Http::fake(fn () => Http::response([], 503));

        $patient = Patient::factory()->create();
        $encounter = Encounter::factory()->create(['patient_id' => $patient->id]);
        $entities = [
            'lis' => ['care_request_lis', $this->careRequest('lab', ['patient_id' => $patient->id, 'encounter_id' => $encounter->id])],
            'ris' => ['care_request_ris', $this->careRequest('imaging', ['patient_id' => $patient->id, 'encounter_id' => $encounter->id])],
            'pms' => ['prescription', $this->prescription(['patient_id' => $patient->id, 'encounter_id' => $encounter->id])],
            'emrs' => ['emrs_encounter', $encounter],
        ];

        foreach ($entities as $partnerCode => [$type, $entity]) {
            $outbox = $this->outbox($partnerCode, $type, $entity->id, $partnerCode.'-outbox');
            (new DeliverIntegrationOutbox($outbox->id))->handle(app(IntegrationPayloadFactory::class));
            $this->assertSame('DEAD', $outbox->fresh()->status, "{$partnerCode} should dead-letter when disclosure consent is absent.");
        }

        $consents = app(PatientConsentService::class);
        $consents->record($patient, 'DATA_PRIVACY', true, null, 'self');
        foreach (self::PARTNERS as $partnerCode) {
            $consents->record($patient, strtoupper($partnerCode).'_SHARING', true, null, 'self');
        }
        foreach ($entities as $partnerCode => [$type, $entity]) {
            $outbox = $this->outbox($partnerCode, $type, $entity->id, $partnerCode.'-retry');
            (new DeliverIntegrationOutbox($outbox->id))->handle(app(IntegrationPayloadFactory::class));
            $this->assertSame('FAILED', $outbox->fresh()->status);
            $this->assertSame(1, $outbox->fresh()->attempts);
            (new DeliverIntegrationOutbox($outbox->id))->handle(app(IntegrationPayloadFactory::class));
            $this->assertSame('DEAD', $outbox->fresh()->status);
            $this->assertSame(2, $outbox->fresh()->attempts);
        }
    }

    public function test_outbound_lis_payload_uses_opaque_refs_and_minimal_fields(): void
    {
        $patient = Patient::factory()->create(['first_name' => 'TestOnlySensitiveName']);
        $request = $this->careRequest('lab', ['patient_id' => $patient->id]);
        $partner = IntegrationPartner::query()->where('code', 'lis')->firstOrFail();
        $outbox = $this->outbox('lis', 'care_request_lis', $request->id, 'lis-payload');
        $consents = app(PatientConsentService::class);
        $consents->record($patient, 'DATA_PRIVACY', true, null, 'self');
        $consents->record($patient, 'LIS_SHARING', true, null, 'self');

        $payload = app(IntegrationPayloadFactory::class)->make($outbox);

        $this->assertSame(['patient_ref', 'request_ref', 'tests', 'urgency'], array_keys($payload));
        $this->assertNotSame((string) $patient->id, $payload['patient_ref']);
        $this->assertStringNotContainsString('TestOnlySensitiveName', json_encode($payload, JSON_THROW_ON_ERROR));
        app(MockPartnerClient::class)->send($partner, '/lab-orders', $payload, $outbox->idempotency_key, 'test-request');
    }

    public function test_approved_lab_and_imaging_requests_and_issued_prescriptions_enqueue_outbox_rows(): void
    {
        $patient = Patient::factory()->create();
        $encounter = Encounter::factory()->create(['patient_id' => $patient->id]);
        $registration = User::factory()->create();
        $registration->roles()->attach(Role::query()->where('name', 'registration')->firstOrFail());
        $lab = $this->careRequest('lab', [
            'patient_id' => $patient->id,
            'encounter_id' => $encounter->id,
            'status' => 'REQUESTED',
        ]);
        $imaging = $this->careRequest('imaging', [
            'patient_id' => $patient->id,
            'encounter_id' => $encounter->id,
            'status' => 'REQUESTED',
        ]);

        app(CareRequestService::class)->decide($lab, $registration, 'APPROVED', null);
        app(CareRequestService::class)->decide($imaging, $registration, 'APPROVED', null);
        app(PrescriptionService::class)->createForEncounter($encounter, [
            'medication_name' => 'TEST-DRUG-CODE',
            'dosage' => 'once daily',
            'instructions' => 'Take after meals.',
        ], $encounter->provider->user);

        $this->assertDatabaseHas('integration_outbox', [
            'integration_partner_id' => IntegrationPartner::query()->where('code', 'lis')->value('id'),
            'entity_type' => 'care_request_lis',
            'entity_id' => $lab->id,
        ]);
        $this->assertDatabaseHas('integration_outbox', [
            'integration_partner_id' => IntegrationPartner::query()->where('code', 'ris')->value('id'),
            'entity_type' => 'care_request_ris',
            'entity_id' => $imaging->id,
        ]);
        $this->assertDatabaseHas('integration_outbox', [
            'integration_partner_id' => IntegrationPartner::query()->where('code', 'pms')->value('id'),
            'entity_type' => 'prescription',
        ]);
    }

    public function test_changed_care_request_and_encounter_pages_keep_the_existing_six_role_matrix(): void
    {
        $roles = [];
        foreach (['patient', 'doctor', 'nurse', 'registration', 'admin', 'super-admin'] as $name) {
            $role = Role::query()->where('name', $name)->firstOrCreate(['name' => $name], ['label' => ucfirst($name)]);
            $user = User::factory()->create();
            $user->roles()->attach($role);
            $roles[$name] = $user;
        }

        $patient = Patient::factory()->linkedTo($roles['patient'])->create();
        $provider = \App\Models\Provider::factory()->create(['user_id' => $roles['doctor']->id]);
        $permission = Permission::query()->firstOrCreate(['name' => 'view-encounters'], ['label' => 'View Encounters']);
        foreach (['doctor', 'nurse', 'super-admin'] as $roleName) {
            $roles[$roleName]->roles()->firstOrFail()->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $encounter = Encounter::factory()->create([
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
        ]);
        $request = $this->careRequest('lab', [
            'patient_id' => $patient->id,
            'encounter_id' => $encounter->id,
            'partner_status' => 'RESULTED',
            'result_reference' => 'opaque-lis-result',
        ]);
        $this->prescription([
            'patient_id' => $patient->id,
            'partner_status' => 'ACCEPTED',
        ]);
        $this->acceptRequiredPrivacyDocuments($patient, $roles['patient']);

        $careAllowed = ['patient', 'doctor', 'nurse', 'registration', 'super-admin'];
        $encounterAllowed = ['doctor', 'nurse', 'super-admin'];
        foreach ($roles as $name => $user) {
            $careResponse = $this->actingAs($user)->get(route('care-requests.show', $request));
            $careResponse->assertStatus(in_array($name, $careAllowed, true) ? 200 : 403);
            if (in_array($name, $careAllowed, true)) {
                $careResponse->assertSee('opaque-lis-result');
            }
            $this->actingAs($user)->get(route('encounters.show', $encounter))
                ->assertStatus(in_array($name, $encounterAllowed, true) ? 200 : 403);
            $approvalPage = $this->actingAs($user)->get(route('patients.portal.approval', $request));
            $this->assertSame(
                $name === 'patient' ? 200 : 403,
                $approvalPage->getStatusCode(),
                "{$name} approval page redirected to ".$approvalPage->headers->get('Location')
            );
            $dashboard = $this->actingAs($user)->get(route('patients.portal'));
            $expectedDashboardStatus = match ($name) {
                'patient' => 200,
                'super-admin' => 302,
                default => 403,
            };
            $this->assertSame(
                $expectedDashboardStatus,
                $dashboard->getStatusCode(),
                "{$name} dashboard redirected to ".$dashboard->headers->get('Location')
            );
            if ($name === 'patient') {
                $dashboard->assertSee('PMS: ACCEPTED');
            }
        }

        $otherPatientUser = User::factory()->create();
        $otherPatientUser->roles()->attach(Role::query()->where('name', 'patient')->firstOrFail());
        $otherPatient = Patient::factory()->linkedTo($otherPatientUser)->create();
        $this->acceptRequiredPrivacyDocuments($otherPatient, $otherPatientUser);
        $this->actingAs($otherPatientUser)->get(route('care-requests.show', $request))->assertForbidden();
    }

    public function test_all_six_human_roles_are_rejected_from_each_new_partner_webhook(): void
    {
        foreach (['patient', 'doctor', 'nurse', 'registration', 'admin', 'super-admin'] as $roleName) {
            $role = Role::query()->where('name', $roleName)->firstOrCreate(['name' => $roleName], ['label' => ucfirst($roleName)]);
            $user = User::factory()->create();
            $user->roles()->attach($role);

            foreach ([
                ['lis', '/api/v1/integrations/lis/webhooks/results', ['external_id' => 'missing-lis', 'status' => 'ACCEPTED']],
                ['ris', '/api/v1/integrations/ris/webhooks/results', ['external_id' => 'missing-ris', 'status' => 'ACCEPTED']],
                ['pms', '/api/v1/integrations/pms/webhooks/dispense-status', ['external_id' => 'missing-pms', 'status' => 'SENT']],
                ['emrs', '/api/v1/integrations/emrs/webhooks/clinical-document', ['encounter_ref' => 'missing', 'document_ref' => 'doc', 'status' => 'AVAILABLE']],
            ] as [$partner, $path, $fields]) {
                $this->actingAs($user)->signedPost($partner, $path, ['event_id' => $roleName.'-'.$partner] + $fields)
                    ->assertUnauthorized();
            }
        }
    }

    private function partnerHeaders(string $code): array
    {
        return ['X-Partner-Code' => $code, 'X-Integration-Key' => 'test-'.$code.'-secret'];
    }

    private function signedPost(string $partner, string $path, array $payload, array $overrides = [])
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

        return $this->call('POST', $path, [], [], [], $headers, $body);
    }

    private function careRequest(string $type, array $attributes = []): CareRequest
    {
        $encounter = isset($attributes['encounter_id'])
            ? Encounter::query()->findOrFail($attributes['encounter_id'])
            : Encounter::factory()->create();
        $user = User::factory()->create();

        return CareRequest::query()->create(array_merge([
            'patient_id' => $attributes['patient_id'] ?? $encounter->patient_id,
            'encounter_id' => $encounter->id,
            'requested_by' => $user->id,
            'type' => $type,
            'description' => 'CBC test; clinical rationale stays local.',
            'urgency' => 'routine',
            'status' => 'APPROVED',
        ], $attributes));
    }

    private function prescription(array $attributes = []): Prescription
    {
        $encounter = isset($attributes['encounter_id'])
            ? Encounter::query()->findOrFail($attributes['encounter_id'])
            : Encounter::factory()->create();

        return Prescription::query()->create(array_merge([
            'encounter_id' => $encounter->id,
            'telehealth_session_id' => null,
            'patient_id' => $attributes['patient_id'] ?? $encounter->patient_id,
            'medication_name' => 'TEST-DRUG-CODE',
            'dosage' => 'once daily',
            'quantity' => 5,
            'instructions' => 'Take after meals.',
            'prescribed_by' => $encounter->provider->user_id,
            'prescribed_at' => now(),
        ], $attributes));
    }

    private function outbox(string $partnerCode, string $type, int $entityId, string $key): IntegrationOutbox
    {
        return IntegrationOutbox::query()->create([
            'integration_partner_id' => IntegrationPartner::query()->where('code', $partnerCode)->value('id'),
            'entity_type' => $type,
            'entity_id' => $entityId,
            'payload_reference' => ['purpose' => $type, 'version' => $key],
            'status' => 'PENDING',
            'idempotency_key' => hash('sha256', $key),
        ]);
    }

    private function encounterReference(IntegrationPartner $partner, Encounter $encounter, string $reference): string
    {
        return IntegrationReferenceMap::query()->create([
            'integration_partner_id' => $partner->id,
            'resource_type' => 'encounter',
            'resource_id' => $encounter->id,
            'emr_reference' => $reference,
        ])->emr_reference;
    }
}
