<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Admission;
use App\Models\Bed;
use App\Models\BedAssignment;
use App\Services\AdmissionService;
use App\Services\BedManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class BedApiController extends Controller
{
    public function __construct(
        protected BedManagementService $bedManagement,
        protected AdmissionService $admissionService
    ) {}

    /**
     * List beds with optional filters.
     */
    public function index(Request $request): JsonResponse
    {
        abort_unless(Gate::allows('view-beds'), 403, 'You are not authorized.');

        $query = Bed::with('room.ward', 'activeAssignment', 'activeReservation');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('ward_id')) {
            $query->whereHas('room', fn ($q) => $q->where('ward_id', $request->ward_id));
        }

        $beds = $query->orderBy('room_id')->orderBy('number')->paginate(20);

        return response()->json([
            'success' => true,
            'message' => 'Beds retrieved successfully.',
            'data' => $beds->items(),
            'meta' => ['current_page' => $beds->currentPage(), 'last_page' => $beds->lastPage(), 'total' => $beds->total()],
        ]);
    }

    /**
     * List available beds.
     */
    public function available(Request $request): JsonResponse
    {
        abort_unless(Gate::allows('view-beds'), 403, 'You are not authorized.');

        $beds = $this->bedManagement->availableBeds($request->integer('ward_id'));

        return response()->json([
            'success' => true,
            'message' => 'Available beds retrieved successfully.',
            'data' => $beds,
        ]);
    }

    /**
     * Show a single bed.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $bed = Bed::with('room.ward', 'activeAssignment', 'activeReservation', 'assignments')->findOrFail($id);

        return response()->json([
            'success' => true,
            'message' => 'Bed retrieved successfully.',
            'data' => $bed,
        ]);
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        abort_unless(Gate::allows('update-bed-status'), 403, 'You are not authorized.');

        $data = $request->validate(['status' => ['required', 'string']]);
        $bed = Bed::query()->findOrFail($id);
        $bed = $this->bedManagement->setStatus($bed, $data['status']);

        return response()->json([
            'success' => true,
            'message' => 'Bed status updated successfully.',
            'data' => $bed,
        ]);
    }

    /**
     * Reserve a bed for an admission.
     */
    public function reserve(Request $request, int $id): JsonResponse
    {
        abort_unless(Gate::allows('assign-beds'), 403, 'You are not authorized.');

        $data = $request->validate([
            'admission_id' => ['required', 'exists:admissions,id'],
            'expires_in_minutes' => ['nullable', 'integer', 'min:5', 'max:1440'],
        ]);

        $admission = Admission::findOrFail($data['admission_id']);

        $reservation = $this->bedManagement->reserveBed($admission, $id, $data['expires_in_minutes'] ?? null);

        return response()->json([
            'success' => true,
            'message' => 'Bed reserved successfully.',
            'data' => $reservation->load('bed'),
        ], 201);
    }

    public function cancelReservation(Request $request, int $id, int $reservationId): JsonResponse
    {
        abort_unless(Gate::allows('assign-beds'), 403, 'You are not authorized.');

        $reservation = $this->bedManagement->cancelReservation($reservationId, $id);

        return response()->json([
            'success' => true,
            'message' => 'Bed reservation cancelled successfully.',
            'data' => $reservation,
        ]);
    }

    /**
     * Assign a bed to an admission.
     */
    public function assign(Request $request, int $id): JsonResponse
    {
        abort_unless(Gate::allows('assign-beds'), 403, 'You are not authorized.');

        $data = $request->validate([
            'admission_id' => ['required', 'exists:admissions,id'],
            'reservation_id' => ['nullable', 'exists:bed_reservations,id'],
        ]);

        $admission = Admission::findOrFail($data['admission_id']);

        $this->admissionService->admit($admission, $id, $data['reservation_id'] ?? null);
        $assignment = $admission->fresh()->activeBedAssignment;

        return response()->json([
            'success' => true,
            'message' => 'Bed assigned successfully.',
            'data' => $assignment->load('bed', 'admission'),
        ], 201);
    }

    /**
     * Release a bed.
     */
    public function release(Request $request, int $id): JsonResponse
    {
        abort_unless(Gate::allows('assign-beds'), 403, 'You are not authorized.');

        $data = $request->validate([
            'admission_id' => [
                'required',
                'exists:admissions,id',
                Rule::exists('bed_assignments', 'admission_id')
                    ->where('bed_id', $id)
                    ->where('status', BedAssignment::STATUS_ACTIVE),
            ],
        ], [
            'admission_id.exists' => 'The admission does not have an active assignment for this bed.',
        ]);

        $admission = Admission::findOrFail($data['admission_id']);

        $bed = $this->bedManagement->releaseBed($admission, $id);

        return response()->json([
            'success' => true,
            'message' => 'Bed released successfully.',
            'data' => $bed,
        ]);
    }
}
