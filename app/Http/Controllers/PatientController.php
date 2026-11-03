<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\AppointmentType;
use App\Models\Department;
use App\Models\Patient;
use App\Models\PatientAddress;
use App\Models\PreArrivalProfile;
use App\Models\Provider;
use App\Http\Requests\CompleteTemporaryIdentityRequest;
use App\Http\Requests\MergePatientRequest;
use App\Http\Requests\VerifyPatientIdentifierRequest;
use App\Rules\PhilHealthNumber;
use App\Rules\PhilippineMobilePhone;
use App\Services\AuditLogService;
use App\Services\PatientConsentService;
use App\Services\PatientIdentifierService;
use App\Services\PatientMergeService;
use App\Services\PatientQrCodeService;
use App\Services\PatientService;
use App\Services\PhilippineAddressDataset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PatientController extends Controller
{
    public function __construct(
        protected PatientService $patientService,
        protected AuditLogService $audit,
        protected PatientConsentService $consents,
        protected PhilippineAddressDataset $addressDataset
    ) {
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', Patient::class);

        $patients = $this->patientService->search(
            $request->user(),
            $request->query('q'),
            $request->only(['date_of_birth', 'sex'])
        );
        $providers = Provider::where('active', true)->with('department')->get();
        $departments = Department::orderBy('name')->get();
        $appointmentTypes = AppointmentType::orderBy('name')->get();
        return view('patients.index', compact('patients', 'providers', 'departments', 'appointmentTypes'));
    }

    public function findByReferenceCode(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'reference_code' => ['nullable', 'string'],
        ], [
            'reference_code.string' => 'No patient record found for this code.',
        ]);

        $result = $this->patientService->resolvePatientReferenceCode($request->user(), $data['reference_code'] ?? null);

        if ($result['status'] === 'found') {
            return redirect()->route('patients.show', $result['patient']);
        }

        if ($result['status'] === 'merged' && $result['patient']) {
            return redirect()->route('patients.show', $result['patient'])
                ->with('success', 'This code belongs to a merged record. The surviving patient record is shown.');
        }

        return back()->withErrors([
            'reference_code' => 'No patient record found for this code.',
        ])->withInput();
    }

    public function create()
    {
        return redirect()->route('patients.index');
    }

    public function store(Request $request)
    {
        $this->authorize('create', Patient::class);

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100', 'regex:/^[\pL\s\'\-.]+$/u'],
            'last_name' => ['required', 'string', 'max:100', 'regex:/^[\pL\s\'\-.]+$/u'],
            'date_of_birth' => ['required', 'date', 'before_or_equal:today'],
            'sex' => ['required', 'in:Male,Female,Other'],
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
            'email' => ['nullable', 'email', 'lowercase', 'max:255'],
            'address_line1' => ['nullable', 'string', 'max:255', 'regex:/^[\pL\pN\s\'\-\.,#]+$/u'],
            'address_region' => ['nullable', 'string', 'max:150'],
            'address_region_psgc_code' => ['nullable', 'string', 'regex:/^\d{10}$/'],
            'address_barangay' => ['nullable', 'string', 'max:150', 'regex:/^[\pL\pN\s\'\-.]+$/u'],
            'address_barangay_psgc_code' => ['nullable', 'string', 'regex:/^\d{10}$/'],
            'address_city' => ['nullable', 'string', 'max:100', 'regex:/^[\pL\pN\s\'\-.]+$/u'],
            'address_city_psgc_code' => ['nullable', 'string', 'regex:/^\d{10}$/'],
            'address_province' => ['nullable', 'string', 'max:100', 'regex:/^[\pL\pN\s\'\-.]+$/u'],
            'address_province_psgc_code' => ['nullable', 'string', 'regex:/^\d{10}$/'],
            'address_submunicipality' => ['nullable', 'string', 'max:150'],
            'address_submunicipality_psgc_code' => ['nullable', 'string', 'regex:/^\d{10}$/'],
            'address_postal' => ['nullable', 'string', 'max:10'],
            'emergency_name' => ['nullable', 'string', 'max:150', 'regex:/^[\pL\s\'\-.]+$/u'],
            'emergency_relationship' => ['nullable', 'string', 'max:50', 'regex:/^[\pL\s\'\-.]+$/u'],
            'emergency_phone' => ['nullable', 'string', 'max:30', new PhilippineMobilePhone],
            'data_privacy_consent' => ['accepted'],
            'force' => ['sometimes', 'boolean'],
        ], [
            'first_name.regex' => 'First name may only contain letters, spaces, and basic punctuation.',
            'last_name.regex' => 'Last name may only contain letters, spaces, and basic punctuation.',
            'phone' => 'Contact number must be a valid Philippine mobile number (09XXXXXXXXX or +639XXXXXXXXX).',
            'emergency_phone' => 'Emergency contact number must be a valid Philippine mobile number (09XXXXXXXXX or +639XXXXXXXXX).',
            'address_postal.regex' => 'Postal code must be exactly 4 digits.',
            'date_of_birth.before_or_equal' => 'Date of birth cannot be in the future.',
        ]);
        $addressSelection = $this->flatAddressSelection($data);
        $data = array_merge($data, $this->flatAddressFields(
            $this->addressDataset->validateSelection($addressSelection)
        ));

        // Duplicate detection
        $duplicates = $this->patientService->findDuplicates([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'date_of_birth' => $data['date_of_birth'],
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
        ]);

        if ($request->boolean('force')) {
            $patient = DB::transaction(function () use ($data, $duplicates): Patient {
                if ($duplicates !== []) {
                    $this->audit->log('DUPLICATE_REGISTRATION_OVERRIDE', 'patient', null, 'SUCCESS', [
                        'candidate_ids' => array_column($duplicates, 'id'),
                        'similarity_scores' => array_column($duplicates, 'similarity_score', 'id'),
                    ], auth()->id());
                }

                return $this->createPatient($data);
            });

            return redirect()->route('patients.show', $patient)->with('success', 'Patient registered successfully.');
        }

        if (count($duplicates) > 0) {
            return back()->withInput()->with([
                'duplicate_warning' => 'Possible existing patient record found. Please verify before proceeding.',
                'duplicates' => $duplicates,
            ]);
        }

        $patient = $this->createPatient($data);

        return redirect()->route('patients.show', $patient)->with('success', 'Patient registered successfully.');
    }

    protected function createPatient(array $data): Patient
    {
        return DB::transaction(function () use ($data): Patient {
            $patient = $this->patientService->register($this->buildRegistrationPayload($data), auth()->id());
            $this->consents->record($patient, 'DATA_PRIVACY', true, auth()->user(), 'staff');

            return $patient;
        });
    }

    protected function buildRegistrationPayload(array $data): array
    {
        return [
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'date_of_birth' => $data['date_of_birth'],
            'sex' => $data['sex'],
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
        ];
    }

    private function flatAddressSelection(array $data): array
    {
        $selection = [];
        foreach (['region', 'province', 'city', 'submunicipality', 'barangay'] as $level) {
            $selection[$level] = $data['address_'.$level] ?? null;
            $selection[$level.'_psgc_code'] = $data['address_'.$level.'_psgc_code'] ?? null;
        }

        return $selection;
    }

    private function flatAddressFields(array $selection): array
    {
        $fields = [];
        foreach ($selection as $key => $value) {
            $fields['address_'.$key] = $value;
        }

        return $fields;
    }

    public function show(Patient $patient, PatientQrCodeService $qrCodes)
    {
        $patient = $this->patientService->findVisibleToOrFail(auth()->user(), $patient->id);
        $this->authorize('view', $patient);

        $canViewIdentityDocuments = auth()->user()->hasRole('registration', 'super-admin')
            || (auth()->user()->hasRole('patient') && $patient->user_id === auth()->id());

        $patient->load([
            'addresses',
            'emergencyContacts',
            'identifiers',
            'consents',
            'appointments.provider',
            'encounters.provider',
'erVisits.triageAssessments',
            'admissions.bedAssignments.bed.room.ward',
            'admissions.transfers',
            'admissions.discharge',
            'clinicalDocuments' => fn ($query) => $canViewIdentityDocuments
                ? $query
                : $query->whereNull('identifier_type'),
        ]);
        $patient->setRelation('consents', $this->consents->history($patient));

        $this->audit->viewPatient($patient->id);

        $mergeCandidates = [];
        if (auth()->user()->hasRole('registration', 'super-admin')) {
            $mergeCandidates = $this->patientService->findDuplicates([
                'first_name' => $patient->first_name,
                'last_name' => $patient->last_name,
                'date_of_birth' => $patient->date_of_birth?->toDateString(),
                'phone' => $patient->phone,
                'email' => $patient->email,
            ], $patient->id);
        }

        $referenceCodeQr = $patient->reference_code ? $qrCodes->dataUri($patient) : null;

        return view('patients.show', compact('patient', 'mergeCandidates', 'referenceCodeQr'));
    }

    public function merge(MergePatientRequest $request, Patient $patient, PatientMergeService $merges): RedirectResponse
    {
        $survivor = $merges->merge($patient, $request->validated(), $request->user());

        return redirect()->route('patients.show', $survivor)->with('success', 'Patient records merged. The original record and linked clinical history are retained.');
    }

    public function showVitals(Patient $patient)
    {
        $this->authorize('view', $patient);
        if (auth()->user()->hasAnyRole(['doctor', 'nurse', 'registration', 'admin', 'super-admin'])) {
            $this->audit->viewPatientClinicalData((int) $patient->id, 'vitals', (int) $patient->id);
        }
        $vitals = $patient->vitals()->with('encounter')->orderBy('recorded_at', 'desc')->paginate(20);
        return view('patients.partials.vitals', compact('patient', 'vitals'));
    }

    public function search(Request $request)
    {
        $term = $request->get('q');
        $this->authorize('viewAny', Patient::class);
        $patients = $this->patientService->search($request->user(), $term);

        return response()->json([
            'data' => $patients->items(),
            'meta' => [
                'current_page' => $patients->currentPage(),
                'last_page' => $patients->lastPage(),
                'total' => $patients->total(),
            ],
        ]);
    }

    public function profile()
    {
        $user = Auth::user();

        $patient = $user->patient()->with(['addresses', 'emergencyContacts', 'clinicalDocuments'])->first();

        if (! $patient) {
            $patient = $this->patientService->register([
                'user_id' => $user->id,
                'first_name' => $user->name,
                'last_name' => '',
                'date_of_birth' => now()->subYears(18)->toDateString(),
                'sex' => 'Other',
                'email' => $user->email,
                'lookup_code' => strtoupper(Str::random(8)),
                'pre_registration_status' => 'not_started',
            ], $user->id);
        }

        $consentTypes = config('consents.types');
        $hasDataPrivacyConsent = $this->consents->hasCurrentGrant($patient, 'DATA_PRIVACY');

        return view('patients.profile', compact('patient', 'consentTypes', 'hasDataPrivacyConsent'));
    }

    public function saveProfile(Request $request)
    {
        $user = $request->user();

        $patient = $user->patient()->first();
        $newPatient = ! $patient;
        if (! $patient) {
            $patient = $this->patientService->register([
                'user_id' => $user->id,
                'first_name' => $user->name,
                'last_name' => '',
                'date_of_birth' => now()->subYears(18)->toDateString(),
                'sex' => 'Other',
                'email' => $user->email,
                'lookup_code' => strtoupper(Str::random(8)),
                'pre_registration_status' => 'not_started',
            ], $user->id);
        }
        $needsDataPrivacyConsent = ! $this->consents->hasCurrentGrant($patient, 'DATA_PRIVACY');

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'date_of_birth' => ['required', 'date', 'before_or_equal:today'],
            'sex' => ['required', 'in:Male,Female,Other'],
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
            'civil_status' => ['nullable', 'string', 'max:50'],
            'nationality' => ['nullable', 'string', 'max:100'],
            'allergies' => ['nullable', 'string', 'max:2000'],
            'address' => ['nullable', 'array'],
            'address.line1' => ['nullable', 'string'],
            'address.region' => ['nullable', 'string', 'max:150'],
            'address.region_psgc_code' => ['nullable', 'string', 'regex:/^\d{10}$/'],
            'address.barangay' => ['nullable', 'string'],
            'address.barangay_psgc_code' => ['nullable', 'string', 'regex:/^\d{10}$/'],
            'address.city' => ['nullable', 'string'],
            'address.city_psgc_code' => ['nullable', 'string', 'regex:/^\d{10}$/'],
            'address.province' => ['nullable', 'string'],
            'address.province_psgc_code' => ['nullable', 'string', 'regex:/^\d{10}$/'],
            'address.submunicipality' => ['nullable', 'string'],
            'address.submunicipality_psgc_code' => ['nullable', 'string', 'regex:/^\d{10}$/'],
            'address.postal_code' => ['nullable', 'string'],
            'emergency_contact' => ['nullable', 'array'],
            'emergency_contact.name' => ['nullable', 'string'],
            'emergency_contact.relationship' => ['nullable', 'string'],
            'emergency_contact.phone' => ['nullable', 'string', new PhilippineMobilePhone],
            'data_privacy_consent' => $needsDataPrivacyConsent ? ['accepted'] : ['nullable', 'accepted'],
        ]);
        if (isset($data['address'])) {
            $data['address'] = $this->addressDataset->withoutEmptySelection($data['address']);
            $currentAddress = $patient->primaryAddress();
            if ($newPatient || $this->addressDataset->hasLocationChanged($currentAddress, $data['address'])) {
                $data['address'] = array_merge(
                    $data['address'],
                    $this->addressDataset->validateSelection($data['address'])
                );
            }
        }
        unset($data['data_privacy_consent']);

        DB::transaction(function () use ($patient, $data, $user, $needsDataPrivacyConsent): void {
            $this->patientService->update($patient, $data);
            if ($needsDataPrivacyConsent) {
                $this->consents->record($patient, 'DATA_PRIVACY', true, $user, 'self');
            }
            $patient->markPendingArrival();
        });

        return redirect()->route('dashboard')->with('success', 'Your pre-registration details have been saved. Your patient lookup code is '.$patient->lookup_code.'.');
    }

    public function lookup(Request $request)
    {
        $term = trim((string) $request->query('q', ''));
        $lookupCode = trim((string) $request->query('lookup_code', ''));

        if ($this->patientService->isPatientReferenceCodeInput($term !== '' ? $term : $lookupCode)) {
            $result = $this->patientService->resolvePatientReferenceCode(
                $request->user(),
                $term !== '' ? $term : $lookupCode
            );

            if (! in_array($result['status'], ['found', 'merged'], true) || ! $result['patient']) {
                return response()->json([
                    'data' => [],
                    'message' => 'No patient record found for this code.',
                ]);
            }

            return response()->json([
                'data' => [$this->registrationLookupPayload($result['patient'], $result['profile'])],
            ]);
        }

        $query = Patient::query()
            ->with(['addresses', 'emergencyContacts', 'preArrivalProfiles'])
            ->where(function ($q) use ($term, $lookupCode) {
                if ($lookupCode !== '') {
                    $q->orWhere('lookup_code', strtoupper($lookupCode));
                }

                if ($term !== '') {
                    $search = '%'.$term.'%';
                    $q->orWhere('first_name', 'like', $search)
                        ->orWhere('last_name', 'like', $search)
                        ->orWhere('email', 'like', $search)
                        ->orWhere('phone', 'like', $search)
                        ->orWhere('lookup_code', 'like', $search)
                        ->orWhereHas('preArrivalProfiles', function ($preArrival) use ($search) {
                            $preArrival->where('reference_code', 'like', $search);
                        });
                }
            });

        $patients = $query->limit(10)->get();

        return response()->json([
            'data' => $patients->map(function ($patient) use ($term, $lookupCode) {
                $referenceQuery = trim((string) ($lookupCode !== '' ? $lookupCode : $term));
                $matchingProfile = $referenceQuery !== ''
                    ? $patient->preArrivalProfiles()
                        ->whereRaw('UPPER(reference_code) = ?', [strtoupper($referenceQuery)])
                        ->first()
                    : null;

                $profile = $matchingProfile ?? $patient->preArrivalProfiles()->latest()->first();

                return $this->registrationLookupPayload($patient, $profile);
            }),
        ]);
    }

    private function registrationLookupPayload(Patient $patient, ?PreArrivalProfile $profile): array
    {
        $patient->loadMissing(['addresses', 'emergencyContacts']);
        $profile ??= $patient->preArrivalProfiles()->latest()->first();
        $address = $patient->addresses->first();
        $contact = $patient->emergencyContacts->first();

        return [
            'id' => $patient->id,
            'mrn' => $patient->mrn,
            'lookup_code' => $patient->lookup_code,
            'reference_code' => $patient->reference_code ?: $profile?->reference_code,
            'reference_code_type' => $patient->reference_code ? 'PRC' : ($profile?->reference_code ? 'PAC' : null),
            'first_name' => $profile?->first_name ?? $patient->first_name,
            'middle_name' => $profile?->middle_name ?? $patient->middle_name,
            'last_name' => $profile?->last_name ?? $patient->last_name,
            'suffix' => $profile?->suffix ?? $patient->suffix,
            'date_of_birth' => $profile?->date_of_birth?->format('Y-m-d') ?? $patient->date_of_birth?->format('Y-m-d'),
            'sex' => $profile?->sex ?? $patient->sex,
            'civil_status' => $profile?->civil_status ?? $patient->civil_status,
            'nationality' => $profile?->nationality ?? $patient->nationality,
            'phone' => $profile?->phone ?? $patient->phone,
            'email' => $profile?->email ?? $patient->email,
            'allergies' => $profile?->allergies ?? $patient->allergies,
            'address' => $address ? [
                'line1' => $profile?->address_line1 ?? $address?->line1,
                'barangay' => $profile?->address_barangay ?? $address?->barangay,
                'city' => $profile?->address_city ?? $address?->city,
                'province' => $profile?->address_province ?? $address?->province,
                'postal_code' => $profile?->address_postal ?? $address?->postal_code,
            ] : ($profile ? [
                'line1' => $profile->address_line1,
                'barangay' => $profile->address_barangay,
                'city' => $profile->address_city,
                'province' => $profile->address_province,
                'postal_code' => $profile->address_postal,
            ] : null),
            'emergency_contact' => $contact ? [
                'name' => $profile?->emergency_name ?? $contact?->name,
                'relationship' => $profile?->emergency_relationship ?? $contact?->relationship,
                'phone' => $profile?->emergency_phone ?? $contact?->phone,
            ] : ($profile ? [
                'name' => $profile->emergency_name,
                'relationship' => $profile->emergency_relationship,
                'phone' => $profile->emergency_phone,
            ] : null),
        ];
    }

    public function verify(Patient $patient)
    {
        $this->authorize('update', $patient);
        $this->patientService->verify($patient, auth()->id());
        return back()->with('success', 'Patient verified successfully.');
    }

    public function verifyIdentifier(
        VerifyPatientIdentifierRequest $request,
        Patient $patient,
        PatientIdentifierService $identifiers
    ): JsonResponse|RedirectResponse {
        $identifier = (string) $request->route('identifier');
        $verifiedPatient = $identifiers->verify($patient, $identifier, $request->user());

        if ($request->is('api/*') || $request->expectsJson()) {
            $verifiedAtColumn = PatientIdentifierService::verificationColumns()[$identifier][1];

            return response()->json([
                'success' => true,
                'message' => 'Patient identifier marked as verified.',
                'data' => [
                    'patient_id' => $patient->id,
                    'identifier_type' => $identifier,
                    'verified_by' => $request->user()->id,
                    'verified_at' => $verifiedPatient->{$verifiedAtColumn}?->toIso8601String(),
                ],
            ]);
        }

        return back()->with('success', 'Patient identifier marked as verified.');
    }

    public function completeTemporaryIdentity(
        CompleteTemporaryIdentityRequest $request,
        Patient $patient
    ): JsonResponse|RedirectResponse {
        $completedPatient = $this->patientService->completeTemporaryIdentity(
            $patient,
            $request->validated(),
            $request->user()?->id
        );

        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Temporary patient identity completed.',
                'data' => $completedPatient,
            ]);
        }

        return back()->with('success', 'Temporary patient identity completed.');
    }
}
