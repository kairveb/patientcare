<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Policies\AppointmentPolicy;
use App\Services\AppointmentService;
use App\Services\PatientService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AppointmentApiController extends Controller
{
    public function __construct(
        protected AppointmentService $appointmentService,
        protected PatientService $patients,
        protected AppointmentPolicy $appointmentPolicy
    ) {}

    /**
     * List appointments.
     */
    public function index(Request $request): JsonResponse
    {
        abort_unless(Gate::allows('view-appointments'), 403, 'You are not authorized.');

        $query = Appointment::with('patient', 'provider', 'department', 'appointmentType')
            ->orderByDesc('starts_at');
        $query = $this->appointmentPolicy->scopeQuery($request->user(), $query);

        if ($request->boolean('upcoming')) {
            $query->where('starts_at', '>=', now())
                ->whereIn('status', ['PENDING', 'CONFIRMED', 'CHECKED-IN']);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('provider_id')) {
            $query->where('provider_id', $request->provider_id);
        }

        $appointments = $query->paginate(15);

        return response()->json([
            'success' => true,
            'message' => 'Appointments retrieved successfully.',
            'data' => $appointments->items(),
            'meta' => [
                'current_page' => $appointments->currentPage(),
                'last_page' => $appointments->lastPage(),
                'total' => $appointments->total(),
            ],
        ]);
    }

    /**
     * Book an appointment.
     */
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('book', Appointment::class), 403, 'You are not authorized.');

        $data = $request->validate([
            'patient_id' => ['required', 'exists:patients,id'],
            'provider_id' => ['required', 'exists:providers,id'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'appointment_type_id' => ['nullable', 'exists:appointment_types,id'],
            'starts_at' => ['required', 'date'],
            'duration' => ['nullable', 'integer', 'min:5', 'max:240'],
            'reason' => ['nullable', 'string'],
            'appointment_slot_id' => ['nullable', 'exists:appointment_slots,id'],
        ]);

        if ($request->user()->isPatient()) {
            $data['patient_id'] = $request->user()->patient()->firstOrFail()->id;
        }

        $appointment = $this->appointmentService->book($data, $request->user()->id);

        return response()->json([
            'success' => true,
            'message' => 'Appointment booked successfully.',
            'data' => $appointment->load('patient', 'provider', 'appointmentType'),
        ], 201);
    }

    /**
     * Show an appointment.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $appointment = Appointment::with('patient', 'provider', 'department', 'appointmentType', 'encounter', 'telehealthSession')
            ->findOrFail($id);

        abort_unless(Gate::allows('view', $appointment), 403, 'You are not authorized.');

        return response()->json([
            'success' => true,
            'message' => 'Appointment retrieved successfully.',
            'data' => $appointment,
        ]);
    }

    /**
     * Update an appointment (partial).
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $appointment = Appointment::findOrFail($id);

        abort_unless(Gate::allows('manageLifecycle', $appointment), 403, 'You are not authorized.');

        $data = $request->validate([
            'status' => ['sometimes', 'in:PENDING,CONFIRMED,CHECKED-IN,IN-CONSULTATION,COMPLETED,CANCELLED,NO-SHOW'],
        ]);

        if (!empty($data['status'])) {
            $appointment = $this->appointmentService->transitionStatus($appointment, $data['status'], $request->user()->id);
        }

        return response()->json([
            'success' => true,
            'message' => 'Appointment updated successfully.',
            'data' => $appointment,
        ]);
    }

    /**
     * Cancel an appointment.
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $appointment = Appointment::findOrFail($id);

        abort_unless($request->user()->can('cancel', $appointment), 403, 'You are not authorized.');

        $appointment = $this->appointmentService->cancel($appointment, $request->user()->id, $request->input('reason'));

        return response()->json([
            'success' => true,
            'message' => 'Appointment cancelled successfully.',
            'data' => $appointment,
        ]);
    }

    /**
     * Reschedule an appointment.
     */
    public function reschedule(Request $request, int $id): JsonResponse
    {
        $appointment = Appointment::findOrFail($id);

        abort_unless($request->user()->can('reschedule', $appointment), 403, 'You are not authorized.');

        $data = $request->validate([
            'starts_at' => ['required', 'date'],
            'duration' => ['nullable', 'integer', 'min:5', 'max:240'],
        ]);

        $appointment = $this->appointmentService->reschedule($appointment, $data, $request->user()->id);

        return response()->json([
            'success' => true,
            'message' => 'Appointment rescheduled successfully.',
            'data' => $appointment,
        ]);
    }

    /**
     * Check in a patient to an appointment.
     */
    public function checkIn(Request $request, int $id): JsonResponse
    {
        $appointment = Appointment::findOrFail($id);

        abort_unless(Gate::allows('manageLifecycle', $appointment), 403, 'You are not authorized.');

        $data = $request->validate([
            'reference_code' => ['nullable', 'string', 'max:255'],
        ], [
            'reference_code.string' => 'No patient record found for this code.',
            'reference_code.max' => 'No patient record found for this code.',
        ]);
        if (filled($data['reference_code'] ?? null)) {
            $lookup = $this->patients->lookupByReferenceCode(
                $request->user(),
                $data['reference_code'],
                (int) $appointment->patient_id
            );
            if ($lookup['status'] !== 'found') {
                return response()->json([
                    'success' => false,
                    'message' => 'No patient record found for this code.',
                ], 404);
            }
        }

        $appointment = $this->appointmentService->checkIn($appointment, $request->user()->id);

        return response()->json([
            'success' => true,
            'message' => 'Patient checked in successfully.',
            'data' => $appointment,
        ]);
    }

    /**
     * Delete an appointment.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $appointment = Appointment::findOrFail($id);

        abort_unless(Gate::allows('delete', $appointment), 403, 'You are not authorized.');

        $appointment->delete();

        return response()->json([
            'success' => true,
            'message' => 'Appointment deleted successfully.',
        ]);
    }
}
