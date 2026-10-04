<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CompleteTemporaryIdentityRequest;
use App\Http\Requests\MergePatientRequest;
use App\Models\Patient;
use App\Rules\PhilHealthNumber;
use App\Rules\PhilippineMobilePhone;
use App\Services\AuditLogService;
use App\Services\PatientMergeService;
use App\Services\PatientQrCodeService;
use App\Services\PatientService;
use App\Services\PatientConsentService;
use App\Services\PhilippineAddressDataset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\DB;

class PatientApiController extends Controller
{
    public function __construct(
        protected PatientService $patientService,
        protected PatientConsentService $consents,
        protected AuditLogService $audit,
        protected PhilippineAddressDataset $addressDataset,
    )
    {
    }

    /**
     * List patients with pagination.
     */
    public function index(Request $request): JsonResponse
    {
        abort_unless(Gate::allows('view-patients'), 403, 'You are not authorized to view patients.');

        $patients = $this->patientService->search(
            $request->user(),
            $request->query('q'),
            $request->only(['date_of_birth', 'sex'])
        );

        return response()->json([
            'success' => true,
            'message' => 'Patients retrieved successfully.',
            'data' => collect($patients->items())
                ->map(fn (Patient $patient) => $this->patientPayload($patient, $request))
                ->all(),
            'meta' => [
                'current_page' => $patients->currentPage(),
                'last_page' => $patients->lastPage(),
                'total' => $patients->total(),
            ],
        ]);
    }

    /**
     * Register a new patient.
     */
    public function store(Request $request): JsonResponse
    {
        abort_unless(Gate::allows('create-patients'), 403, 'You are not authorized to create patients.');

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'suffix' => ['nullable', 'string', 'max:20'],
            'date_of_birth' => ['required', 'date', 'before:today'],
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
            'civil_status' => ['nullable', 'string', 'max:30'],
            'nationality' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30', new PhilippineMobilePhone],
            'email' => ['nullable', 'email', 'max:255'],
            'allergies' => ['nullable', 'string'],
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
            'data_privacy_consent' => ['accepted'],
            'force_duplicate' => ['sometimes', 'boolean'],
        ]);
        if (isset($data['address'])) {
            $data['address'] = $this->addressDataset->withoutEmptySelection($data['address']);
            $data['address'] = array_merge(
                $data['address'],
                $this->addressDataset->validateSelection($data['address'])
            );
        }

        $duplicates = $this->patientService->findDuplicates($data);
        $forceDuplicate = (bool) ($data['force_duplicate'] ?? false);
        unset($data['force_duplicate']);

        if ($duplicates !== [] && ! $forceDuplicate) {
            return response()->json([
                'success' => false,
                'message' => 'Possible duplicate patient records were found. Review them before continuing.',
                'error_code' => 'POSSIBLE_DUPLICATE_PATIENTS',
                'candidates' => $duplicates,
            ], 409);
        }

        unset($data['data_privacy_consent']);
        $patient = DB::transaction(function () use ($data, $request, $duplicates, $forceDuplicate) {
            if ($duplicates !== [] && $forceDuplicate) {
                $this->audit->log('DUPLICATE_REGISTRATION_OVERRIDE', 'patient', null, 'SUCCESS', [
                    'candidate_ids' => array_column($duplicates, 'id'),
                    'similarity_scores' => array_column($duplicates, 'similarity_score', 'id'),
                ], $request->user()->id);
            }

            $patient = $this->patientService->register($data, $request->user()->id);
            $this->consents->record($patient, 'DATA_PRIVACY', true, $request->user(), 'staff');

            return $patient;
        });

        return response()->json([
            'success' => true,
            'message' => 'Patient registered successfully.',
            'data' => $this->patientPayload($patient->load('addresses', 'emergencyContacts'), $request),
        ], 201);
    }

    public function merge(
        MergePatientRequest $request,
        Patient $patient,
        PatientMergeService $merges
    ): JsonResponse {
        $survivor = $merges->merge($patient, $request->validated(), $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Patient records merged. The original record and linked clinical history are retained.',
            'data' => $this->patientPayload($survivor, $request),
        ]);
    }

    /**
     * Show a single patient.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $patient = $this->patientService->findVisibleToOrFail($request->user(), $id);

        abort_unless(Gate::allows('view', $patient), 403, 'You are not authorized to view this patient.');

        return response()->json([
            'success' => true,
            'message' => 'Patient retrieved successfully.',
            'data' => $this->patientPayload($patient, $request),
        ]);
    }

    public function lookupReferenceCode(Request $request, PatientQrCodeService $qrCodes): JsonResponse
    {
        $data = $request->validate([
            'reference_code' => ['nullable', 'string'],
        ], [
            'reference_code.string' => 'No patient record found for this code.',
        ]);
        $result = $this->patientService->resolvePatientReferenceCode($request->user(), $data['reference_code'] ?? null);

        if (! in_array($result['status'], ['found', 'merged'], true) || ! $result['patient']) {
            return response()->json([
                'success' => false,
                'message' => 'No patient record found for this code.',
            ], 404);
        }

        $message = $result['status'] === 'merged'
            ? 'This code belongs to a merged record. The surviving patient record is shown.'
            : 'Patient retrieved successfully.';

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => [
                ...$this->patientPayload($result['patient'], $request),
                'reference_code_qr' => $qrCodes->dataUri($result['patient']),
            ],
        ]);
    }

    public function myPatientId(Request $request, PatientQrCodeService $qrCodes): JsonResponse
    {
        $patient = $request->user()->patient()->first();
        abort_unless($patient, 404, 'Patient profile not found.');
        abort_if(blank($patient->reference_code), 409, 'Patient reference code is not assigned yet.');

        return response()->json([
            'success' => true,
            'data' => [
                'reference_code' => $patient->reference_code,
                'qr_code' => $qrCodes->dataUri($patient),
            ],
        ]);
    }

    /**
     * Update a patient.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $patient = Patient::findOrFail($id);

        abort_unless(Gate::allows('update', $patient), 403, 'You are not authorized to update this patient.');

        $data = $request->validate([
            'first_name' => ['sometimes', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['sometimes', 'string', 'max:255'],
            'suffix' => ['nullable', 'string', 'max:20'],
            'date_of_birth' => ['sometimes', 'date', 'before:today'],
            'sex' => ['sometimes', 'in:Male,Female,Other'],
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
            'civil_status' => ['nullable', 'string', 'max:30'],
            'nationality' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30', new PhilippineMobilePhone],
            'email' => ['nullable', 'email', 'max:255'],
            'allergies' => ['nullable', 'string'],
            'address' => ['nullable', 'array'],
            'address.line1' => ['nullable', 'string'],
            'address.region' => ['nullable', 'string', 'max:150'],
            'address.region_psgc_code' => ['nullable', 'string', 'regex:/^\d{10}$/'],
            'address.province' => ['nullable', 'string', 'max:150'],
            'address.province_psgc_code' => ['nullable', 'string', 'regex:/^\d{10}$/'],
            'address.city' => ['nullable', 'string', 'max:150'],
            'address.city_psgc_code' => ['nullable', 'string', 'regex:/^\d{10}$/'],
            'address.submunicipality' => ['nullable', 'string', 'max:150'],
            'address.submunicipality_psgc_code' => ['nullable', 'string', 'regex:/^\d{10}$/'],
            'address.barangay' => ['nullable', 'string', 'max:150'],
            'address.barangay_psgc_code' => ['nullable', 'string', 'regex:/^\d{10}$/'],
            'address.postal_code' => ['nullable', 'string', 'max:20'],
            'emergency_contact' => ['nullable', 'array'],
        ]);
        if (isset($data['address'])) {
            $data['address'] = $this->addressDataset->withoutEmptySelection($data['address']);
            $currentAddress = $patient->primaryAddress();
            if ($this->addressDataset->hasLocationChanged($currentAddress, $data['address'])) {
                $data['address'] = array_merge(
                    $data['address'],
                    $this->addressDataset->validateSelection($data['address'])
                );
            }
        }

        $patient = $this->patientService->update($patient, $data);

        return response()->json([
            'success' => true,
            'message' => 'Patient updated successfully.',
            'data' => $this->patientPayload($patient->load('addresses', 'emergencyContacts'), $request),
        ]);
    }

    public function completeTemporaryIdentity(
        CompleteTemporaryIdentityRequest $request,
        Patient $patient
    ): JsonResponse {
        $patient = $this->patientService->completeTemporaryIdentity(
            $patient,
            $request->validated(),
            $request->user()->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Temporary patient identity completed.',
            'data' => $patient,
        ]);
    }

    /**
     * Delete a patient.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $patient = Patient::findOrFail($id);

        abort_unless(Gate::allows('delete', $patient), 403, 'You are not authorized to delete this patient.');

        $patient->delete();

        return response()->json([
            'success' => true,
            'message' => 'Patient deleted successfully.',
        ]);
    }

    /**
     * Search patients.
     */
    public function search(Request $request, string $term): JsonResponse
    {
        abort_unless(Gate::allows('view-patients'), 403, 'You are not authorized to view patients.');

        $patients = $this->patientService->search($request->user(), $term);

        return response()->json([
            'success' => true,
            'message' => 'Patients retrieved successfully.',
            'data' => collect($patients->items())
                ->map(fn (Patient $patient) => $this->patientPayload($patient, $request))
                ->all(),
            'meta' => [
                'current_page' => $patients->currentPage(),
                'last_page' => $patients->lastPage(),
                'total' => $patients->total(),
            ],
        ]);
    }

    private function patientPayload(Patient $patient, Request $request): array
    {
        $data = $patient->toArray();

        $user = $request->user();
        if ($user->hasRole('registration', 'super-admin')
            || ($user->hasRole('patient') && $patient->user_id === $user->id)) {
            foreach ([
                'philhealth_number',
                'senior_id_number',
                'pwd_id_number',
                'hmo_name',
                'hmo_member_id',
                'guarantor_name',
                'guarantor_relationship',
                'guarantor_contact',
                'philhealth_verified_by',
                'philhealth_verified_at',
                'senior_id_verified_by',
                'senior_id_verified_at',
                'pwd_id_verified_by',
                'pwd_id_verified_at',
                'hmo_member_verified_by',
                'hmo_member_verified_at',
            ] as $field) {
                $data[$field] = $patient->{$field};
            }
        }

        if ($user->hasRole('doctor', 'nurse')) {
            unset($data['insurance_number']);
        }

        return $data;
    }
}
