<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOpdVitalsRequest;
use App\Models\Encounter;
use App\Policies\EncounterPolicy;
use App\Rules\Icd10Code;
use App\Services\AdmissionService;
use App\Services\EncounterService;
use App\Services\PrescriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class EncounterApiController extends Controller
{
    public function __construct(
        protected EncounterService $encounterService,
        protected EncounterPolicy $encounterPolicy
    ) {}

    /**
     * List encounters.
     */
    public function index(Request $request): JsonResponse
    {
        abort_unless(Gate::allows('viewAny', Encounter::class), 403, 'You are not authorized.');

        $query = Encounter::with('patient', 'provider', 'appointment');
        $query = $this->encounterPolicy->scopeQuery($request->user(), $query);

        if (! $request->user()->isPatient() && $request->filled('patient_id')) {
            $query->where('patient_id', $request->patient_id);
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        $encounters = $query->orderByDesc('started_at')->paginate(15);

        return response()->json([
            'success' => true,
            'message' => 'Encounters retrieved successfully.',
            'data' => $encounters->items(),
            'meta' => ['current_page' => $encounters->currentPage(), 'last_page' => $encounters->lastPage(), 'total' => $encounters->total()],
        ]);
    }

    /**
     * Create an encounter.
     */
    public function store(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()->hasRole('doctor') && $request->user()->hasPermission('create-encounters'),
            403,
            'Only an authorized doctor can create encounters.'
        );

        $vitalFields = array_keys(StoreOpdVitalsRequest::sharedRules());
        $hasVitals = collect($vitalFields)->contains(fn (string $field): bool => $request->filled("vitals.{$field}"));
        $vitalRules = [];
        if ($hasVitals) {
            foreach (StoreOpdVitalsRequest::sharedRules() as $field => $rules) {
                $vitalRules["vitals.{$field}"] = $rules;
            }
        }

        $data = $request->validate(array_merge([
            'patient_id' => ['required', 'exists:patients,id'],
            'provider_id' => ['required', 'exists:providers,id'],
            'appointment_id' => ['nullable', 'exists:appointments,id'],
            'type' => ['required', 'in:OUTPATIENT,TELEHEALTH,EMERGENCY'],
            'chief_complaint' => ['nullable', 'string'],
            'history_of_present_illness' => ['nullable', 'string', 'max:30000'],
            'physical_examination' => ['nullable', 'string', 'max:30000'],
            'primary_diagnosis_code' => ['nullable', 'string', 'max:20', app(Icd10Code::class)],
            'primary_diagnosis_description' => ['nullable', 'string', 'max:255'],
            'assessment' => ['nullable', 'string'],
            'plan' => ['nullable', 'string'],
            'follow_up_date' => ['nullable', 'date'],
            'vitals' => ['nullable', 'array:blood_pressure,heart_rate,respiratory_rate,temperature,spo2,height_cm,weight,pain_score'],
            'notes' => ['nullable', 'string'],
        ], $vitalRules));
        if (! $hasVitals) {
            $data['vitals'] = null;
        }

        abort_unless(
            $request->user()->provider?->id === (int) $data['provider_id'],
            403,
            'Doctors may only create encounters for their own provider profile.'
        );

        $encounter = $this->encounterService->create($data);

        return response()->json([
            'success' => true,
            'message' => 'Encounter created successfully.',
            'data' => $encounter->load('appointment', 'vitals', 'notes'),
        ], 201);
    }

    /**
     * Show an encounter.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $encounter = Encounter::findOrFail($id);
        abort_unless(Gate::allows('view', $encounter), 403, 'You are not authorized.');
        $encounter->load('patient', 'provider', 'appointment', 'vitals', 'notes', 'prescriptions.prescribedBy');

        return response()->json([
            'success' => true,
            'message' => 'Encounter retrieved successfully.',
            'data' => $encounter,
        ]);
    }

    public function prescribe(Request $request, Encounter $encounter, PrescriptionService $prescriptions): JsonResponse
    {
        abort_unless(Gate::allows('update', $encounter), 403, 'You are not authorized to prescribe for this encounter.');

        $data = $request->validate([
            'medication_name' => ['required', 'string', 'max:255'],
            'dosage' => ['required', 'string', 'max:255'],
            'instructions' => ['required', 'string', 'max:5000'],
        ]);

        $prescription = $prescriptions->createForEncounter($encounter, $data, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Prescription saved successfully.',
            'data' => $prescription->load(['patient', 'encounter', 'prescribedBy']),
        ], 201);
    }

    public function complete(Request $request, Encounter $encounter): JsonResponse
    {
        abort_unless(Gate::allows('complete', $encounter), 403, 'Only the assigned doctor may complete this encounter.');

        $data = $request->validate([
            'history_of_present_illness' => ['required', 'string', 'max:30000'],
            'physical_examination' => ['required', 'string', 'max:30000'],
            'primary_diagnosis_code' => ['required', 'string', 'max:20', app(Icd10Code::class)],
            'primary_diagnosis_description' => ['required', 'string', 'max:255'],
            'assessment' => ['required', 'string', 'max:30000'],
            'plan' => ['required', 'string', 'max:30000'],
            'follow_up_date' => ['nullable', 'date'],
        ]);

        $encounter = $this->encounterService->complete($encounter, $data, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Encounter completed.',
            'data' => $encounter->load('appointment', 'vitals'),
        ]);
    }

    public function requestAdmission(Request $request, Encounter $encounter, AdmissionService $admissions): JsonResponse
    {
        abort_unless(Gate::allows('update', $encounter) && $request->user()->hasRole('doctor'), 403, 'Only the assigned doctor may request admission.');

        $data = $request->validate(['reason' => ['required', 'string', 'max:5000']]);
        $admission = $admissions->requestFromEncounter($encounter, $request->user(), $data['reason']);

        return response()->json([
            'success' => true,
            'message' => 'Admission request sent to Bed Management.',
            'data' => $admission,
        ], 201);
    }
}
