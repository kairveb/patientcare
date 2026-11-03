<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\ErVisit;
use App\Models\Patient;
use App\Models\PreArrivalProfile;
use App\Models\Provider;
use App\Models\Role;
use App\Models\User;
use App\Services\PatientMergeService;
use App\Services\PatientQrCodeService;
use App\Services\PatientService;
use Database\Seeders\HimsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PatientReferenceCodeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['security.otp_enabled' => false]);
        $this->seed(HimsSeeder::class);
    }

    public function test_patient_service_generates_unique_random_prc_for_registration(): void
    {
        $patients = app(PatientService::class);
        $first = $patients->register($this->registrationData('One'));
        $second = $patients->register($this->registrationData('Two'));

        $this->assertMatchesRegularExpression('/^PRC-[0-9]{16}$/', $first->reference_code);
        $this->assertMatchesRegularExpression('/^PRC-[0-9]{16}$/', $second->reference_code);
        $this->assertNotSame($first->reference_code, $second->reference_code);
        $this->assertNotSame($first->mrn, $first->reference_code);
    }

    public function test_web_and_api_registration_and_unknown_er_paths_generate_a_prc(): void
    {
        $registration = $this->user('registration@coor.test');
        $this->actingAs($registration)
            ->post(route('patients.store'), array_replace($this->registrationData('WalkIn'), [
                'address_region' => 'National Capital Region (NCR)',
                'address_region_psgc_code' => '1300000000',
                'address_city' => 'Quezon City',
                'address_city_psgc_code' => '1381300000',
                'address_barangay' => 'Bahay Toro',
                'address_barangay_psgc_code' => '1381300012',
                'data_privacy_consent' => '1',
            ]))
            ->assertRedirect();
        $walkIn = Patient::where('first_name', 'WalkIn')->firstOrFail();
        $this->assertSame('1381300000', $walkIn->primaryAddress()->city_psgc_code);
        $this->assertSame('1381300012', $walkIn->primaryAddress()->barangay_psgc_code);

        $apiResponse = $this->actingAs($registration)->postJson('/api/v1/patients', [
            ...array_merge($this->registrationData('ApiWalkIn'), [
                'last_name' => 'UniqueApiReference',
                'date_of_birth' => '1985-04-11',
                'address' => [
                    'region' => 'National Capital Region (NCR)',
                    'region_psgc_code' => '1300000000',
                    'city' => 'Quezon City',
                    'city_psgc_code' => '1381300000',
                    'barangay' => 'Bahay Toro',
                    'barangay_psgc_code' => '1381300012',
                ],
            ]),
            'data_privacy_consent' => true,
        ])->assertCreated();
        $this->assertMatchesRegularExpression('/^PRC-[0-9]{16}$/', $apiResponse->json('data.reference_code'));

        $this->actingAs($registration)->post(route('emergency.unknown-arrivals.store'), [
            'chief_complaint' => 'Unable to identify patient',
        ])->assertRedirect();
        $unknownEr = Patient::where('is_temporary_identity', true)->latest('id')->firstOrFail();

        $this->assertMatchesRegularExpression('/^PRC-[0-9]{16}$/', $walkIn->reference_code);
        $this->assertMatchesRegularExpression('/^PRC-[0-9]{16}$/', $unknownEr->reference_code);
        $this->assertTrue($unknownEr->is_temporary_identity);

        $this->actingAs($registration)->postJson('/api/v1/emergency/visits/unknown', [
            'chief_complaint' => 'Unknown patient via API',
        ])->assertCreated();
        $apiUnknownEr = Patient::where('is_temporary_identity', true)->latest('id')->firstOrFail();
        $this->assertMatchesRegularExpression('/^PRC-[0-9]{16}$/', $apiUnknownEr->reference_code);
    }

    public function test_web_registration_rejects_a_city_from_another_region(): void
    {
        $registration = $this->user('registration@coor.test');
        $payload = array_replace($this->registrationData('InvalidAddress'), [
            'address_region' => 'National Capital Region (NCR)',
            'address_region_psgc_code' => '1300000000',
            'address_city' => 'City of Baguio',
            'address_city_psgc_code' => '1430300000',
            'address_barangay' => 'Apugan-Loakan',
            'address_barangay_psgc_code' => '1430300001',
            'data_privacy_consent' => '1',
        ]);

        $this->actingAs($registration)
            ->post(route('patients.store'), $payload)
            ->assertSessionHasErrors('city');

        $this->assertDatabaseMissing('patients', ['first_name' => 'InvalidAddress']);
    }

    public function test_patient_account_and_portal_profile_fallback_use_central_prc_generation(): void
    {
        $this->post('/register', [
            'name' => 'Patient Account',
            'email' => 'prc-account@example.test',
            'password' => 'Password123!Secure',
            'password_confirmation' => 'Password123!Secure',
            'privacy_notice_accepted' => '1',
            'terms_accepted' => '1',
        ])->assertRedirect();

        $registeredPatient = Patient::whereHas('user', fn ($query) => $query->where('email', 'prc-account@example.test'))
            ->firstOrFail();
        $this->assertMatchesRegularExpression('/^PRC-[0-9]{16}$/', $registeredPatient->reference_code);

        $portalUser = User::factory()->create();
        $portalUser->roles()->attach(Role::where('name', 'patient')->value('id'));
        $this->actingAs($portalUser)->get(route('patients.profile'))->assertOk();

        $portalPatient = Patient::where('user_id', $portalUser->id)->firstOrFail();
        $this->assertMatchesRegularExpression('/^PRC-[0-9]{16}$/', $portalPatient->reference_code);
    }

    public function test_backfill_command_assigns_codes_once_and_is_idempotent(): void
    {
        $patient = Patient::factory()->create(['reference_code' => null]);

        $this->artisan('patients:backfill-reference-codes')->assertSuccessful();
        $firstCode = $patient->fresh()->reference_code;
        $this->assertMatchesRegularExpression('/^PRC-[0-9]{16}$/', $firstCode);

        $this->artisan('patients:backfill-reference-codes')->assertSuccessful();
        $this->assertSame($firstCode, $patient->fresh()->reference_code);
    }

    public function test_qr_code_payload_is_only_the_patient_reference_code(): void
    {
        $patient = app(PatientService::class)->register($this->registrationData('QrOnly'));
        $qrCodes = app(PatientQrCodeService::class);

        $this->assertSame($patient->reference_code, $qrCodes->qrCode($patient)->getData());
        $this->assertSame(
            'otpauth://totp/HIMS%20(test%40example.test)?secret=ABC123',
            $qrCodes->qrCodeForPayload('otpauth://totp/HIMS%20(test%40example.test)?secret=ABC123')->getData()
        );

        $svgDataUri = $qrCodes->dataUri($patient);
        $svg = base64_decode(substr($svgDataUri, strlen('data:image/svg+xml;base64,')), true);
        $this->assertIsString($svg);
        $this->assertStringNotContainsString($patient->full_name, $svg);
        $this->assertStringNotContainsString($patient->mrn, $svg);
        $this->assertStringNotContainsString($patient->date_of_birth->toDateString(), $svg);
    }

    public function test_prc_lookup_normalizes_case_and_missing_dash_and_audits_without_phi(): void
    {
        $patient = app(PatientService::class)->register($this->registrationData('Lookup'));
        $registration = $this->user('registration@coor.test');

        $this->actingAs($registration)
            ->from(route('patients.index'))
            ->post(route('patients.find-by-prc'), [
                'reference_code' => strtolower(str_replace('-', '', $patient->reference_code)),
            ])
            ->assertRedirect(route('patients.show', $patient));

        $log = AuditLog::where('action', 'LOOKUP_PATIENT_REFERENCE_CODE')->latest('id')->firstOrFail();
        $this->assertSame($registration->id, $log->user_id);
        $this->assertNull($log->resource_id);
        $this->assertSame(['result' => 'found', 'code_type' => 'PRC'], $log->metadata);
        $this->assertStringNotContainsString($patient->reference_code, json_encode($log->toArray()));
        $this->assertStringNotContainsString($patient->mrn, json_encode($log->toArray()));
        $this->assertStringNotContainsString($patient->full_name, json_encode($log->toArray()));
    }

    public function test_unknown_and_malformed_codes_return_the_same_generic_message(): void
    {
        $registration = $this->user('registration@coor.test');
        $messages = [];

        foreach (['PRC-9999999999999999', 'not-a-code'] as $code) {
            $response = $this->actingAs($registration)
                ->postJson('/api/v1/patients/lookup/reference-code', ['reference_code' => $code])
                ->assertNotFound();
            $messages[] = $response->json('message');
        }

        $this->assertSame(
            ['No patient record found for this code.', 'No patient record found for this code.'],
            $messages
        );
        $this->assertSame(2, AuditLog::where('action', 'LOOKUP_PATIENT_REFERENCE_CODE')->where('result', 'FAILURE')->count());
    }

    public function test_staff_api_resolves_legacy_pac_input_and_audits_only_generic_metadata(): void
    {
        $patient = app(PatientService::class)->register($this->registrationData('LegacyPac'));
        $profile = $patient->preArrivalProfiles()->create([
            'token' => (string) Str::uuid(),
            'reference_code' => 'PAC-2468',
            'status' => 'pending',
            'first_name' => 'PreRegistered',
            'last_name' => 'Patient',
        ]);
        $registration = $this->user('registration@coor.test');

        $this->actingAs($registration)
            ->postJson('/api/v1/patients/lookup/reference-code', ['reference_code' => 'pac2468'])
            ->assertOk()
            ->assertJsonPath('data.id', $patient->id)
            ->assertJsonPath('data.reference_code', $patient->reference_code);

        $log = AuditLog::where('action', 'LOOKUP_PATIENT_REFERENCE_CODE')->latest('id')->firstOrFail();
        $this->assertSame(['result' => 'found', 'code_type' => 'PAC'], $log->metadata);
        $this->assertStringNotContainsString($profile->reference_code, json_encode($log->toArray()));
        $this->assertStringNotContainsString($patient->full_name, json_encode($log->toArray()));
    }

    public function test_staff_api_forbids_roles_without_patient_lookup_access(): void
    {
        $nurse = $this->user('nurse@coor.test');

        $this->actingAs($nurse)
            ->getJson('/patients/lookup?q=PRC-0000000000000000')
            ->assertForbidden();
    }

    public function test_api_lookup_and_own_patient_id_endpoint_return_prc_qr_without_cross_patient_lookup(): void
    {
        $patient = app(PatientService::class)->register($this->registrationData('ApiLookup'));
        $registration = $this->user('registration@coor.test');

        $this->actingAs($registration)
            ->postJson('/api/v1/patients/lookup/reference-code', [
                'reference_code' => strtolower(str_replace('-', '', $patient->reference_code)),
            ])
            ->assertOk()
            ->assertJsonPath('data.reference_code', $patient->reference_code)
            ->assertJsonPath('data.reference_code_qr', fn ($value) => str_starts_with($value, 'data:image/svg+xml;base64,'));

        $patientUser = $this->user('patient@coor.test');
        $ownPatient = $patientUser->patient()->firstOrFail();
        $this->acceptRequiredPrivacyDocuments($ownPatient, $patientUser);
        $ownPatient->update(['reference_code' => app(PatientService::class)->generateUniqueReferenceCode()]);

        $this->actingAs($patientUser)
            ->getJson('/api/v1/me/patient-id')
            ->assertOk()
            ->assertJsonPath('data.reference_code', $ownPatient->fresh()->reference_code);

        $this->actingAs($patientUser)
            ->postJson('/api/v1/patients/lookup/reference-code', ['reference_code' => $patient->reference_code])
            ->assertForbidden();

        $this->actingAs($patientUser)
            ->get(route('patients.portal.patient-id'))
            ->assertOk()
            ->assertSee('My Patient ID')
            ->assertSee($ownPatient->fresh()->reference_code);

        $this->actingAs($registration)
            ->get(route('patients.show', $patient))
            ->assertOk()
            ->assertSee($patient->reference_code)
            ->assertSee('Patient Reference Code');
    }

    public function test_prc_check_in_and_er_arrival_use_the_same_lookup(): void
    {
        $patient = app(PatientService::class)->register($this->registrationData('Arrival'));
        $provider = Provider::firstOrFail();
        $appointment = Appointment::create([
            'appointment_number' => 'APT-PRC-'.uniqid(),
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
            'starts_at' => now()->addDay()->setTime(9, 0),
            'ends_at' => now()->addDay()->setTime(9, 30),
            'status' => Appointment::STATUS_CONFIRMED,
        ]);
        $registration = $this->user('registration@coor.test');

        $this->actingAs($registration)
            ->post(route('appointments.check-in', $appointment), [
                'reference_code' => strtolower(str_replace('-', '', $patient->reference_code)),
            ])
            ->assertRedirect();
        $this->assertSame(Appointment::STATUS_CHECKED_IN, $appointment->fresh()->status);

        $this->actingAs($registration)
            ->post(route('emergency.store'), [
                'reference_code' => $patient->reference_code,
                'chief_complaint' => 'PRC ER arrival',
            ])
            ->assertRedirect();
        $this->assertDatabaseHas('er_visits', [
            'patient_id' => $patient->id,
            'chief_complaint' => 'PRC ER arrival',
        ]);

        $apiPatient = app(PatientService::class)->register($this->registrationData('ApiArrival'));
        $apiAppointment = Appointment::create([
            'appointment_number' => 'APT-PRC-API-'.uniqid(),
            'patient_id' => $apiPatient->id,
            'provider_id' => $provider->id,
            'starts_at' => now()->addDays(2)->setTime(9, 0),
            'ends_at' => now()->addDays(2)->setTime(9, 30),
            'status' => Appointment::STATUS_CONFIRMED,
        ]);
        $this->actingAs($registration)
            ->postJson("/api/v1/appointments/{$apiAppointment->id}/check-in", [
                'reference_code' => strtolower(str_replace('-', '', $apiPatient->reference_code)),
            ])
            ->assertOk();
        $this->assertSame(Appointment::STATUS_CHECKED_IN, $apiAppointment->fresh()->status);

        $this->actingAs($registration)->postJson('/api/v1/emergency/visits', [
            'reference_code' => $apiPatient->reference_code,
            'chief_complaint' => 'PRC API ER arrival',
        ])->assertCreated();
        $this->assertDatabaseHas('er_visits', [
            'patient_id' => $apiPatient->id,
            'chief_complaint' => 'PRC API ER arrival',
        ]);
    }

    public function test_doctor_and_nurse_prc_lookup_respects_existing_patient_visibility_scope(): void
    {
        $patient = app(PatientService::class)->register($this->registrationData('Scoped'));
        $doctor = $this->user('doctor@coor.test');
        $nurse = $this->user('nurse@coor.test');

        $this->actingAs($doctor)->postJson('/api/v1/patients/lookup/reference-code', [
            'reference_code' => $patient->reference_code,
        ])->assertNotFound();
        $this->actingAs($nurse)->postJson('/api/v1/patients/lookup/reference-code', [
            'reference_code' => $patient->reference_code,
        ])->assertNotFound();

        ErVisit::create([
            'visit_number' => 'ER-PRC-'.uniqid(),
            'patient_id' => $patient->id,
            'arrived_at' => now(),
            'chief_complaint' => 'Visibility scope',
            'status' => ErVisit::STATUS_ARRIVED,
            'created_by' => $nurse->id,
        ]);
        $this->actingAs($nurse)->postJson('/api/v1/patients/lookup/reference-code', [
            'reference_code' => $patient->reference_code,
        ])->assertOk();

        Appointment::create([
            'appointment_number' => 'APT-PRC-VIS-'.uniqid(),
            'patient_id' => $patient->id,
            'provider_id' => $doctor->provider->id,
            'starts_at' => now()->addDay()->setTime(9, 0),
            'ends_at' => now()->addDay()->setTime(9, 30),
            'status' => Appointment::STATUS_CONFIRMED,
        ]);
        $this->actingAs($doctor)->postJson('/api/v1/patients/lookup/reference-code', [
            'reference_code' => $patient->reference_code,
        ])->assertOk();
    }

    public function test_merged_prc_is_retired_and_only_registration_sees_survivor_redirect(): void
    {
        $patients = app(PatientService::class);
        $source = $patients->register([
            'first_name' => 'Merge',
            'last_name' => 'Candidate',
            'date_of_birth' => '1980-01-02',
            'sex' => 'Male',
        ]);
        $survivor = $patients->register([
            'first_name' => 'Merge',
            'last_name' => 'Candidate',
            'date_of_birth' => '1980-01-02',
            'sex' => 'Male',
        ]);
        $retiredCode = $source->reference_code;
        $survivorCode = $survivor->reference_code;
        $registration = $this->user('registration@coor.test');

        app(PatientMergeService::class)->merge($source, ['target_patient_id' => $survivor->id], $registration);

        $this->assertSame($retiredCode, Patient::withTrashed()->findOrFail($source->id)->reference_code);
        $this->assertSame($survivorCode, $survivor->fresh()->reference_code);
        $this->assertSame($survivor->id, app(PatientService::class)
            ->lookupByReferenceCode($registration, $retiredCode)['patient']->id);

        $this->actingAs($registration)
            ->from(route('patients.index'))
            ->post(route('patients.find-by-prc'), ['reference_code' => $retiredCode])
            ->assertRedirect(route('patients.show', $survivor))
            ->assertSessionHas('success');

        $nurse = $this->user('nurse@coor.test');
        $this->actingAs($nurse)
            ->post(route('patients.find-by-prc'), ['reference_code' => $retiredCode])
            ->assertRedirect()
            ->assertSessionHasErrors('reference_code');
    }

    public function test_prc_lookup_is_throttled(): void
    {
        $patient = app(PatientService::class)->register($this->registrationData('Throttled'));
        $registration = $this->user('registration@coor.test');

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->actingAs($registration)
                ->post(route('patients.find-by-prc'), ['reference_code' => $patient->reference_code])
                ->assertRedirect();
        }

        $this->actingAs($registration)
            ->post(route('patients.find-by-prc'), ['reference_code' => $patient->reference_code])
            ->assertTooManyRequests();
    }

    private function registrationData(string $firstName): array
    {
        return [
            'first_name' => $firstName,
            'last_name' => 'Reference',
            'date_of_birth' => '1990-04-12',
            'sex' => 'Female',
        ];
    }

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }
}
