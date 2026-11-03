<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\CareRequest;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\TelehealthSession;
use App\Models\Prescription;
use App\Services\DischargeSummaryService;
use App\Services\PatientConsentService;
use App\Services\PatientQrCodeService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PatientPortalController extends Controller
{
    public function __construct(protected PatientConsentService $consents) {}

    public function dashboard(PatientQrCodeService $qrCodes)
    {
        $user = Auth::user();
        $patient = $user?->patient()->with(['appointments.provider.user', 'encounters.provider', 'addresses'])->first();

        if (! $patient) {
            return redirect()->route('patients.profile')->with('warning', 'Complete your registration details to access the patient portal.');
        }

        $preArrivalProfile = $patient->preArrivalProfiles()->latest()->first();
        $preArrivalQrCode = $preArrivalProfile
            ? $qrCodes->dataUriFor($preArrivalProfile->token)
            : null;

        $upcomingAppointments = $patient->appointments()
            ->with(['provider.user', 'appointmentType'])
            ->where('starts_at', '>=', now())
            ->orderBy('starts_at')
            ->limit(5)
            ->get();

        $recentEncounters = $patient->encounters()
            ->with('provider.user')
            ->orderByDesc('started_at')
            ->limit(5)
            ->get();

        $upcomingTelehealth = TelehealthSession::query()
            ->whereHas('appointment', fn ($query) => $query->where('patient_id', $patient->id))
            ->where('status', '!=', TelehealthSession::STATUS_COMPLETED)
            ->with(['appointment.provider.user'])
            ->orderBy('start_time')
            ->limit(5)
            ->get();

        $prescriptions = Prescription::query()
            ->where('patient_id', $patient->id)
            ->with('prescribedBy')
            ->orderByDesc('prescribed_at')
            ->limit(5)
            ->get();

        return view('patient-portal.dashboard', compact(
            'patient',
            'upcomingAppointments',
            'recentEncounters',
            'upcomingTelehealth',
            'prescriptions',
            'preArrivalProfile',
            'preArrivalQrCode'
        ));
    }

    public function appointments()
    {
        $patient = Auth::user()?->patient()->firstOrFail();

        $appointments = $patient->appointments()
            ->with(['provider.user', 'appointmentType'])
            ->orderByDesc('starts_at')
            ->paginate(10);

        $followUps = $patient->encounters()
            ->with(['provider', 'appointment'])
            ->whereNotNull('follow_up_date')
            ->whereDate('follow_up_date', '>=', today())
            ->orderBy('follow_up_date')
            ->get();

        return view('patient-portal.appointments', compact('patient', 'appointments', 'followUps'));
    }

    public function approvals(Request $request)
    {
        $patient = $request->user()->patient()->firstOrFail();
        $requests = $patient->careRequests()
            ->with(['encounter.provider', 'requestedBy', 'decidedBy'])
            ->orderByDesc('created_at')
            ->paginate(10);

        return view('patient-portal.approvals', compact('patient', 'requests'));
    }

    public function approval(Request $request, CareRequest $careRequest)
    {
        $this->authorize('view', $careRequest);
        abort_unless($request->user()->hasRole('patient'), 403);
        $careRequest->load(['encounter.provider', 'requestedBy', 'decidedBy', 'clinicalDocument', 'statusHistories.user']);

        return view('patient-portal.approval', compact('careRequest'));
    }

    public function patientId(Request $request, PatientQrCodeService $qrCodes)
    {
        $patient = $request->user()->patient()->firstOrFail();

        return view('patient-portal.patient-id', [
            'patient' => $patient,
            'referenceCodeQr' => $patient->reference_code ? $qrCodes->dataUri($patient) : null,
        ]);
    }

    public function history()
    {
        $patient = Auth::user()?->patient()->firstOrFail();

        $history = $patient->encounters()
            ->with(['provider.user', 'notes', 'appointment.telehealthSession.aiSummary'])
            ->orderByDesc('started_at')
            ->paginate(10);

        return view('patient-portal.history', compact('patient', 'history'));
    }

    public function dischargeSummaries(Request $request, DischargeSummaryService $summaries): View
    {
        $patient = $request->user()->patient()->firstOrFail();
        $results = $summaries->approvedForPatient($patient)
            ->with('attendingProvider')
            ->orderByDesc('approved_at')
            ->paginate(10);

        foreach ($results->items() as $summary) {
            $summaries->recordPatientView($summary, $request->user());
        }

        return view('patient-portal.discharge-summaries', [
            'summaries' => $results,
        ]);
    }

    public function dischargeSummariesApi(Request $request, DischargeSummaryService $summaries): JsonResponse
    {
        $patient = $request->user()->patient()->firstOrFail();
        $results = $summaries->approvedForPatient($patient)
            ->with('attendingProvider')
            ->orderByDesc('approved_at')
            ->paginate(10);

        foreach ($results->items() as $summary) {
            $summaries->recordPatientView($summary, $request->user());
        }

        return response()->json([
            'success' => true,
            'data' => collect($results->items())
                ->map(fn ($summary) => $summaries->patientPayload($summary))
                ->all(),
            'meta' => [
                'current_page' => $results->currentPage(),
                'last_page' => $results->lastPage(),
                'total' => $results->total(),
            ],
        ]);
    }

    public function dischargeSummaryApi(Request $request, int $id, DischargeSummaryService $summaries): JsonResponse
    {
        $patient = $request->user()->patient()->firstOrFail();
        $summary = $summaries->findApprovedForPatient($patient, $id);
        $summaries->recordPatientView($summary, $request->user());

        return response()->json([
            'success' => true,
            'data' => $summaries->patientPayload($summary),
        ]);
    }

    public function telehealth()
    {
        $patient = Auth::user()?->patient()->firstOrFail();

        $sessions = TelehealthSession::query()
            ->whereHas('appointment', fn ($query) => $query->where('patient_id', $patient->id))
            ->with(['appointment.provider.user', 'aiSummary', 'referrals.department'])
            ->orderByDesc('start_time')
            ->paginate(10);

        return view('patient-portal.telehealth', compact('patient', 'sessions'));
    }

    public function waitingRoom(TelehealthSession $session, Request $request)
    {
        $patient = $request->user()->patient()->firstOrFail();
        abort_unless($session->appointment?->patient_id === $patient->id, 403);

        $session->loadMissing(['appointment.provider.user', 'appointment.department']);
        $hasTelehealthConsent = $this->consents->hasCurrentGrant($patient, 'TELEHEALTH');
        $todaySessions = TelehealthSession::query()
            ->whereDate('start_time', today())
            ->whereIn('status', [
                TelehealthSession::STATUS_SCHEDULED,
                TelehealthSession::STATUS_ACTIVE,
                TelehealthSession::STATUS_ONGOING,
            ])
            ->whereHas('appointment', fn ($query) => $query->where('provider_id', $session->appointment?->provider_id))
            ->orderBy('start_time')
            ->orderBy('id')
            ->pluck('id');
        $queueIndex = $session->start_time?->isToday()
            ? $todaySessions->search($session->id)
            : false;
        $queuePosition = $queueIndex === false ? null : $queueIndex + 1;
        $appointment = $session->appointment;
        $canJoin = $session->isLive()
            && $hasTelehealthConsent
            && $appointment
            && in_array($appointment->status, [
                \App\Models\Appointment::STATUS_CONFIRMED,
                \App\Models\Appointment::STATUS_CHECKED_IN,
                \App\Models\Appointment::STATUS_IN_CONSULTATION,
            ], true)
            && now()->gte($appointment->starts_at->copy()->subMinutes((int) config('telehealth.join_early_minutes', 15)))
            && now()->lte($appointment->ends_at->copy()->addMinutes((int) config('telehealth.join_late_minutes', 30)));
        $joinUrl = $canJoin
            ? route('telehealth.join', ['session' => $session])
            : null;

        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json([
                'success' => true,
                'data' => [
                    'telehealth_session_id' => $session->id,
                    'status' => $session->status,
                    'queue_position' => $queuePosition,
                    'queue_total' => $todaySessions->count(),
                    'doctor_started' => $session->isLive(),
                    'telehealth_consent' => $hasTelehealthConsent,
                    'can_join' => $canJoin,
                    'join_url' => $joinUrl,
                ],
            ]);
        }

        return view('patient-portal.telehealth-waiting-room', [
            'session' => $session,
            'queuePosition' => $queuePosition,
            'queueTotal' => $todaySessions->count(),
            'doctorStarted' => $session->isLive(),
            'hasTelehealthConsent' => $hasTelehealthConsent,
            'canJoin' => $canJoin,
            'joinUrl' => $joinUrl,
        ]);
    }
}
