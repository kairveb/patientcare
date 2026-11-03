<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\Waitlist;
use App\Policies\WaitlistPolicy;
use App\Services\AppointmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WaitlistApiController extends Controller
{
    public function __construct(
        protected AppointmentService $appointmentService,
        protected WaitlistPolicy $waitlistPolicy
    ) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('viewAny', Waitlist::class), 403, 'You are not authorized.');

        $query = Waitlist::with(['patient', 'provider', 'department', 'appointmentType']);
        $query = $this->waitlistPolicy->scopeQuery($request->user(), $query);

        if (! $request->user()->isPatient() && $request->filled('patient_id')) {
            $query->where('patient_id', $request->patient_id);
        }

        if ($request->filled('provider_id')) {
            $query->where('provider_id', $request->provider_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('department_id')) {
            $query->where('department_id', $request->department_id);
        }

        $waitlists = $query->orderByDesc('created_at')->paginate(15);

        return response()->json([
            'success' => true,
            'message' => 'Waitlists retrieved successfully.',
            'data' => $waitlists->items(),
            'meta' => [
                'current_page' => $waitlists->currentPage(),
                'last_page' => $waitlists->lastPage(),
                'total' => $waitlists->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'patient_id' => ['required', 'exists:patients,id'],
            'provider_id' => ['nullable', 'exists:providers,id'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'appointment_type_id' => ['nullable', 'exists:appointment_types,id'],
            'preferred_date' => ['nullable', 'date'],
            'status' => ['sometimes', 'in:WAITING'],
        ]);

        $patient = Patient::findOrFail($data['patient_id']);
        abort_unless($request->user()->can('create', [Waitlist::class, $patient]), 403, 'You are not authorized.');
        $data['status'] = Waitlist::STATUS_WAITING;

        $waitlist = $this->appointmentService->addToWaitlist($data);

        return response()->json([
            'success' => true,
            'message' => 'Patient added to waitlist successfully.',
            'data' => $waitlist,
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $waitlist = Waitlist::findOrFail($id);
        abort_unless($request->user()->can('update', $waitlist), 403, 'You are not authorized.');

        if ($request->user()->isPatient()) {
            $data = $request->validate([
                'status' => ['required', 'in:REMOVED'],
            ]);
            $waitlist->update($data);

            return response()->json([
                'success' => true,
                'message' => 'Waitlist entry cancelled successfully.',
                'data' => $waitlist,
            ]);
        }

        if ($request->exists('status')) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'status' => 'Waitlist status is managed by the system.',
            ]);
        }

        $data = $request->validate([
            'provider_id' => ['nullable', 'exists:providers,id'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'appointment_type_id' => ['nullable', 'exists:appointment_types,id'],
            'preferred_date' => ['nullable', 'date'],
        ]);

        $waitlist->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Waitlist entry updated successfully.',
            'data' => $waitlist,
        ]);
    }
}
