<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateUnknownErArrivalRequest;
use App\Models\ErQueue;
use App\Models\ErVisit;
use App\Services\PatientService;
use App\Services\TriageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class EmergencyApiController extends Controller
{
    public function __construct(
        protected TriageService $triageService,
        protected PatientService $patients
    ) {}

    /**
     * Get the ER queue.
     */
    public function queue(Request $request): JsonResponse
    {
        abort_unless(Gate::allows('view-er'), 403, 'You are not authorized.');

        $query = ErQueue::with('erVisit.patient')
            ->orderByRaw("CASE priority
                WHEN 'Level 1' THEN 1
                WHEN 'Level 2' THEN 2
                WHEN 'Level 3' THEN 3
                WHEN 'Level 4' THEN 4
                WHEN 'Level 5' THEN 5
                ELSE 99
            END")
            ->orderBy('queued_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $queue = $query->paginate(20);

        return response()->json([
            'success' => true,
            'message' => 'ER queue retrieved successfully.',
            'data' => $queue->items(),
            'meta' => ['current_page' => $queue->currentPage(), 'last_page' => $queue->lastPage(), 'total' => $queue->total()],
        ]);
    }

    /**
     * Register an ER visit (arrival).
     */
    public function storeVisit(Request $request): JsonResponse
    {
        abort_unless(Gate::allows('create-er-visits'), 403, 'You are not authorized.');

        $data = $request->validate([
            'patient_id' => ['nullable', 'required_without:reference_code', 'exists:patients,id'],
            'reference_code' => ['nullable', 'required_without:patient_id', 'string', 'max:255'],
            'arrived_at' => ['nullable', 'date'],
            'arrival_method' => ['nullable', 'string', 'max:100'],
            'chief_complaint' => ['required', 'string'],
            'referral_details' => ['nullable', 'string'],
        ], [
            'reference_code.required_without' => 'No patient record found for this code.',
            'reference_code.string' => 'No patient record found for this code.',
            'reference_code.max' => 'No patient record found for this code.',
        ]);

        if (filled($data['reference_code'] ?? null)) {
            $lookup = $this->patients->lookupByReferenceCode(
                $request->user(),
                $data['reference_code'],
                isset($data['patient_id']) ? (int) $data['patient_id'] : null
            );
            if ($lookup['status'] !== 'found' || ! $lookup['patient']) {
                return response()->json([
                    'success' => false,
                    'message' => 'No patient record found for this code.',
                ], 404);
            }
            $data['patient_id'] = $lookup['patient']->id;
        }

        unset($data['reference_code']);
        $visit = $this->triageService->registerErVisit($data);

        return response()->json([
            'success' => true,
            'message' => 'ER visit registered successfully.',
            'data' => $visit->load('patient'),
        ], 201);
    }

    public function storeUnknownVisit(CreateUnknownErArrivalRequest $request): JsonResponse
    {
        $visit = $this->triageService->registerUnknownErVisit($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Temporary ER identity and visit registered.',
            'data' => $visit,
        ], 201);
    }

    /**
     * Show an ER visit.
     */
    public function showVisit(Request $request, int $id): JsonResponse
    {
        $visit = ErVisit::findOrFail($id);
        abort_unless($request->user()->can('view', $visit), 403, 'You are not authorized.');

        if ($request->user()->hasRole('registration') && ! $request->user()->isSuperAdmin()) {
            $visit->load(['patient:id,mrn,first_name,middle_name,last_name,suffix,date_of_birth,sex']);

            return response()->json([
                'success' => true,
                'message' => 'ER visit retrieved successfully.',
                'data' => [
                    'id' => $visit->id,
                    'visit_number' => $visit->visit_number,
                    'patient_id' => $visit->patient_id,
                    'arrived_at' => $visit->arrived_at,
                    'arrival_method' => $visit->arrival_method,
                    'status' => $visit->status,
                    'created_at' => $visit->created_at,
                    'updated_at' => $visit->updated_at,
                    'patient' => $visit->patient,
                ],
            ]);
        }

        $visit->load([
            'patient',
            'triage',
            'triageAssessments',
            'triageReassessments',
            'queue',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'ER visit retrieved successfully.',
            'data' => $visit,
        ]);
    }
}
