<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\PreArrivalProfile;
use App\Models\Role;
use App\Models\User;
use App\Services\PatientConsentService;
use Database\Seeders\HimsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

class PortalPreRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(HimsSeeder::class);
    }

    public function test_patient_can_create_pre_arrival_profile_and_view_ticket(): void
    {
        $role = Role::where('name', 'patient')->firstOrFail();
        $user = User::factory()->create();
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->roles()->syncWithoutDetaching([$role->id]);
        $this->assertTrue($user->fresh()->hasRole('patient'));
        $this->assertTrue($user->fresh()->hasVerifiedEmail());

        $patient = Patient::create([
            'user_id' => $user->id,
            'mrn' => 'MRN-PRE-001',
            'first_name' => 'Jane',
            'last_name' => 'Patient',
            'date_of_birth' => '1990-05-15',
            'sex' => 'Female',
            'phone' => '09170000011',
            'email' => 'jane.patient@example.test',
            'verified' => true,
        ]);
        app(PatientConsentService::class)->record($patient, 'DATA_PRIVACY', true, $user, 'self');
        app(PatientConsentService::class)->record($patient, 'TERMS_OF_USE', true, $user, 'self');

        $getResponse = $this->actingAs($user, 'web')->get('/portal/pre-register');
        $getResponse->assertOk();
        $getResponse->assertSee('Pre-registration');
        $getResponse->assertDontSee('name="data_privacy_consent"', false);

        $postResponse = $this->withSession(['_token' => 'test-token'])
            ->actingAs($user, 'web')
            ->post('/portal/pre-register', [
                '_token' => 'test-token',
                'first_name' => 'Jane',
                'last_name' => 'Patient',
                'date_of_birth' => '1990-05-15',
                'sex' => 'Female',
                'phone' => '09170000011',
                'email' => 'jane.patient@example.test',
                'allergies' => 'Penicillin',
                'emergency_name' => 'John Patient',
                'emergency_phone' => '09170000099',
                'emergency_relationship' => 'Spouse',
                'address_line1' => '123 Sample Street',
                'address_region' => 'National Capital Region (NCR)',
                'address_region_psgc_code' => '1300000000',
                'address_barangay' => 'Bahay Toro',
                'address_barangay_psgc_code' => '1381300012',
                'address_city' => 'Quezon City',
                'address_city_psgc_code' => '1381300000',
                'address_postal' => '1100',
            ]);

        $postResponse->assertSessionHasErrors('data_privacy_consent');
        $this->assertSame(0, $patient->preArrivalProfiles()->count());

        $postResponse = $this->withSession(['_token' => 'test-token'])
            ->actingAs($user, 'web')
            ->post('/portal/pre-register', [
                '_token' => 'test-token',
                'first_name' => 'Jane',
                'last_name' => 'Patient',
                'date_of_birth' => '1990-05-15',
                'sex' => 'Female',
                'phone' => '09170000011',
                'email' => 'jane.patient@example.test',
                'allergies' => 'Penicillin',
                'emergency_name' => 'John Patient',
                'emergency_phone' => '09170000099',
                'emergency_relationship' => 'Spouse',
                'address_line1' => '123 Sample Street',
                'address_region' => 'National Capital Region (NCR)',
                'address_region_psgc_code' => '1300000000',
                'address_barangay' => 'Bahay Toro',
                'address_barangay_psgc_code' => '1381300012',
                'address_city' => 'Quezon City',
                'address_city_psgc_code' => '1381300000',
                'address_postal' => '1100',
                'data_privacy_consent' => '1',
            ]);

        $profile = $patient->preArrivalProfiles()->latest()->first();
        $this->assertNotNull($profile);
        $this->assertNotEmpty($profile->reference_code);
        $this->assertMatchesRegularExpression('/^PAC-\d{4}$/', $profile->reference_code);
        $this->assertDatabaseHas('patient_consents', [
            'patient_id' => $patient->id,
            'type' => 'DATA_PRIVACY',
            'granted' => true,
            'channel' => 'self',
        ]);

        $postResponse->assertRedirect(route('patients.portal'));

        $profileRow = DB::table('pre_arrival_profiles')->where('id', $profile->id)->first();
        $this->assertSame($patient->id, $profileRow->patient_id);
        $this->assertSame('pending', $profileRow->status);
        $this->assertNull($profile->fresh()->visit_reason);

        $this->assertNotEmpty($profile->token);
        $this->assertNotEmpty($profile->qr_code_url);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $profile->qr_code_url);
        $this->assertStringNotContainsString('api.qrserver.com', $profile->qr_code_url);
        $this->assertMatchesRegularExpression('/^PRC-\d{16}$/', $patient->fresh()->reference_code);

        $dashboardResponse = $this->actingAs($user, 'web')->get(route('patients.portal'));
        $dashboardResponse->assertOk();
        $dashboardResponse->assertSee('Pre-arrival ticket');
        $dashboardResponse->assertSee($patient->fresh()->reference_code);
        $dashboardResponse->assertSee('data:image/svg+xml;base64,', false);
        $dashboardResponse->assertDontSee('api.qrserver.com', false);
        $dashboardResponse->assertDontSee($profile->reference_code);
    }

    public function test_patient_pre_registration_accepts_missing_visit_reason_for_dashboard_form(): void
    {
        $role = Role::where('name', 'patient')->firstOrFail();
        $user = User::factory()->create();
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->roles()->syncWithoutDetaching([$role->id]);

        $patient = Patient::create([
            'user_id' => $user->id,
            'mrn' => 'MRN-PRE-DASH-001',
            'first_name' => 'Jane',
            'last_name' => 'Patient',
            'date_of_birth' => '1990-05-15',
            'sex' => 'Female',
            'phone' => '09170000011',
            'email' => 'jane.patient@example.test',
            'verified' => true,
        ]);

        $response = $this->withSession(['_token' => 'test-token'])
            ->actingAs($user, 'web')
            ->post('/portal/pre-register', [
                '_token' => 'test-token',
                'first_name' => 'Jane',
                'last_name' => 'Patient',
                'date_of_birth' => '1990-05-15',
                'sex' => 'Female',
                'phone' => '09170000011',
                'email' => 'jane.patient@example.test',
                'address_line1' => '123 Sample Street',
                'address_region' => 'National Capital Region (NCR)',
                'address_region_psgc_code' => '1300000000',
                'address_barangay' => 'Bahay Toro',
                'address_barangay_psgc_code' => '1381300012',
                'address_city' => 'Quezon City',
                'address_city_psgc_code' => '1381300000',
                'address_postal' => '1100',
                'emergency_name' => 'John Patient',
                'emergency_phone' => '09170000099',
                'emergency_relationship' => 'Spouse',
                'data_privacy_consent' => '1',
            ]);

        $response->assertRedirect(route('patients.portal'));

        $profile = $patient->preArrivalProfiles()->latest()->first();
        $this->assertNotNull($profile);
        $this->assertNull($profile->visit_reason);
    }

    public function test_public_pre_registration_form_includes_missing_demographic_fields(): void
    {
        $response = $this->get('/pre-register');

        $response->assertOk();
        $response->assertSee('name="middle_name"', false);
        $response->assertSee('name="suffix"', false);
        $response->assertSee('name="civil_status"', false);
        $response->assertSee('name="nationality"', false);
        $response->assertSee('name="allergies"', false);
        $response->assertSee('name="address_postal"', false);
    }

    public function test_patient_pre_registration_stores_the_same_demographic_and_contact_fields_as_walk_in_registration(): void
    {
        $role = Role::where('name', 'patient')->firstOrFail();
        $user = User::factory()->create();
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->roles()->syncWithoutDetaching([$role->id]);

        $patient = Patient::create([
            'user_id' => $user->id,
            'mrn' => 'MRN-PRE-PAIR-001',
            'first_name' => 'Jane',
            'middle_name' => 'M.',
            'last_name' => 'Patient',
            'suffix' => 'Jr.',
            'date_of_birth' => '1990-05-15',
            'sex' => 'Female',
            'civil_status' => 'Single',
            'nationality' => 'Filipino',
            'phone' => '09170000011',
            'email' => 'jane.patient@example.test',
            'verified' => true,
        ]);

        $response = $this->withSession(['_token' => 'test-token'])
            ->actingAs($user, 'web')
            ->post('/portal/pre-register', [
                '_token' => 'test-token',
                'first_name' => 'Jane',
                'middle_name' => 'M.',
                'last_name' => 'Patient',
                'suffix' => 'Jr.',
                'date_of_birth' => '1990-05-15',
                'sex' => 'Female',
                'civil_status' => 'Single',
                'nationality' => 'Filipino',
                'phone' => '09170000011',
                'email' => 'jane.patient@example.test',
                'address_line1' => '123 Sample Street',
                'address_region' => 'National Capital Region (NCR)',
                'address_region_psgc_code' => '1300000000',
                'address_barangay' => 'Bahay Toro',
                'address_barangay_psgc_code' => '1381300012',
                'address_city' => 'Quezon City',
                'address_city_psgc_code' => '1381300000',
                'address_postal' => '1100',
                'allergies' => 'Penicillin',
                'emergency_name' => 'John Patient',
                'emergency_phone' => '09170000099',
                'emergency_relationship' => 'Spouse',
                'visit_reason' => 'Follow-up for recurring abdominal pain',
                'data_privacy_consent' => '1',
            ]);

        $response->assertRedirect(route('patients.portal'));

        $profile = $patient->preArrivalProfiles()->latest()->first();
        $this->assertNotNull($profile);
        $this->assertSame('Jane', $profile->first_name);
        $this->assertSame('Patient', $profile->last_name);
        $this->assertSame('1990-05-15', $profile->date_of_birth->format('Y-m-d'));
        $this->assertSame('Female', $profile->sex);
        $this->assertSame('123 Sample Street', $profile->address_line1);
        $this->assertSame('Bahay Toro', $profile->address_barangay);
        $this->assertSame('Quezon City', $profile->address_city);
        $this->assertSame('National Capital Region (NCR)', $patient->fresh()->primaryAddress()->region);
        $this->assertSame('1300000000', $patient->fresh()->primaryAddress()->region_psgc_code);
        $this->assertSame('1381300012', $patient->fresh()->primaryAddress()->barangay_psgc_code);
        $this->assertNull($profile->address_province);
        $this->assertSame('1100', $profile->address_postal);
        $this->assertSame('John Patient', $profile->emergency_name);
        $this->assertSame('09170000099', $profile->emergency_phone);
        $this->assertSame('Spouse', $profile->emergency_relationship);
        $this->assertSame('Penicillin', $profile->allergies);
    }

    public function test_lookup_returns_pre_registration_fields_for_walk_in_registration(): void
    {
        $role = Role::where('name', 'registration')->firstOrFail();
        $user = User::factory()->create();
        $user->roles()->syncWithoutDetaching([$role->id]);

        $patient = Patient::create([
            'user_id' => $user->id,
            'mrn' => 'MRN-LOOKUP-001',
            'first_name' => 'Lookup',
            'last_name' => 'Patient',
            'date_of_birth' => '1990-05-15',
            'sex' => 'Female',
            'phone' => '09170000011',
            'email' => 'lookup.patient@example.test',
            'verified' => true,
        ]);

        $profile = $patient->preArrivalProfiles()->create([
            'token' => (string) Uuid::uuid4(),
            'reference_code' => 'PAC-9988',
            'status' => 'pending',
            'first_name' => 'Lookup',
            'middle_name' => 'Mae',
            'last_name' => 'Patient',
            'suffix' => 'Jr.',
            'date_of_birth' => '1990-05-15',
            'sex' => 'Female',
            'civil_status' => 'Single',
            'nationality' => 'Filipino',
            'phone' => '09170000011',
            'email' => 'lookup.patient@example.test',
            'allergies' => 'Penicillin',
            'emergency_name' => 'John Patient',
            'emergency_relationship' => 'Spouse',
            'emergency_phone' => '09170000099',
            'address_line1' => '123 Sample Street',
            'address_barangay' => 'Bahay Toro',
            'address_city' => 'Quezon City',
            'address_province' => 'Metro Manila',
            'address_postal' => '1100',
            'visit_reason' => 'Follow-up visit',
        ]);

        $response = $this->actingAs($user, 'web')->get('/patients/lookup?q=' . urlencode($profile->reference_code));

        $response->assertOk();
        $response->assertJsonPath('data.0.reference_code', 'PAC-9988');
        $response->assertJsonPath('data.0.middle_name', 'Mae');
        $response->assertJsonPath('data.0.suffix', 'Jr.');
        $response->assertJsonPath('data.0.civil_status', 'Single');
        $response->assertJsonPath('data.0.nationality', 'Filipino');
        $response->assertJsonPath('data.0.allergies', 'Penicillin');
        $response->assertJsonPath('data.0.address.line1', '123 Sample Street');
        $response->assertJsonPath('data.0.emergency_contact.relationship', 'Spouse');
        $response->assertJsonPath('data.0.emergency_contact.phone', '09170000099');
    }

    public function test_lookup_returns_the_matching_reference_code_when_patient_has_multiple_pre_arrival_profiles(): void
    {
        $role = Role::where('name', 'registration')->firstOrFail();
        $user = User::factory()->create();
        $user->roles()->syncWithoutDetaching([$role->id]);

        $patient = Patient::create([
            'user_id' => $user->id,
            'mrn' => 'MRN-LOOKUP-MULTI-001',
            'first_name' => 'Reference',
            'last_name' => 'Patient',
            'date_of_birth' => '1990-05-15',
            'sex' => 'Female',
            'phone' => '09170000088',
            'email' => 'reference.patient@example.test',
            'verified' => true,
        ]);

        $oldProfile = $patient->preArrivalProfiles()->create([
            'token' => (string) Uuid::uuid4(),
            'reference_code' => 'PAC-1001',
            'status' => 'pending',
            'first_name' => 'Older',
            'last_name' => 'Patient',
            'date_of_birth' => '1990-05-15',
            'sex' => 'Female',
            'visit_reason' => 'Older visit',
        ]);

        $newProfile = $patient->preArrivalProfiles()->create([
            'token' => (string) Uuid::uuid4(),
            'reference_code' => 'PAC-2002',
            'status' => 'pending',
            'first_name' => 'Newest',
            'last_name' => 'Patient',
            'date_of_birth' => '1990-05-15',
            'sex' => 'Female',
            'visit_reason' => 'Newest visit',
        ]);

        $response = $this->actingAs($user, 'web')->get('/patients/lookup?q=' . urlencode($newProfile->reference_code));

        $response->assertOk();
        $response->assertJsonPath('data.0.reference_code', 'PAC-2002');
        $response->assertJsonPath('data.0.first_name', 'Newest');
        $response->assertJsonPath('data.0.last_name', 'Patient');
    }

    public function test_registration_fast_lookup_resolves_normalized_prc_and_returns_prefill_fields_without_javascript(): void
    {
        $registration = User::whereHas('roles', fn ($query) => $query->where('name', 'registration'))->firstOrFail();
        $patient = app(\App\Services\PatientService::class)->register([
            'first_name' => 'Stored',
            'last_name' => 'Demographics',
            'date_of_birth' => '1990-05-15',
            'sex' => 'Female',
            'phone' => '09170000011',
            'email' => 'prefill@example.test',
            'address' => ['line1' => 'Stored Street', 'city' => 'Manila'],
            'emergency_contact' => ['name' => 'Contact Name', 'relationship' => 'Sibling', 'phone' => '09170000099'],
        ]);
        $patient->preArrivalProfiles()->create([
            'token' => (string) Uuid::uuid4(),
            'reference_code' => 'PAC-4455',
            'status' => 'pending',
            'first_name' => 'PreRegistered',
            'middle_name' => 'Mae',
            'last_name' => 'Patient',
            'date_of_birth' => '1990-05-15',
            'sex' => 'Female',
            'address_line1' => 'Pre-registration Street',
        ]);

        $response = $this->actingAs($registration)
            ->getJson(route('patients.lookup', ['q' => strtolower(str_replace('-', '', $patient->reference_code))]))
            ->assertOk()
            ->assertJsonPath('data.0.reference_code', $patient->reference_code)
            ->assertJsonPath('data.0.reference_code_type', 'PRC')
            ->assertJsonPath('data.0.first_name', 'PreRegistered')
            ->assertJsonPath('data.0.middle_name', 'Mae')
            ->assertJsonPath('data.0.address.line1', 'Pre-registration Street')
            ->assertJsonPath('data.0.emergency_contact.name', 'Contact Name');

        $log = \App\Models\AuditLog::where('action', 'LOOKUP_PATIENT_REFERENCE_CODE')->latest('id')->firstOrFail();
        $this->assertSame(['result' => 'found', 'code_type' => 'PRC'], $log->metadata);
        $this->assertStringNotContainsString($patient->reference_code, json_encode($log->toArray()));
    }

    public function test_registration_fast_lookup_returns_no_result_for_unknown_code_and_denies_forbidden_role(): void
    {
        $registration = User::whereHas('roles', fn ($query) => $query->where('name', 'registration'))->firstOrFail();
        $this->actingAs($registration)
            ->getJson(route('patients.lookup', ['q' => 'PAC-9999']))
            ->assertOk()
            ->assertExactJson([
                'data' => [],
                'message' => 'No patient record found for this code.',
            ]);

        $this->assertSame('not_found', \App\Models\AuditLog::where('action', 'LOOKUP_PATIENT_REFERENCE_CODE')->latest('id')->firstOrFail()->metadata['result']);

        $nurse = User::whereHas('roles', fn ($query) => $query->where('name', 'nurse'))->firstOrFail();
        $this->actingAs($nurse)
            ->getJson(route('patients.lookup', ['q' => 'PRC-0000000000000000']))
            ->assertForbidden();
    }

    public function test_pre_registration_display_prefers_prc_and_falls_back_to_pac_without_a_patient_prc(): void
    {
        $patient = Patient::create([
            'mrn' => 'MRN-PAC-FALLBACK-001',
            'first_name' => 'Fallback',
            'last_name' => 'Patient',
            'date_of_birth' => '1990-05-15',
            'sex' => 'Female',
        ]);
        $profile = $patient->preArrivalProfiles()->create([
            'token' => (string) Uuid::uuid4(),
            'reference_code' => 'PAC-5566',
            'status' => 'pending',
        ]);

        $this->assertSame('PAC-5566', $profile->displayReferenceCode());
        $this->view('portal.public-pre-register-confirmation', compact('profile'))
            ->assertSee('Patient Reference Code (PRC)')
            ->assertSee('PAC-5566')
            ->assertDontSee('PRC-');

        $orphanedProfile = new PreArrivalProfile(['reference_code' => 'PAC-7788']);
        $orphanedProfile->setRelation('patient', null);
        $this->assertSame('PAC-7788', $orphanedProfile->displayReferenceCode());
    }

    public function test_reference_codes_are_generated_and_unique(): void
    {
        $firstPatient = Patient::create([
            'user_id' => null,
            'mrn' => 'MRN-REF-001',
            'first_name' => 'First',
            'last_name' => 'Patient',
            'date_of_birth' => '1990-01-01',
            'sex' => 'Female',
            'phone' => '09170000001',
            'email' => 'first.patient@example.test',
            'verified' => true,
        ]);

        $secondPatient = Patient::create([
            'user_id' => null,
            'mrn' => 'MRN-REF-002',
            'first_name' => 'Second',
            'last_name' => 'Patient',
            'date_of_birth' => '1991-02-02',
            'sex' => 'Male',
            'phone' => '09170000002',
            'email' => 'second.patient@example.test',
            'verified' => true,
        ]);

        $first = PreArrivalProfile::create([
            'patient_id' => $firstPatient->id,
            'token' => (string) Uuid::uuid4(),
            'reference_code' => PreArrivalProfile::generateUniqueReferenceCode(),
            'status' => 'pending',
            'visit_reason' => 'Follow-up',
        ]);

        $second = PreArrivalProfile::create([
            'patient_id' => $secondPatient->id,
            'token' => (string) Uuid::uuid4(),
            'reference_code' => PreArrivalProfile::generateUniqueReferenceCode(),
            'status' => 'pending',
            'visit_reason' => 'Follow-up',
        ]);

        $this->assertNotSame($first->reference_code, $second->reference_code);
        $this->assertMatchesRegularExpression('/^PAC-\d{4}$/', $first->reference_code);
        $this->assertMatchesRegularExpression('/^PAC-\d{4}$/', $second->reference_code);
    }

    public function test_new_patient_can_pre_register_without_an_existing_account(): void
    {
        $response = $this->withSession(['_token' => 'test-token'])
            ->post('/pre-register', [
                '_token' => 'test-token',
                'first_name' => 'New',
                'last_name' => 'Patient',
                'date_of_birth' => '1995-06-20',
                'sex' => 'Female',
                'phone' => '09170000111',
                'email' => 'new.patient@example.test',
                'address_line1' => '10 New Street',
                'address_region' => 'National Capital Region (NCR)',
                'address_region_psgc_code' => '1300000000',
                'address_barangay' => 'Bahay Toro',
                'address_barangay_psgc_code' => '1381300012',
                'address_city' => 'Quezon City',
                'address_city_psgc_code' => '1381300000',
                'address_postal' => '1000',
                'emergency_name' => 'New Contact',
                'emergency_relationship' => 'Parent',
                'emergency_phone' => '09170000112',
                'visit_reason' => 'New patient consultation',
                'medical_history' => 'None',
                'data_privacy_consent' => '1',
            ]);

        $response->assertOk();
        $response->assertSee('Patient Reference Code (PRC)');

        $patient = Patient::where('email', 'new.patient@example.test')->firstOrFail();
        $profile = $patient->preArrivalProfiles()->firstOrFail();
        $this->assertSame('1381300012', $patient->primaryAddress()->barangay_psgc_code);
        $response->assertSee($patient->reference_code);
        $response->assertDontSee('PAC-');
        $response->assertDontSee($profile->reference_code);
        $this->assertFalse($patient->verified);
        $this->assertDatabaseHas('patient_addresses', [
            'patient_id' => $patient->id,
            'line1' => '10 New Street',
            'barangay' => 'Bahay Toro',
            'region' => 'National Capital Region (NCR)',
            'region_psgc_code' => '1300000000',
            'city_psgc_code' => '1381300000',
            'barangay_psgc_code' => '1381300012',
        ]);
        $this->assertDatabaseHas('emergency_contacts', [
            'patient_id' => $patient->id,
            'name' => 'New Contact',
            'relationship' => 'Parent',
        ]);
        $this->assertSame('pending', $patient->preArrivalProfiles()->firstOrFail()->status);
    }

    public function test_public_pre_registration_rejects_a_city_outside_the_selected_region(): void
    {
        $this->withSession(['_token' => 'test-token'])
            ->post('/pre-register', [
                '_token' => 'test-token',
                'first_name' => 'Invalid',
                'last_name' => 'Address',
                'date_of_birth' => '1995-06-20',
                'sex' => 'Female',
                'email' => 'invalid.address@example.test',
                'address_region' => 'National Capital Region (NCR)',
                'address_region_psgc_code' => '1300000000',
                'address_city' => 'City of Baguio',
                'address_city_psgc_code' => '1430300000',
                'address_barangay' => 'Apugan-Loakan',
                'address_barangay_psgc_code' => '1430300001',
                'data_privacy_consent' => '1',
            ])
            ->assertSessionHasErrors('city');

        $this->assertDatabaseMissing('patients', ['email' => 'invalid.address@example.test']);
    }

    public function test_patient_portal_pre_registration_rejects_a_mismatched_location(): void
    {
        $role = Role::where('name', 'patient')->firstOrFail();
        $user = User::factory()->create();
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->roles()->syncWithoutDetaching([$role->id]);
        $patient = Patient::create([
            'user_id' => $user->id,
            'mrn' => 'MRN-INVALID-ADDRESS',
            'first_name' => 'Portal',
            'last_name' => 'Patient',
            'date_of_birth' => '1990-05-15',
            'sex' => 'Female',
            'email' => 'portal.invalid.address@example.test',
            'verified' => true,
        ]);

        $this->withSession(['_token' => 'test-token'])
            ->actingAs($user, 'web')
            ->post('/portal/pre-register', [
                '_token' => 'test-token',
                'address_region' => 'National Capital Region (NCR)',
                'address_region_psgc_code' => '1300000000',
                'address_city' => 'City of Baguio',
                'address_city_psgc_code' => '1430300000',
                'address_barangay' => 'Apugan-Loakan',
                'address_barangay_psgc_code' => '1430300001',
                'data_privacy_consent' => '1',
            ])
            ->assertSessionHasErrors('city');

        $this->assertSame(0, $patient->preArrivalProfiles()->count());
    }

    public function test_patient_cannot_access_staff_telehealth_index(): void
    {
        $role = Role::where('name', 'patient')->firstOrFail();
        $user = User::factory()->create();
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->roles()->syncWithoutDetaching([$role->id]);

        $patient = Patient::create([
            'user_id' => $user->id,
            'mrn' => 'MRN-ACCESS-001',
            'first_name' => 'Access',
            'last_name' => 'Patient',
            'date_of_birth' => '1991-02-10',
            'sex' => 'Female',
            'phone' => '09170000088',
            'email' => 'access.patient@example.test',
            'verified' => true,
        ]);
        app(PatientConsentService::class)->record($patient, 'DATA_PRIVACY', true, $user, 'self');
        app(PatientConsentService::class)->record($patient, 'TERMS_OF_USE', true, $user, 'self');

        $response = $this->actingAs($user, 'web')->get('/telehealth');

        $response->assertForbidden();
    }

    public function test_patient_dashboard_hides_staff_quick_actions_and_shows_pre_registration_link(): void
    {
        $role = Role::where('name', 'patient')->firstOrFail();
        $user = User::factory()->create();
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->roles()->syncWithoutDetaching([$role->id]);

        $patient = Patient::create([
            'user_id' => $user->id,
            'mrn' => 'MRN-ACCESS-002',
            'first_name' => 'Portal',
            'last_name' => 'Patient',
            'date_of_birth' => '1988-03-15',
            'sex' => 'Male',
            'phone' => '09170000089',
            'email' => 'portal.patient@example.test',
            'verified' => true,
        ]);
        app(PatientConsentService::class)->record($patient, 'DATA_PRIVACY', true, $user, 'self');
        app(PatientConsentService::class)->record($patient, 'TERMS_OF_USE', true, $user, 'self');

        $dashboard = $this->actingAs($user, 'web')->get('/dashboard');
        $dashboard->assertDontSee('Register Patient');
        $dashboard->assertDontSee('ER Queue');
        $dashboard->assertDontSee('>Telehealth</a>', false);
        $dashboard->assertDontSee('Care Delivery');
        $dashboard->assertDontSee('Hospital Systems');
        $dashboard->assertSee('Pre-register for your visit');

        $portal = $this->actingAs($user, 'web')->get('/patient-portal');
        $portal->assertDontSee('Hospital Systems');
        $portal->assertSee('Pre-register for your visit');
    }
}
