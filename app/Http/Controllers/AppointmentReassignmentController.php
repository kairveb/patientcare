<?php

namespace App\Http\Controllers;

use App\Http\Requests\DecideAppointmentReassignmentRequest;
use App\Http\Requests\PatientAppointmentReassignmentRequest;
use App\Http\Requests\StoreProviderUnavailabilityRequest;
use App\Models\AppointmentReassignment;
use App\Models\Provider;
use App\Models\ProviderUnavailability;
use App\Services\AppointmentReassignmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AppointmentReassignmentController extends Controller
{
    public function __construct(private readonly AppointmentReassignmentService $reassignments)
    {
    }

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', AppointmentReassignment::class);
        $query = AppointmentReassignment::query()
            ->with(['appointment.patient', 'appointment.appointmentType', 'originalProvider', 'suggestedProvider', 'suggestedSlot', 'waitlist'])
            ->orderByRaw("CASE status WHEN 'PENDING_REVIEW' THEN 0 WHEN 'DECLINED_BY_PATIENT' THEN 1 ELSE 2 END")
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->value());
        }

        $cases = $query->paginate(20)->withQueryString();
        $candidateMap = [];
        foreach ($cases as $case) {
            if (in_array($case->status, [AppointmentReassignment::STATUS_PENDING_REVIEW, AppointmentReassignment::STATUS_DECLINED_BY_PATIENT], true)) {
                $candidateMap[$case->id] = $this->reassignments->suggestionsFor($case->appointment);
            }
        }

        return view('appointment-reassignments.index', [
            'cases' => $cases,
            'candidateMap' => $candidateMap,
            'unavailabilities' => ProviderUnavailability::with(['provider', 'recordedBy'])->latest()->limit(10)->get(),
            'providers' => Provider::query()->where('active', true)->with(['user', 'department'])->orderBy('display_name')->get(),
            'statuses' => [
                AppointmentReassignment::STATUS_PENDING_REVIEW,
                AppointmentReassignment::STATUS_DECLINED_BY_PATIENT,
                AppointmentReassignment::STATUS_APPROVED,
                AppointmentReassignment::STATUS_RESCHEDULED,
                AppointmentReassignment::STATUS_WAITLISTED,
                AppointmentReassignment::STATUS_CANCELLED,
            ],
        ]);
    }

    public function unavailabilityForm(Request $request): View
    {
        $query = Provider::query()->where('active', true)->with(['user', 'department'])->orderBy('display_name');
        if ($request->user()->hasRole('doctor')) {
            $query->whereKey($request->user()->provider?->id ?? 0);
        }

        return view('appointment-reassignments.unavailability', ['providers' => $query->get()]);
    }

    public function storeUnavailability(StoreProviderUnavailabilityRequest $request): RedirectResponse
    {
        $this->reassignments->markUnavailable($request->validated(), (int) $request->user()->id);

        $destination = $request->user()->hasAnyRole(['registration', 'admin', 'super-admin'])
            ? route('appointment-reassignments.index')
            : route('dashboard');

        return redirect($destination)
            ->with('success', 'Provider unavailability recorded. Affected appointments have been reviewed for reassignment.');
    }

    public function decide(DecideAppointmentReassignmentRequest $request, AppointmentReassignment $reassignment): RedirectResponse
    {
        $data = $request->validated();
        $this->reassignments->decide(
            $reassignment,
            $data['action'],
            (int) $request->user()->id,
            isset($data['provider_id']) ? (int) $data['provider_id'] : null,
            isset($data['appointment_slot_id']) ? (int) $data['appointment_slot_id'] : null,
            $data['remarks'] ?? null
        );

        return back()->with('success', 'Reassignment case updated.');
    }

    public function patientIndex(Request $request): View
    {
        $patient = $request->user()->patient()->firstOrFail();
        $cases = AppointmentReassignment::query()
            ->with(['appointment.provider', 'appointment.appointmentType', 'suggestedProvider', 'suggestedSlot', 'histories'])
            ->whereHas('appointment', fn ($query) => $query->where('patient_id', $patient->id))
            ->latest()
            ->paginate(15);

        return view('patient-portal.reassignments', compact('cases'));
    }

    public function patientShow(Request $request, AppointmentReassignment $reassignment): View
    {
        Gate::authorize('view', $reassignment);
        $reassignment->load(['appointment.provider', 'appointment.appointmentType', 'suggestedProvider', 'suggestedSlot', 'histories.changedBy']);
        $candidates = $this->reassignments->suggestionsFor($reassignment->appointment);

        return view('patient-portal.reassignment', compact('reassignment', 'candidates'));
    }

    public function patientRespond(
        PatientAppointmentReassignmentRequest $request,
        AppointmentReassignment $reassignment
    ): RedirectResponse {
        $data = $request->validated();
        $this->reassignments->decide(
            $reassignment,
            $data['action'],
            (int) $request->user()->id,
            isset($data['provider_id']) ? (int) $data['provider_id'] : null,
            isset($data['appointment_slot_id']) ? (int) $data['appointment_slot_id'] : null,
            $data['remarks'] ?? null
        );

        return redirect()->route('patient.reassignments.show', $reassignment)
            ->with('success', 'Your appointment choice has been recorded.');
    }
}
