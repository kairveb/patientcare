<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\DecideAppointmentRequest;
use App\Http\Requests\RescheduleAppointmentRequest;
use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Notifications\AppointmentJourneyNotification;
use App\Policies\AppointmentPolicy;
use App\Services\AppointmentService;
use App\Services\AuditLogService;
use App\Services\PatientService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AppointmentApiController extends Controller
{
    public function __construct(
        protected AppointmentService $appointmentService,
        protected PatientService $patients,
        protected AppointmentPolicy $appointmentPolicy,
        protected AuditLogService $audit
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
            'appointment_slot_id' => ['required', 'integer', 'exists:appointment_slots,id'],
            'booking_source' => ['nullable', 'in:online,walk_in,phone'],
            'parent_appointment_id' => ['nullable', 'integer', 'exists:appointments,id'],
        ]);

        if ($request->user()->isPatient()) {
            $data['patient_id'] = $request->user()->patient()->firstOrFail()->id;
            $data['booking_source'] = 'online';
            $data['status'] = config('appointments.requires_approval', true)
                ? Appointment::STATUS_PENDING
                : Appointment::STATUS_CONFIRMED;
        } else {
            $data['booking_source'] = $data['booking_source'] ?? 'online';
            if (in_array($data['booking_source'], ['walk_in', 'phone'], true)) {
                abort_unless($request->user()->hasAnyRole(['registration', 'nurse', 'admin', 'super-admin']), 403);
                $data['status'] = Appointment::STATUS_CONFIRMED;
            } elseif (! config('appointments.requires_approval', true)) {
                $data['status'] = Appointment::STATUS_CONFIRMED;
            }
        }
        if (! empty($data['appointment_type_id'])) {
            $type = AppointmentType::query()->findOrFail($data['appointment_type_id']);
            $provider = \App\Models\Provider::query()->findOrFail($data['provider_id']);
            if ($type->telehealth && ! $provider->accepts_virtual) {
                throw ValidationException::withMessages([
                    'appointment_type_id' => 'This doctor is not currently accepting virtual consultations.',
                ]);
            }
            $slot = \App\Models\AppointmentSlot::query()->findOrFail($data['appointment_slot_id']);
            if ($slot->appointment_type_id && (int) $slot->appointment_type_id !== (int) $type->id) {
                throw ValidationException::withMessages([
                    'appointment_slot_id' => 'The selected slot is not for this consultation type.',
                ]);
            }
        }
        if (! empty($data['parent_appointment_id'])) {
            $parent = Appointment::query()->findOrFail($data['parent_appointment_id']);
            abort_unless(
                (int) $parent->patient_id === (int) $data['patient_id']
                && (int) $parent->provider_id === (int) $data['provider_id'],
                403
            );
        }

        $appointment = $this->appointmentService->book($data, $request->user()->id);
        if (in_array($appointment->booking_source, ['walk_in', 'phone'], true)) {
            $this->audit->log('CREATE_WALK_IN_APPOINTMENT', 'appointment', $appointment->id, 'SUCCESS', [
                'booking_source' => $appointment->booking_source,
            ], $request->user()->id);
        }
        if ($appointment->status === Appointment::STATUS_CONFIRMED && $appointment->patient?->user) {
            $appointment->patient->user->notify(new AppointmentJourneyNotification(
                'Appointment confirmed',
                'Your appointment is confirmed for '.$appointment->starts_at->format('M j, Y g:i A').'.',
                $appointment
            ));
        }

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
            'status' => ['sometimes', 'in:PENDING,CONFIRMED,REJECTED,CHECKED-IN,IN-CONSULTATION,COMPLETED,CANCELLED,NO-SHOW'],
            'reason' => ['nullable', 'string', 'min:3', 'max:1000'],
        ]);

        if (!empty($data['status'])) {
            if ($appointment->status === Appointment::STATUS_PENDING
                && in_array($data['status'], [Appointment::STATUS_CONFIRMED, Appointment::STATUS_REJECTED], true)) {
                throw ValidationException::withMessages([
                    'decision' => 'Use the appointment decision workflow with a confirm/reject decision and reason.',
                ]);
            }
            $appointment = $this->appointmentService->transitionStatus(
                $appointment,
                $data['status'],
                $request->user()->id,
                $data['reason'] ?? null
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Appointment updated successfully.',
            'data' => $appointment,
        ]);
    }

    public function decide(DecideAppointmentRequest $request, Appointment $appointment): JsonResponse
    {
        $data = $request->validated();
        $status = $data['decision'] === 'confirm'
            ? Appointment::STATUS_CONFIRMED
            : Appointment::STATUS_REJECTED;
        $updated = $this->appointmentService->transitionStatus(
            $appointment,
            $status,
            $request->user()->id,
            $data['reason']
        );
        $this->audit->log(
            $data['decision'] === 'confirm' ? 'APPROVE_APPOINTMENT' : 'REJECT_APPOINTMENT',
            'appointment',
            $updated->id,
            'SUCCESS',
            ['has_reason' => true, 'channel' => 'api'],
            $request->user()->id
        );

        if ($updated->patient?->user) {
            $updated->patient->user->notify(new AppointmentJourneyNotification(
                $data['decision'] === 'confirm' ? 'Appointment confirmed' : 'Appointment request not approved',
                $data['decision'] === 'confirm'
                    ? 'Your appointment request has been confirmed for '.$updated->starts_at->format('M j, Y g:i A').'.'
                    : 'Your appointment request was not approved. Reason: '.$data['reason'],
                $updated
            ));
        }

        return response()->json([
            'success' => true,
            'message' => $data['decision'] === 'confirm' ? 'Appointment confirmed.' : 'Appointment rejected.',
            'data' => $updated,
        ]);
    }

    public function markNoShow(Request $request, int $id): JsonResponse
    {
        $appointment = Appointment::findOrFail($id);
        abort_unless(Gate::allows('manageLifecycle', $appointment), 403, 'You are not authorized.');

        $updated = $this->appointmentService->markNoShow($appointment, $request->user()->id);

        return response()->json([
            'success' => true,
            'message' => 'Appointment marked as no-show.',
            'data' => $updated,
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
    public function reschedule(RescheduleAppointmentRequest $request, int $id): JsonResponse
    {
        $appointment = Appointment::findOrFail($id);

        abort_unless($request->user()->can('reschedule', $appointment), 403, 'You are not authorized.');

        $data = $request->validated();

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
