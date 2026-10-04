<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\PreArrivalProfile;
use App\Rules\PhilHealthNumber;
use App\Rules\PhilippineMobilePhone;
use App\Services\PatientService;
use App\Services\PatientConsentService;
use App\Services\PatientQrCodeService;
use App\Services\PhilippineAddressDataset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PreRegistrationController extends Controller
{
    public function __construct(
        protected PatientService $patientService,
        protected PatientConsentService $consents,
        protected PhilippineAddressDataset $addressDataset,
        protected PatientQrCodeService $patientQrCodes
    ) {}

    public function publicCreate()
    {
        return view('portal.public-pre-register', [
            'consentTypes' => config('consents.types'),
        ]);
    }

    public function publicStore(Request $request)
    {
        $data = $this->validatePreRegistration($request, true);
        if ($this->patientService->findDuplicates($data) !== []) {
            return back()->withInput()->withErrors([
                'duplicate_patient' => 'A patient record may already match these details. Please contact Registration Staff to verify your record before submitting again.',
            ]);
        }

        $patient = DB::transaction(function () use ($data): Patient {
            $patient = $this->patientService->register([
            'first_name' => $data['first_name'],
            'middle_name' => $data['middle_name'] ?? null,
            'last_name' => $data['last_name'],
            'suffix' => $data['suffix'] ?? null,
            'date_of_birth' => $data['date_of_birth'],
            'sex' => $data['sex'],
            'civil_status' => $data['civil_status'] ?? null,
            'nationality' => $data['nationality'] ?? null,
            'religion' => $data['religion'] ?? null,
            'occupation' => $data['occupation'] ?? null,
            'philhealth_number' => $data['philhealth_number'] ?? null,
            'senior_id_number' => $data['senior_id_number'] ?? null,
            'pwd_id_number' => $data['pwd_id_number'] ?? null,
            'hmo_name' => $data['hmo_name'] ?? null,
            'hmo_member_id' => $data['hmo_member_id'] ?? null,
            'guarantor_name' => $data['guarantor_name'] ?? null,
            'guarantor_relationship' => $data['guarantor_relationship'] ?? null,
            'guarantor_contact' => $data['guarantor_contact'] ?? null,
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'allergies' => $data['allergies'] ?? null,
            'verified' => false,
            'address' => [
                'line1' => $data['address_line1'] ?? null,
                'region' => $data['address_region'] ?? null,
                'barangay' => $data['address_barangay'] ?? null,
                'city' => $data['address_city'] ?? null,
                'province' => $data['address_province'] ?? null,
                'submunicipality' => $data['address_submunicipality'] ?? null,
                'region_psgc_code' => $data['address_region_psgc_code'] ?? null,
                'province_psgc_code' => $data['address_province_psgc_code'] ?? null,
                'city_psgc_code' => $data['address_city_psgc_code'] ?? null,
                'submunicipality_psgc_code' => $data['address_submunicipality_psgc_code'] ?? null,
                'barangay_psgc_code' => $data['address_barangay_psgc_code'] ?? null,
                'postal_code' => $data['address_postal'] ?? null,
            ],
            'emergency_contact' => [
                'name' => $data['emergency_name'] ?? null,
                'relationship' => $data['emergency_relationship'] ?? null,
                'phone' => $data['emergency_phone'] ?? null,
            ],
            ]);
            $this->consents->record($patient, 'DATA_PRIVACY', true, Auth::user(), 'self');

            return $patient;
        });

        $this->patientService->ensureReferenceCode($patient);
        $profile = $this->createProfile($patient, $data);

        return view('portal.public-pre-register-confirmation', compact('profile'));
    }

    public function create()
    {
        $user = Auth::user();
        $patient = $user?->patient()->with(['addresses', 'emergencyContacts'])->first();

        if (! $patient) {
            return redirect()->route('patients.profile')->with('warning', 'Complete your patient profile before pre-registering for care.');
        }

        $address = $patient->primaryAddress();
        $contact = $patient->primaryEmergencyContact();

        $consentTypes = config('consents.types');
        $hasDataPrivacyConsent = $this->consents->hasCurrentGrant($patient, 'DATA_PRIVACY');

        return view('portal.pre-register', compact('patient', 'address', 'contact', 'consentTypes', 'hasDataPrivacyConsent'));
    }

    public function store(Request $request)
    {
        $user = Auth::user();
        $patient = $user?->patient()->firstOrFail();

        $needsDataPrivacyConsent = ! $this->consents->hasCurrentGrant($patient, 'DATA_PRIVACY');
        $data = $this->validatePreRegistration(
            $request,
            false,
            $needsDataPrivacyConsent,
            $patient->primaryAddress()
        );
        $this->patientService->ensureReferenceCode($patient);

        $preferredFirstName = $data['first_name'] ?? $patient->first_name;

        $preferredMiddleName = $data['middle_name'] ?? $patient->middle_name;
        $preferredLastName = $data['last_name'] ?? $patient->last_name;
        $preferredSuffix = $data['suffix'] ?? $patient->suffix;
        $preferredDateOfBirth = $data['date_of_birth'] ?? $patient->date_of_birth?->toDateString();
        $preferredSex = $data['sex'] ?? $patient->sex;
        $preferredCivilStatus = $data['civil_status'] ?? $patient->civil_status;
        $preferredNationality = $data['nationality'] ?? $patient->nationality;
        $preferredPhone = $data['phone'] ?? $patient->phone;
        $preferredEmail = $data['email'] ?? $patient->email;
        $patientData = array_intersect_key($data, array_flip([
            'religion',
            'occupation',
            'philhealth_number',
            'senior_id_number',
            'pwd_id_number',
            'hmo_name',
            'hmo_member_id',
            'guarantor_name',
            'guarantor_relationship',
            'guarantor_contact',
        ]));
        $addressData = [];
        foreach ([
            'line1' => 'address_line1',
            'region' => 'address_region',
            'province' => 'address_province',
            'city' => 'address_city',
            'submunicipality' => 'address_submunicipality',
            'barangay' => 'address_barangay',
            'postal_code' => 'address_postal',
            'region_psgc_code' => 'address_region_psgc_code',
            'province_psgc_code' => 'address_province_psgc_code',
            'city_psgc_code' => 'address_city_psgc_code',
            'submunicipality_psgc_code' => 'address_submunicipality_psgc_code',
            'barangay_psgc_code' => 'address_barangay_psgc_code',
        ] as $addressField => $inputField) {
            if (array_key_exists($inputField, $data)) {
                if (in_array($addressField, ['region', 'province', 'city', 'submunicipality', 'barangay'], true)
                    && blank($data[$inputField])
                    && blank($data[$inputField.'_psgc_code'] ?? null)) {
                    continue;
                }
                $addressData[$addressField] = $data[$inputField];
            }
        }
        if ($addressData !== []) {
            $patientData['address'] = $addressData;
        }
        if ($patientData !== []) {
            $this->patientService->update($patient, $patientData);
        }
        $preferredEmergencyName = $data['emergency_name'] ?? null;
        $preferredEmergencyPhone = $data['emergency_phone'] ?? null;
        $preferredEmergencyRelationship = $data['emergency_relationship'] ?? null;
        $preferredPostal = $data['address_postal'] ?? null;

        $referenceCode = PreArrivalProfile::generateUniqueReferenceCode();
        $token = (string) Str::uuid();

        $profileData = [
            'token' => $token,
            'reference_code' => $referenceCode,
            'status' => 'pending',
            'first_name' => $preferredFirstName,
            'middle_name' => $preferredMiddleName,
            'last_name' => $preferredLastName,
            'suffix' => $preferredSuffix,
            'date_of_birth' => $preferredDateOfBirth,
            'sex' => $preferredSex,
            'civil_status' => $preferredCivilStatus,
            'nationality' => $preferredNationality,
            'phone' => $preferredPhone,
            'email' => $preferredEmail,
            'allergies' => $data['allergies'] ?? null,
            'emergency_name' => $preferredEmergencyName,
            'emergency_phone' => $preferredEmergencyPhone,
            'emergency_relationship' => $preferredEmergencyRelationship,
            'address_line1' => $data['address_line1'] ?? null,
            'address_barangay' => $data['address_barangay'] ?? null,
            'address_city' => $data['address_city'] ?? null,
            'address_province' => $data['address_province'] ?? null,
            'address_postal' => $preferredPostal,
        ];

        $profile = $patient->preArrivalProfiles()->create($profileData);

        $profile->update([
            'token' => $profile->token ?: $token,
            'qr_code_url' => $this->patientQrCodes->dataUriFor($profile->token),
        ]);
        if ($needsDataPrivacyConsent) {
            $this->consents->record($patient, 'DATA_PRIVACY', true, $user, 'self');
        }

        return redirect()->route('patients.portal')->with('success', 'Your pre-registration details have been saved. Patient Reference Code (PRC): ' . $patient->reference_code . '.');
    }

    protected function validatePreRegistration(
        Request $request,
        bool $newPatient = false,
        bool $requireDataPrivacyConsent = false,
        ?\App\Models\PatientAddress $currentAddress = null
    ): array
    {
        $presence = $newPatient ? 'required' : 'nullable';

        $data = $request->validate([
            'first_name' => [$presence, 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => [$presence, 'string', 'max:100'],
            'suffix' => ['nullable', 'string', 'max:20'],
            'date_of_birth' => [$presence, 'date', 'before_or_equal:today'],
            'sex' => [$presence, 'in:Male,Female,Other'],
            'civil_status' => ['nullable', 'string', 'max:50'],
            'nationality' => ['nullable', 'string', 'max:100'],
            'religion' => ['nullable', 'string', 'max:100'],
            'occupation' => ['nullable', 'string', 'max:150'],
            'philhealth_number' => ['nullable', 'string', 'max:30', new PhilHealthNumber],
            'senior_id_number' => ['nullable', 'string', 'max:100'],
            'pwd_id_number' => ['nullable', 'string', 'max:100'],
            'hmo_name' => ['nullable', 'string', 'max:150'],
            'hmo_member_id' => ['nullable', 'string', 'max:100'],
            'guarantor_name' => ['nullable', 'string', 'max:150'],
            'guarantor_relationship' => ['nullable', 'string', 'max:100'],
            'guarantor_contact' => ['nullable', 'string', 'max:30', new PhilippineMobilePhone],
            'phone' => ['nullable', 'string', 'max:30', new PhilippineMobilePhone],
            'email' => ['nullable', 'email', 'max:255'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_region' => ['nullable', 'string', 'max:150'],
            'address_region_psgc_code' => ['nullable', 'string', 'regex:/^\d{10}$/'],
            'address_barangay' => ['nullable', 'string', 'max:150'],
            'address_barangay_psgc_code' => ['nullable', 'string', 'regex:/^\d{10}$/'],
            'address_city' => ['nullable', 'string', 'max:100'],
            'address_city_psgc_code' => ['nullable', 'string', 'regex:/^\d{10}$/'],
            'address_province' => ['nullable', 'string', 'max:100'],
            'address_province_psgc_code' => ['nullable', 'string', 'regex:/^\d{10}$/'],
            'address_submunicipality' => ['nullable', 'string', 'max:150'],
            'address_submunicipality_psgc_code' => ['nullable', 'string', 'regex:/^\d{10}$/'],
            'address_postal' => ['nullable', 'string', 'max:10'],
            'allergies' => ['nullable', 'string', 'max:2000'],
            'emergency_name' => ['nullable', 'string', 'max:150'],
            'emergency_phone' => ['nullable', 'string', 'max:30', new PhilippineMobilePhone],
            'emergency_relationship' => ['nullable', 'string', 'max:50'],
            'data_privacy_consent' => ($newPatient || $requireDataPrivacyConsent)
                ? ['accepted']
                : ['nullable', 'accepted'],
        ]);

        $selection = [];
        foreach (['region', 'province', 'city', 'submunicipality', 'barangay'] as $level) {
            foreach ([$level, $level.'_psgc_code'] as $field) {
                $inputField = 'address_'.$field;
                if (array_key_exists($inputField, $data)) {
                    $selection[$field] = $data[$inputField];
                }
            }
        }
        $selection = $this->addressDataset->withoutEmptySelection($selection);
        if ($newPatient || $this->addressDataset->hasLocationChanged($currentAddress, $selection)) {
            $validatedSelection = $this->addressDataset->validateSelection($selection);
            foreach ($validatedSelection as $field => $value) {
                $data['address_'.$field] = $value;
            }
        }

        return $data;
    }

    protected function createProfile(Patient $patient, array $data): PreArrivalProfile
    {
        $profile = $patient->preArrivalProfiles()->create([
            'token' => (string) Str::uuid(),
            'reference_code' => PreArrivalProfile::generateUniqueReferenceCode(),
            'status' => 'pending',
            'first_name' => $data['first_name'],
            'middle_name' => $data['middle_name'] ?? null,
            'last_name' => $data['last_name'],
            'suffix' => $data['suffix'] ?? null,
            'date_of_birth' => $data['date_of_birth'],
            'sex' => $data['sex'],
            'civil_status' => $data['civil_status'] ?? null,
            'nationality' => $data['nationality'] ?? null,
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'allergies' => $data['allergies'] ?? null,
            'emergency_name' => $data['emergency_name'] ?? null,
            'emergency_phone' => $data['emergency_phone'] ?? null,
            'emergency_relationship' => $data['emergency_relationship'] ?? null,
            'address_line1' => $data['address_line1'] ?? null,
            'address_barangay' => $data['address_barangay'] ?? null,
            'address_city' => $data['address_city'] ?? null,
            'address_province' => $data['address_province'] ?? null,
            'address_postal' => $data['address_postal'] ?? null,
        ]);

        $profile->update(['qr_code_url' => $this->patientQrCodes->dataUriFor($profile->token)]);

        return $profile;
    }
}
