<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateAdmissionRequest;
use App\Http\Requests\RequestBillingClearanceFormRequest;
use App\Http\Requests\UpdateAdmissionGuaranteeRequest;
use App\Models\Admission;
use App\Services\AdmissionService;
use App\Services\BillingClearanceRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AdmissionApiController extends Controller
{
    public function __construct(protected AdmissionService $admissionService) {}

    /**
     * List admissions.
     */
    public function index(Request $request): JsonResponse
    {
        abort_unless(Gate::allows('view-admissions'), 403, 'You are not authorized.');

        $query = Admission::with('patient', 'attendingProvider', 'activeBedAssignment.bed', 'billingClearanceRequests');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('patient_id')) {
            $query->where('patient_id', $request->patient_id);
        }

        $admissions = $query->orderByDesc('created_at')->paginate(15);

        return response()->json([
            'success' => true,
            'message' => 'Admissions retrieved successfully.',
            'data' => $admissions->items(),
            'meta' => ['current_page' => $admissions->currentPage(), 'last_page' => $admissions->lastPage(), 'total' => $admissions->total()],
        ]);
    }

    /**
     * Create an admission request.
     */
    public function store(CreateAdmissionRequest $request): JsonResponse
    {
        $admission = $this->admissionService->create($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Admission request created successfully.',
            'data' => $admission->load('patient'),
        ], 201);
    }

    /**
     * Show an admission.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $admission = Admission::with('patient', 'attendingProvider', 'erVisit', 'bedAssignments.bed', 'bedReservations', 'transfers', 'discharge', 'billingClearanceRequests')
            ->findOrFail($id);
        if ($request->user()->hasRole('registration', 'super-admin')) {
            $admission->makeVisible(['guarantor_name', 'guarantor_relationship', 'guarantor_contact', 'loa_reference']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Admission retrieved successfully.',
            'data' => $admission,
        ]);
    }

    public function updateGuaranteeDetails(UpdateAdmissionGuaranteeRequest $request, Admission $admission): JsonResponse
    {
        $admission = $this->admissionService->updateGuaranteeDetails($admission, $request->validated(), $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Admission guarantor and LOA reference details saved.',
            'data' => [
                'admission_id' => $admission->id,
                'guarantor_name' => $admission->guarantor_name,
                'guarantor_relationship' => $admission->guarantor_relationship,
                'guarantor_contact' => $admission->guarantor_contact,
                'loa_reference' => $admission->loa_reference,
            ],
        ]);
    }

    public function requestBillingClearance(
        RequestBillingClearanceFormRequest $request,
        Admission $admission,
        BillingClearanceRequestService $clearances
    ): JsonResponse {
        $clearanceRequest = $clearances->request($admission, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Billing clearance request recorded.',
            'data' => [
                'id' => $clearanceRequest->id,
                'admission_id' => $clearanceRequest->admission_id,
                'status' => $clearanceRequest->status,
                'requested_at' => $clearanceRequest->requested_at,
            ],
        ], $clearanceRequest->wasRecentlyCreated ? 201 : 200);
    }
}
