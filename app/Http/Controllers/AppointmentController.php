<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\Department;
use App\Models\Patient;
use App\Models\Provider;
use App\Models\SymptomCheck;
use App\Policies\AppointmentPolicy;
use App\Services\AppointmentService;
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
        protected AppointmentPolicy $appointmentPolicy
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

        $providers = Provider::where('active', true)->with('department')->get();
        $departments = Department::orderBy('name')->get();
        $appointmentTypes = AppointmentType::orderBy('name')->get();

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
        $providers = Provider::where('active', true)->with('department')->get();
        $departments = Department::orderBy('name')->get();
        $appointmentTypes = AppointmentType::orderBy('name')->get();
        $selectedAppointmentTypeId = null;
        if ($request->filled('symptom_check_id')) {
            $symptomCheck = SymptomCheck::query()->findOrFail($request->integer('symptom_check_id'));
            if ($user->hasRole('patient') && $user->patient?->id !== $symptomCheck->patient_id) {
                abort(403);
            }
            abort_if($symptomCheck->requires_er, 403);
            $selectedAppointmentTypeId = AppointmentType::query()
                ->where('active', true)
                ->where('telehealth', $symptomCheck->recommended_consult_type === 'Virtual')
                ->value('id');
            if ($selectedAppointmentTypeId === null) {
                abort(500, 'No active appointment type is configured for the recommended consultation.');
            }
        }

        return view('appointments.create', compact('patients', 'providers', 'departments', 'appointmentTypes', 'selectedAppointmentTypeId'));
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
            ]);

            if ($request->user()->isPatient()) {
                $data['patient_id'] = $request->user()->patient()->firstOrFail()->id;
            }

            $appointment = $this->appointments->book($data, auth()->id());
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

    public function reschedule(Request $request, Appointment $appointment)
    {
        Gate::authorize('reschedule', $appointment);
        $data = $request->validate([
            'starts_at' => ['required', 'date'],
            'duration' => ['nullable', 'integer'],
        ]);

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
