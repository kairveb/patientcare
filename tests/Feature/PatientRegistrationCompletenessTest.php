<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ChargeRate;
use App\Models\Encounter;
use App\Models\ErVisit;
use App\Models\Patient;
use App\Models\PatientAddress;
use App\Models\Role;
use App\Models\User;
use App\Http\Middleware\EnsureCurrentPrivacyAcceptance;
use App\Services\BillingAggregationService;
use Database\Seeders\HimsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PatientRegistrationCompletenessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(HimsSeeder::class);
    }

    public function test_registration_and_patient_portal_capture_registration_data_with_encrypted_identifiers(): void
    {
        $registration = $this->userWithRole('registration');
        $response = $this->actingAs($registration)->postJson('/api/v1/patients', [
            'first_name' => 'Registration',
            'last_name' => 'Patient',
            'date_of_birth' => '1990-04-12',
            'sex' => 'Female',
            'religion' => 'Christian',
            'occupation' => 'Teacher',
            'philhealth_number' => '12 3456-7890 12',
            'senior_id_number' => '123-456',
            'pwd_id_number' => '789 012',
            'hmo_name' => 'Example HMO',
            'hmo_member_id' => '345-678',
            'guarantor_name' => 'Guarantor Person',
            'guarantor_relationship' => 'Sibling',
            'guarantor_contact' => '09123456789',
            'emergency_contact' => [
                'name' => 'Emergency Contact',
                'relationship' => 'Sibling',
                'phone' => '+639123456789',
            ],
            'address' => [
                'region' => 'National Capital Region (NCR)',
                'region_psgc_code' => '1300000000',
                'city' => 'Quezon City',
                'city_psgc_code' => '1381300000',
                'barangay' => 'Bahay Toro',
                'barangay_psgc_code' => '1381300012',
                'postal_code' => '1100',
            ],
            'data_privacy_consent' => true,
        ])->assertCreated()
            ->assertJsonPath('data.philhealth_number', '123456789012')
            ->assertJsonPath('data.hmo_member_id', '345678')
            ->assertJsonPath('data.religion', 'Christian');

        $patient = Patient::findOrFail($response->json('data.id'));
        $this->assertSame('123456789012', $patient->philhealth_number);
        $this->assertNotSame('123456789012', DB::table('patients')->where('id', $patient->id)->value('philhealth_number'));
        $this->assertSame('345678', $patient->hmo_member_id);
        $this->assertSame('1381300012', $patient->primaryAddress()->barangay_psgc_code);

        $this->actingAs($registration)->postJson('/api/v1/patients', [
            'first_name' => 'Invalid',
            'last_name' => 'Address',
            'date_of_birth' => '1991-04-12',
            'sex' => 'Female',
            'address' => [
                'region' => 'National Capital Region (NCR)',
                'region_psgc_code' => '1300000000',
                'city' => 'City of Baguio',
                'city_psgc_code' => '1430300000',
                'barangay' => 'Apugan-Loakan',
                'barangay_psgc_code' => '1430300001',
            ],
            'data_privacy_consent' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('city');
        $this->assertDatabaseMissing('patients', ['first_name' => 'Invalid']);

        $patientUser = $this->userWithRole('patient');
        $portalPatient = Patient::factory()->create(['user_id' => $patientUser->id]);
        $this->acceptRequiredPrivacyDocuments($portalPatient, $patientUser);
        $this->actingAs($patientUser)->post(route('patients.profile.save'), [
            'first_name' => $portalPatient->first_name,
            'last_name' => $portalPatient->last_name,
            'date_of_birth' => $portalPatient->date_of_birth->toDateString(),
            'sex' => $portalPatient->sex,
            'philhealth_number' => '123456789012',
            'religion' => 'Catholic',
            'address' => [
                'region' => 'National Capital Region (NCR)',
                'region_psgc_code' => '1300000000',
                'city' => 'Quezon City',
                'city_psgc_code' => '1381300000',
                'barangay' => 'Bahay Toro',
                'barangay_psgc_code' => '1381300012',
            ],
            'data_privacy_consent' => true,
        ])->assertRedirect(route('dashboard'));

        $this->assertSame('123456789012', $portalPatient->fresh()->philhealth_number);
        $this->assertSame('Catholic', $portalPatient->fresh()->religion);
        $this->assertSame('1381300012', $portalPatient->fresh()->primaryAddress()->barangay_psgc_code);

        $this->actingAs($patientUser)->post(route('patients.profile.save'), [
            'first_name' => $portalPatient->first_name,
            'last_name' => $portalPatient->last_name,
            'date_of_birth' => $portalPatient->date_of_birth->toDateString(),
            'sex' => $portalPatient->sex,
            'address' => [
                'region' => 'National Capital Region (NCR)',
                'region_psgc_code' => '1300000000',
                'city' => 'City of Baguio',
                'city_psgc_code' => '1430300000',
                'barangay' => 'Apugan-Loakan',
                'barangay_psgc_code' => '1430300001',
            ],
            'data_privacy_consent' => true,
        ])->assertRedirect();
        $this->assertSame('1381300012', $portalPatient->fresh()->primaryAddress()->barangay_psgc_code);
    }

    public function test_staff_registration_saves_shared_demographics_and_normalizes_identifiers(): void
    {
        $registration = $this->userWithRole('registration');

        $this->actingAs($registration)->post(route('patients.store'), [
            'first_name' => 'Staff',
            'middle_name' => 'Form',
            'last_name' => 'Patient',
            'suffix' => 'Jr.',
            'date_of_birth' => '1990-04-12',
            'sex' => 'Not specified',
            'civil_status' => 'Annulled/Divorced',
            'philhealth_number' => '1234-5678 9012',
            'senior_id_number' => '123-456',
            'pwd_id_number' => '789 012',
            'hmo_member_id' => '345-678',
            'data_privacy_consent' => true,
        ])->assertRedirect();

        $patient = Patient::where('first_name', 'Staff')->firstOrFail();
        $this->assertSame('Form', $patient->middle_name);
        $this->assertSame('Jr.', $patient->suffix);
        $this->assertSame('Not specified', $patient->sex);
        $this->assertSame('Annulled/Divorced', $patient->civil_status);
        $this->assertSame('Filipino', $patient->nationality);
        $this->assertSame('123456789012', $patient->philhealth_number);
        $this->assertSame('123456', $patient->senior_id_number);
        $this->assertSame('789012', $patient->pwd_id_number);
        $this->assertSame('345678', $patient->hmo_member_id);
    }

    public function test_invalid_philhealth_format_and_forbidden_demographic_update_are_rejected(): void
    {
        $registration = $this->userWithRole('registration');
        $patientData = [
            'first_name' => 'Invalid',
            'last_name' => 'PhilHealth',
            'date_of_birth' => '1990-04-12',
            'sex' => 'Female',
            'philhealth_number' => 'gsgahkjsj',
            'data_privacy_consent' => true,
        ];

        $this->actingAs($registration)->postJson('/api/v1/patients', $patientData)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('philhealth_number')
            ->assertJsonPath(
                'errors.philhealth_number.0',
                'Please enter exactly 12 numbers. Spaces or dashes may be used for readability.'
            );
        $this->assertDatabaseMissing('patients', ['first_name' => 'Invalid']);

        $this->actingAs($registration)->postJson('/api/v1/patients', [
            ...$patientData,
            'philhealth_number' => '123456789012',
            'senior_id_number' => "SC-123\u{0007}",
            'pwd_id_number' => 'PWD-123',
            'hmo_member_id' => 'HMO-123',
            'guarantor_contact' => '555',
            'emergency_contact' => ['phone' => '555'],
            'address' => ['postal_code' => 'AB12'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'senior_id_number',
                'pwd_id_number',
                'hmo_member_id',
                'address.postal_code',
                'guarantor_contact',
                'emergency_contact.phone',
            ]);

        $this->actingAs($registration)->postJson('/api/v1/patients', [
            ...$patientData,
            'philhealth_number' => '123456789012',
            'senior_id_number' => '2026-01',
            'pwd_id_number' => '44 7',
            'hmo_member_id' => '123-456',
            'address' => [
                'region' => 'National Capital Region (NCR)',
                'region_psgc_code' => '1300000000',
                'city' => 'Quezon City',
                'city_psgc_code' => '1381300000',
                'barangay' => 'Bahay Toro',
                'barangay_psgc_code' => '1381300012',
                'postal_code' => '1100',
            ],
        ])->assertCreated();

        $patient = Patient::factory()->create();
        $nurse = $this->userWithRole('nurse');
        $this->actingAs($nurse)->patchJson("/api/v1/patients/{$patient->id}", [
            'religion' => 'Not permitted',
        ])->assertForbidden();
    }

    public function test_profile_and_staff_registration_reject_non_digit_philhealth_and_postal_values(): void
    {
        $patientUser = $this->userWithRole('patient');
        $patient = Patient::factory()->create(['user_id' => $patientUser->id]);
        $this->acceptRequiredPrivacyDocuments($patient, $patientUser);

        $this->actingAs($patientUser)->post(route('patients.profile.save'), [
        'first_name' => $patient->first_name,
        'last_name' => $patient->last_name,
        'date_of_birth' => $patient->date_of_birth->toDateString(),
        'sex' => $patient->sex,
        'philhealth_number' => 'gsgahkjsj',
        'address' => ['postal_code' => 'AB12'],
        ])->assertSessionHasErrors(['philhealth_number', 'address.postal_code']);

        $registration = $this->userWithRole('registration');
        $this->actingAs($registration)->from(route('patients.create'))->post(route('patients.store'), [
        'first_name' => 'New',
        'last_name' => 'Patient',
        'date_of_birth' => '1990-04-12',
        'sex' => 'Female',
        'philhealth_number' => 'gsgahkjsj',
        'address_postal' => 'AB12',
        'data_privacy_consent' => true,
        ])->assertSessionHasErrors(['philhealth_number', 'address_postal']);
    }

    public function test_patient_api_update_uses_the_same_identifier_and_phone_validation(): void
    {
        $registration = $this->userWithRole('registration');
        $patient = Patient::factory()->create();

        $this->actingAs($registration)->patchJson("/api/v1/patients/{$patient->id}", [
            'philhealth_number' => 'gsgahkjsj',
            'senior_id_number' => "SC-123\u{0007}",
            'address' => ['postal_code' => 'AB12'],
            'emergency_contact' => ['phone' => '123'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'philhealth_number',
                'senior_id_number',
                'address.postal_code',
                'emergency_contact.phone',
            ]);
    }

    public function test_profile_and_staff_registration_render_numeric_identifier_inputs(): void
    {
        $patientUser = $this->userWithRole('patient');
        $patient = Patient::factory()->create(['user_id' => $patientUser->id]);
        $this->acceptRequiredPrivacyDocuments($patient, $patientUser);

        $this->actingAs($patientUser)->get(route('patients.profile'))
        ->assertOk()
        ->assertSee('name="philhealth_number"', false)
        ->assertSee('inputmode="numeric" pattern="[0-9]+(?:[ -]?[0-9]+)*" maxlength="23" data-patient-identifier="philhealth"', false)
        ->assertSee('name="address[postal_code]"', false)
        ->assertSee('pattern="[0-9]{4}" maxlength="4" data-digits-only', false)
        ->assertSee('name="senior_id_number"', false)
        ->assertSee('inputmode="numeric" pattern="[0-9]+(?:[ -]?[0-9]+)*" maxlength="100" autocomplete="off" data-patient-identifier', false)
        ->assertSee('name="pwd_id_number"', false)
        ->assertSee('name="hmo_member_id"', false);

        $registration = $this->userWithRole('registration');
        $this->actingAs($registration)->get(route('patients.index'))
        ->assertOk()
        ->assertSee('inputmode="numeric" pattern="[0-9]+(?:[ -]?[0-9]+)*" maxlength="23" data-patient-identifier="philhealth"', false)
        ->assertSee('pattern="[0-9]{4}" maxlength="4" data-digits-only', false);
    }

    public function test_legacy_free_text_address_survives_unrelated_profile_edits(): void
    {
        $patientUser = $this->userWithRole('patient');
        $patient = Patient::factory()->create(['user_id' => $patientUser->id]);
        $this->acceptRequiredPrivacyDocuments($patient, $patientUser);
        $address = PatientAddress::create([
            'patient_id' => $patient->id,
            'line1' => '12 Legacy Street',
            'barangay' => 'Old Barangay Label',
            'city' => 'Old City Label',
            'province' => 'Old Province Label',
            'primary' => true,
        ]);

        $this->actingAs($patientUser)->get(route('patients.profile'))
            ->assertOk()
            ->assertSee('12 Legacy Street')
            ->assertSee('Old Barangay Label');

        $this->actingAs($patientUser)->post(route('patients.profile.save'), [
            'first_name' => $patient->first_name,
            'last_name' => $patient->last_name,
            'date_of_birth' => $patient->date_of_birth->toDateString(),
            'sex' => $patient->sex,
            'religion' => 'Catholic',
            'data_privacy_consent' => true,
        ])->assertRedirect(route('dashboard'));

        $address->refresh();
        $this->assertSame('12 Legacy Street', $address->line1);
        $this->assertSame('Old Barangay Label', $address->barangay);
        $this->assertSame('Old City Label', $address->city);
        $this->assertSame('Old Province Label', $address->province);
        $this->assertNull($address->region_psgc_code);
    }

    public function test_patient_profile_defaults_nationality_and_saves_supported_civil_status_without_reconsent(): void
    {
        $patientUser = $this->userWithRole('patient');
        $patient = Patient::factory()->create([
            'user_id' => $patientUser->id,
            'nationality' => null,
        ]);
        $this->acceptRequiredPrivacyDocuments($patient, $patientUser);

        $this->actingAs($patientUser)->get(route('patients.profile'))
            ->assertOk()
            ->assertSee('value="Single"', false)
            ->assertSee('value="Married"', false)
            ->assertSee('value="Widowed"', false)
            ->assertSee('value="Separated"', false)
            ->assertSee('value="Annulled/Divorced"', false)
            ->assertSee('>Annulled/Divorced</option>', false)
            ->assertSee('name="nationality" value="Filipino"', false)
            ->assertDontSee('name="data_privacy_consent"', false);

        $this->actingAs($patientUser)->post(route('patients.profile.save'), [
            'first_name' => $patient->first_name,
            'last_name' => $patient->last_name,
            'date_of_birth' => $patient->date_of_birth->toDateString(),
            'sex' => $patient->sex,
            'civil_status' => 'Annulled/Divorced',
            'nationality' => 'Filipino',
        ])->assertRedirect(route('dashboard'));

        $this->assertSame('Annulled/Divorced', $patient->fresh()->civil_status);
        $this->assertSame('Filipino', $patient->fresh()->nationality);

        $this->actingAs($patientUser)->post(route('patients.profile.save'), [
            'first_name' => '',
            'last_name' => $patient->fresh()->last_name,
            'date_of_birth' => $patient->fresh()->date_of_birth->toDateString(),
            'sex' => $patient->fresh()->sex,
            'nationality' => '',
        ])->assertSessionHasErrors('first_name');

        $this->get(route('patients.profile'))
            ->assertOk()
            ->assertSee('name="nationality" value="Filipino"', false);
    }

    public function test_patient_profile_shows_the_privacy_checkbox_when_consent_is_not_current(): void
    {
        $patientUser = $this->userWithRole('patient');
        Patient::factory()->create(['user_id' => $patientUser->id]);
        $this->acceptRequiredPrivacyDocuments($patientUser->patient, $patientUser);
        config(['consents.types.DATA_PRIVACY.version' => '2.0']);
        $this->withoutMiddleware(EnsureCurrentPrivacyAcceptance::class);

        $this->actingAs($patientUser)->get(route('patients.profile'))
            ->assertOk()
            ->assertSee('name="data_privacy_consent"', false)
            ->assertSee('type="checkbox"', false)
            ->assertSee('value="1"', false)
            ->assertSee('required', false);

        $this->post(route('patients.profile.save'), [
            'first_name' => $patientUser->patient->first_name,
            'last_name' => $patientUser->patient->last_name,
            'date_of_birth' => $patientUser->patient->date_of_birth->toDateString(),
            'sex' => $patientUser->patient->sex,
        ])->assertSessionHasErrors('data_privacy_consent');
    }

    public function test_staff_registration_uses_the_shared_privacy_consent_block(): void
    {
        $admin = User::where('email', 'super-admin@coor.test')->firstOrFail();

        $this->withoutMiddleware(EnsureCurrentPrivacyAcceptance::class)
            ->actingAs($admin)->get(route('patients.index'))
            ->assertOk()
            ->assertSee('name="data_privacy_consent"', false)
            ->assertSee('Read the Privacy Notice')
            ->assertSee('type="checkbox"', false);
    }

    public function test_registration_staff_verifies_identifiers_and_identifier_changes_clear_verification(): void
    {
        $registration = $this->userWithRole('registration');
        $patient = Patient::factory()->create(['philhealth_number' => '123456789012']);
        $url = "/api/v1/patients/{$patient->id}/identifiers/philhealth_number/verify";

        $this->actingAs($registration)->postJson($url)
            ->assertOk()
            ->assertJsonPath('data.identifier_type', 'philhealth_number')
            ->assertJsonPath('data.verified_by', $registration->id);
        $this->assertNotNull($patient->fresh()->philhealth_verified_at);

        $this->actingAs($registration)->patchJson("/api/v1/patients/{$patient->id}", [
            'philhealth_number' => '123456789013',
        ])->assertOk();
        $this->assertNull($patient->fresh()->philhealth_verified_by);
        $this->assertNull($patient->fresh()->philhealth_verified_at);

        $nurse = $this->userWithRole('nurse');
        $this->actingAs($nurse)->postJson($url)->assertForbidden();
        $this->actingAs($registration)->postJson("/api/v1/patients/{$patient->id}/identifiers/unsupported/verify")
            ->assertForbidden();

        $emptyPatient = Patient::factory()->create();
        $this->actingAs($registration)
            ->postJson("/api/v1/patients/{$emptyPatient->id}/identifiers/pwd_id_number/verify")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('identifier');
        $this->assertSame(1, AuditLog::where('action', 'VERIFY_PATIENT_IDENTIFIER')->count());
    }

    public function test_patient_can_upload_own_id_image_but_staff_only_can_verify_it(): void
    {
        Storage::fake('local');
        $patientUser = $this->userWithRole('patient');
        $patient = Patient::factory()->create(['user_id' => $patientUser->id]);
        $this->acceptRequiredPrivacyDocuments($patient, $patientUser);

        $documentId = $this->actingAs($patientUser)->postJson('/api/v1/me/identity-documents', [
            'identifier_type' => 'senior_id_number',
            'document' => UploadedFile::fake()->image('senior-id.png'),
        ])->assertCreated()
            ->assertJsonPath('data.patient_id', $patient->id)
            ->assertJsonPath('data.identifier_type', 'senior_id_number')
            ->json('data.id');

        $this->assertDatabaseHas('clinical_documents', [
            'patient_id' => $patient->id,
            'identifier_type' => 'senior_id_number',
            'uploaded_by' => $patientUser->id,
        ]);

        $registration = $this->userWithRole('registration');
        $this->actingAs($registration)
            ->get(route('patients.clinical-documents.show', [
                'patientId' => $patient->id,
                'id' => $documentId,
                'download' => 1,
            ]))
            ->assertOk();

        $nurse = $this->userWithRole('nurse');
        $this->actingAs($nurse)->postJson('/api/v1/me/identity-documents', [
            'identifier_type' => 'senior_id_number',
            'document' => UploadedFile::fake()->image('senior-id.png'),
        ])->assertForbidden();
    }

    public function test_nurse_and_doctor_patient_api_payloads_do_not_expose_registration_identifiers(): void
    {
        $patient = Patient::factory()->create([
            'philhealth_number' => '123456789012',
            'senior_id_number' => '123456',
            'hmo_member_id' => '789012',
            'guarantor_name' => 'Private Guarantor',
        ]);

        $doctor = User::where('email', 'doctor@coor.test')->firstOrFail();
        Encounter::create([
            'encounter_number' => 'ENC-PRIVACY-'.Str::upper(Str::random(8)),
            'patient_id' => $patient->id,
            'provider_id' => $doctor->provider->id,
            'type' => Encounter::TYPE_OUTPATIENT,
            'started_at' => now(),
            'status' => 'OPEN',
        ]);
        $nurse = $this->userWithRole('nurse');
        ErVisit::create([
            'visit_number' => 'ER-PRIVACY-'.Str::upper(Str::random(8)),
            'patient_id' => $patient->id,
            'arrived_at' => now(),
            'chief_complaint' => 'Privacy test',
            'status' => ErVisit::STATUS_IN_TREATMENT,
            'created_by' => $nurse->id,
        ]);

        foreach ([$nurse, $doctor] as $user) {
            $this->actingAs($user)->getJson("/api/v1/patients/{$patient->id}")
                ->assertOk()
                ->assertJsonMissingPath('data.philhealth_number')
                ->assertJsonMissingPath('data.senior_id_number')
                ->assertJsonMissingPath('data.hmo_member_id')
                ->assertJsonMissingPath('data.guarantor_name');
        }

        $registration = $this->userWithRole('registration');
        $this->actingAs($registration)->getJson("/api/v1/patients/{$patient->id}")
            ->assertOk()
            ->assertJsonPath('data.philhealth_number', '123456789012');
    }

    public function test_billing_preview_exposes_verified_senior_and_pwd_flags_without_discount_calculation(): void
    {
        $doctor = User::where('email', 'doctor@coor.test')->firstOrFail();
        $registration = $this->userWithRole('registration');
        $patient = Patient::factory()->create([
            'senior_id_number' => '123-456',
            'senior_id_verified_by' => $registration->id,
            'senior_id_verified_at' => now(),
            'pwd_id_number' => null,
            'pwd_id_verified_at' => null,
        ]);
        $encounter = Encounter::create([
            'encounter_number' => 'ENC-REG-'.Str::upper(Str::random(8)),
            'patient_id' => $patient->id,
            'provider_id' => $doctor->provider->id,
            'type' => Encounter::TYPE_OUTPATIENT,
            'started_at' => now(),
            'status' => 'OPEN',
        ]);
        ChargeRate::create([
            'charge_code' => 'encounter:OUTPATIENT',
            'category' => 'ENCOUNTER',
            'description' => 'Outpatient encounter',
            'unit_amount' => 250,
            'currency' => 'PHP',
            'active' => true,
        ]);

        $preview = app(BillingAggregationService::class)->previewForEncounter($encounter);

        $this->assertSame(['senior' => true, 'pwd' => false], $preview['discount_flags']);
        $this->assertSame('250.00', $preview['total']);
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create([
            'email' => $roleName.'-'.Str::uuid().'@example.test',
        ]);
        $user->roles()->attach(Role::where('name', $roleName)->firstOrFail());

        return $user;
    }
}
