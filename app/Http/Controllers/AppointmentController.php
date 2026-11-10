<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\Department;
use App\Models\Patient;
use App\Models\Provider;
use App\Models\SymptomCheck;
use App\Policies\AppointmentPolicy;
use App\Http\Requests\RescheduleAppointmentRequest;
use App\Http\Requests\DecideAppointmentRequest;
use App\Services\AppointmentService;
use App\Services\AuditLogService;
use App\Notifications\AppointmentJourneyNotification;
use App\Services\PatientService;
use App\Services\SchedulingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AppointmentController extends Controller
{
    public function __construct(
        protected AppointmentService $appointments,
        protected SchedulingService $scheduling,
        protected PatientService $patients,
        protected AppointmentPolicy $appointmentPolicy,
        protected AuditLogService $audit
    ) {}

    public function index(Request $request)
    {
        $query = Appointment::with(['patient', 'provider', 'department', 'appointmentType', 'vitals'])
            ->orderBy('starts_at', 'desc');

        $query = $this->appointmentPolicy->scopeQuery($request->user(), $query);

        if ($request->get('status')) {
            $query->where('status', $request->get('status'));
        }
        if ($request->get('date')) {
            $query->whereDate('starts_at', $request->get('date'));
        }
        if ($request->filled('follow_up')) {
            if ($request->get('follow_up') === 'due') {
                $query->whereHas('encounter', function ($encounterQuery) {
                    $encounterQuery->whereNotNull('follow_up_date')
                        ->whereDate('follow_up_date', '<=', now()->addDays(7)->toDateString());
                });
            }
        }
        if ($request->get('q')) {
            $term = $request->get('q');
            $query->where(function ($q) use ($term) {
                $q->where('appointment_number', 'like', "%{$term}%")
                    ->orWhereHas('patient', fn ($p) => $p->where('first_name', 'like', "%{$term}%")->orWhere('last_name', 'like', "%{$term}%"));
            });
        }

        $appointments = $query->paginate(15);

        $patientIds = $appointments->pluck('patient_id')->filter()->unique()->values();
        if ($request->filled('follow_up') && $request->get('follow_up') === 'due') {
            $patients = $patientIds->isNotEmpty()
                ? Patient::whereIn('id', $patientIds)->where('is_temporary_identity', false)->orderBy('last_name')->get()
                : collect();
        } else {
            $patients = Patient::where('is_temporary_identity', false)->orderBy('last_name')->get();
        }

        $providers = Provider::query()->where('active', true)->with('department')->orderBy('display_name')->get();
        $departments = Department::query()->where('active', true)->orderBy('name')->get();
        $appointmentTypes = AppointmentType::query()->where('active', true)->orderBy('name')->get();

        return view('appointments.index', [
            'appointments' => $appointments,
            'patients' => $patients,
            'providers' => $providers,
            'departments' => $departments,
            'appointmentTypes' => $appointmentTypes,
            'statuses' => [
                Appointment::STATUS_PENDING,
                Appointment::STATUS_CONFIRMED,
                Appointment::STATUS_CHECKED_IN,
                Appointment::STATUS_IN_CONSULTATION,
                Appointment::STATUS_COMPLETED,
                Appointment::STATUS_CANCELLED,
                Appointment::STATUS_REJECTED,
                Appointment::STATUS_NO_SHOW,
            ],
        ]);
    }

    public function create(Request $request)
    {
        Gate::authorize('create', Appointment::class);
        $user = auth()->user();
        $patients = $user->isPatient()
            ? collect([$user->patient()->firstOrFail()])
            : Patient::where('is_temporary_identity', false)->orderBy('last_name')->get();
        $providers = Provider::query()->where('active', true)->with('department')->orderBy('display_name')->get();
        $departments = Department::query()->where('active', true)->orderBy('name')->get();
        $appointmentTypes = AppointmentType::query()->where('active', true)->orderBy('name')->get();
        $selectedAppointmentTypeId = null;
        $selectedDepartmentId = null;
        $selectedProviderId = null;
        $selectedSpecialtyName = null;
        $selectedReason = null;
        $selectedSlotId = null;
        $selectedStartsAt = null;
        $parentAppointmentId = null;
        $selectedBookingSource = $user->isPatient() ? 'online' : 'online';
        if ($request->filled('provider_id')) {
            $selectedProviderId = $request->integer('provider_id');
        }
        if ($request->filled('appointment_type_id')) {
            $selectedAppointmentTypeId = $request->integer('appointment_type_id');
        }
        if ($request->filled('appointment_slot_id')) {
            $slot = \App\Models\AppointmentSlot::query()
                ->whereKey($request->integer('appointment_slot_id'))
                ->where('status', \App\Models\AppointmentSlot::STATUS_AVAILABLE)
                ->first();
            if ($slot && (! $selectedProviderId || (int) $selectedProviderId === (int) $slot->provider_id)) {
                $selectedProviderId = $slot->provider_id;
                $selectedSlotId = $slot->id;
                $selectedStartsAt = $slot->starts_at;
            }
        }
        $selectedReason = $request->query('reason');
        $parentAppointmentId = $request->integer('parent_appointment_id') ?: null;
        if ($request->filled('symptom_check_id')) {
            $symptomCheck = SymptomCheck::query()->findOrFail($request->integer('symptom_check_id'));
            if ($user->hasRole('patient') && $user->patient?->id !== $symptomCheck->patient_id) {
                abort(403);
            }
            abort_if($symptomCheck->requires_er, 403);
            $triageResult = $symptomCheck->ai_result ?? [];
            $selectedDepartmentId = $triageResult['recommended_department_id']
                ?? $departments->firstWhere('name', $symptomCheck->recommended_department)?->id;
            $selectedSpecialtyName = $triageResult['recommended_specialty'] ?? null;
            $specialtyId = $triageResult['recommended_specialty_id'] ?? null;
            if ($selectedDepartmentId && $specialtyId) {
                $selectedProviderId = Provider::query()
                    ->where('active', true)
                    ->where('department_id', $selectedDepartmentId)
                    ->whereHas('specialties', fn ($query) => $query->whereKey($specialtyId))
                    ->orderBy('id')
                    ->value('id');
            }
            $selectedReason = implode(', ', $symptomCheck->symptoms ?? []);

            $selectedAppointmentTypeId = AppointmentType::query()
                ->where('active', true)
                ->where('telehealth', $symptomCheck->recommended_consult_type === 'Virtual')
                ->value('id');
            if ($selectedAppointmentTypeId === null) {
                abort(500, 'No active appointment type is configured for the recommended consultation.');
            }
        }

        return view('appointments.create', compact(
            'patients',
            'providers',
            'departments',
            'appointmentTypes',
            'selectedAppointmentTypeId',
            'selectedDepartmentId',
            'selectedProviderId',
            'selectedSpecialtyName',
            'selectedReason',
            'selectedSlotId',
            'selectedStartsAt',
            'parentAppointmentId',
            'selectedBookingSource'
        ));
    }

    public function store(Request $request)
    {
        Gate::authorize('book', Appointment::class);
        try {
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

            if (! empty($data['parent_appointment_id'])) {
                $parent = Appointment::query()->findOrFail($data['parent_appointment_id']);
                abort_unless(
                    (int) $parent->patient_id === (int) $data['patient_id']
                    && (int) $parent->provider_id === (int) $data['provider_id'],
                    403,
                    'A follow-up must be booked with the same doctor for the same patient.'
                );
            }
            if (! empty($data['appointment_type_id'])) {
                $type = AppointmentType::query()->findOrFail($data['appointment_type_id']);
                $provider = Provider::query()->findOrFail($data['provider_id']);
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

            $appointment = $this->appointments->book($data, auth()->id());
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
            $appointment->load(['patient', 'provider', 'department', 'appointmentType', 'statusHistories.user', 'encounter', 'telehealthSession']);

            if ($request->expectsJson() || $request->wantsJson() || $request->ajax()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Appointment booked successfully.',
                    'appointment_id' => $appointment->id,
                    'html' => view('appointments.modal-detail', compact('appointment'))->render(),
                ]);
            }

            return redirect()->route('appointments.show', $appointment)
                ->with('success', 'Appointment booked successfully.');
        } catch (ValidationException $e) {
            if ($request->expectsJson() || $request->wantsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->validator->errors()->first(),
                    'errors' => $e->errors(),
                ], 422);
            }

            return back()->withInput()->withErrors($e->errors());
        }
    }

    public function approvalQueue(Request $request)
    {
        abort_unless($request->user()->hasAnyRole(['registration', 'nurse', 'admin', 'super-admin']), 403);
        $appointments = Appointment::query()->with(['patient', 'provider', 'appointmentType'])
            ->where('status', Appointment::STATUS_PENDING)
            ->orderBy('starts_at')
            ->paginate(20);

        return view('appointments.approvals', compact('appointments'));
    }

    public function decide(DecideAppointmentRequest $request, Appointment $appointment)
    {
        $data = $request->validated();
        $status = $data['decision'] === 'confirm'
            ? Appointment::STATUS_CONFIRMED
            : Appointment::STATUS_REJECTED;
        $updated = $this->appointments->transitionStatus($appointment, $status, $request->user()->id, $data['reason']);
        $this->audit->log($data['decision'] === 'confirm' ? 'APPROVE_APPOINTMENT' : 'REJECT_APPOINTMENT',
            'appointment', $updated->id, 'SUCCESS', ['has_reason' => true], $request->user()->id);

        $patientUser = $updated->patient?->user;
        if ($patientUser) {
            $patientUser->notify(new AppointmentJourneyNotification(
                $data['decision'] === 'confirm' ? 'Appointment confirmed' : 'Appointment request not approved',
                $data['decision'] === 'confirm'
                    ? 'Your appointment request has been confirmed for '.$updated->starts_at->format('M j, Y g:i A').'.'
                    : 'Your appointment request was not approved. Reason: '.$data['reason'],
                $updated
            ));
        }

        return back()->with('success', $data['decision'] === 'confirm' ? 'Appointment confirmed.' : 'Appointment rejected.');
    }

    public function show(Request $request, Appointment $appointment)
    {
        Gate::authorize('view', $appointment);
        $appointment->load(['patient', 'provider', 'department', 'appointmentType', 'statusHistories.user', 'encounter', 'telehealthSession']);

        if ($request->boolean('modal') || $request->expectsJson() || $request->wantsJson() || $request->ajax()) {
            return view('appointments.modal-detail', compact('appointment'));
        }

        return view('appointments.show', compact('appointment'));
    }

    public function slots(Request $request)
    {
        $data = $request->validate([
            'provider_id' => ['required', 'exists:providers,id'],
            'date' => ['required', 'date'],
        ]);

        abort_unless(
            ! $request->user()->hasRole('doctor')
            || (int) $data['provider_id'] === $request->user()->provider?->id,
            403
        );

        $slots = $this->scheduling->availableSlots((int) $data['provider_id'], $data['date']);

        return response()->json(['data' => $slots]);
    }

    public function checkIn(Request $request, Appointment $appointment)
    {
        try {
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
                    throw ValidationException::withMessages([
                        'reference_code' => ['No patient record found for this code.'],
                    ]);
                }
            }

            $this->appointments->checkIn($appointment, auth()->id());

            if ($request->expectsJson() || $request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => true, 'message' => 'Patient checked in successfully.']);
            }

            return back()->with('success', 'Patient checked in successfully.');
        } catch (ValidationException $e) {
            if ($request->expectsJson() || $request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => false, 'message' => $e->validator->errors()->first(), 'errors' => $e->errors()], 422);
            }

            return back()->withErrors($e->errors());
        }
    }

    public function cancel(Request $request, Appointment $appointment)
    {
        Gate::authorize('cancel', $appointment);
        $data = $request->validate(['reason' => ['nullable', 'string']]);
        try {
            $this->appointments->cancel($appointment, auth()->id(), $data['reason'] ?? null);

            if ($request->expectsJson() || $request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => true, 'message' => 'Appointment cancelled.']);
            }

            return back()->with('success', 'Appointment cancelled.');
        } catch (ValidationException $e) {
            if ($request->expectsJson() || $request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => false, 'message' => $e->validator->errors()->first(), 'errors' => $e->errors()], 422);
            }

            return back()->withErrors($e->errors());
        }
    }

    public function reschedule(RescheduleAppointmentRequest $request, Appointment $appointment)
    {
        Gate::authorize('reschedule', $appointment);
        $data = $request->validated();

        try {
            $this->appointments->reschedule($appointment, $data, auth()->id());

            if ($request->expectsJson() || $request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => true, 'message' => 'Appointment rescheduled.']);
            }

            return back()->with('success', 'Appointment rescheduled.');
        } catch (ValidationException $e) {
            if ($request->expectsJson() || $request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => false, 'message' => $e->validator->errors()->first(), 'errors' => $e->errors()], 422);
            }

            return back()->withInput()->withErrors($e->errors());
        }
    }

    public function markNoShow(Request $request, Appointment $appointment)
    {
        try {
            $this->appointments->markNoShow($appointment, auth()->id());

            if ($request->expectsJson() || $request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => true, 'message' => 'Marked as no-show.']);
            }

            return back()->with('success', 'Marked as no-show.');
        } catch (ValidationException $e) {
            if ($request->expectsJson() || $request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => false, 'message' => $e->validator->errors()->first(), 'errors' => $e->errors()], 422);
            }

            return back()->withErrors($e->errors());
        }
    }
}
