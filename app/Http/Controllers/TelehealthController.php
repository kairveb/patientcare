<?php

namespace App\Http\Controllers;

use App\Http\Requests\EscalateTelehealthToErRequest;
use App\Models\Prescription;
use App\Models\ErVisit;
use App\Models\TelehealthSession;
use App\Services\AuditLogService;
use App\Services\EmergencyAlertService;
use App\Services\PatientConsentService;
use App\Services\TelehealthService;
use App\Services\TriageService;
use App\Services\ZoomService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class TelehealthController extends Controller
{
    public function __construct(
        protected TelehealthService $telehealth,
        protected TriageService $triage,
        protected AuditLogService $audit,
        protected EmergencyAlertService $emergencyAlerts,
        protected PatientConsentService $consents
    ) {}

    public function index(Request $request)
    {
        $statusFilter = $request->get('status');
        $dateFilter = $request->get('date');
        $searchQuery = trim((string) $request->get('q', ''));
        $prescriptionsView = $request->input('view') === 'prescriptions';

        $query = TelehealthSession::with(['appointment.patient', 'appointment.provider'])->orderBy('start_time', 'desc');

        $user = auth()->user();
        $canViewMeetingUrls = $user?->hasRole('super-admin', 'doctor') ?? false;
        if ($user && $user->hasRole('patient')) {
            $patientId = $user->patient?->id;
            $query->whereHas('appointment', fn ($appointmentQuery) => $appointmentQuery->where('patient_id', $patientId ?? 0));
        } elseif ($user && $user->hasRole('doctor')) {
            $providerId = $user->provider?->id;
            $query->whereHas('appointment', fn ($appointmentQuery) => $appointmentQuery->where('provider_id', $providerId ?? 0));
        }

        if ($searchQuery !== '') {
            $query->whereHas('appointment.patient', function ($patientQuery) use ($searchQuery) {
                $patientQuery->where(function ($inner) use ($searchQuery) {
                    $search = '%' . strtolower($searchQuery) . '%';
                    $inner->whereRaw('LOWER(first_name) LIKE ?', [$search])
                        ->orWhereRaw('LOWER(last_name) LIKE ?', [$search])
                        ->orWhereRaw('LOWER(CONCAT(first_name, " ", last_name)) LIKE ?', [$search]);
                });
            });
        }

        if ($dateFilter) {
            $query->whereDate('start_time', $dateFilter);
        }

        if ($statusFilter === 'live') {
            $query->whereIn('status', [TelehealthSession::STATUS_ACTIVE, TelehealthSession::STATUS_ONGOING]);
        } elseif ($statusFilter) {
            $query->where('status', $statusFilter);
        }

        $sessions = $query->paginate(15);
        if (! $canViewMeetingUrls) {
            $sessions->getCollection()->each(
                fn (TelehealthSession $session) => $session->makeHidden(['join_url', 'host_start_url', 'zoom_meeting_id'])
            );
        }
        $prescriptions = Prescription::with(['patient', 'telehealthSession.appointment.patient', 'encounter'])
            ->orderByDesc('prescribed_at')
            ->paginate(15, ['*'], 'prescriptions_page');

        return view('telehealth.index', [
            'sessions' => $sessions,
            'prescriptions' => $prescriptions,
            'zoomEnabled' => config('services.zoom.enabled'),
            'statusFilter' => $statusFilter,
            'dateFilter' => $dateFilter,
            'searchQuery' => $searchQuery,
            'prescriptionsView' => $prescriptionsView,
            'canViewMeetingUrls' => $canViewMeetingUrls,
        ]);
    }

    public function show(TelehealthSession $session, Request $request)
    {
        $isSessionDoctor = $request->user()->hasRole('doctor')
            && $request->user()->provider?->id === $session->appointment?->provider_id;
        abort_unless(
            ! $request->user()->hasRole('doctor') || $isSessionDoctor,
            404
        );
        $canViewMeetingUrls = $isSessionDoctor || $request->user()->hasRole('super-admin');
        if (! $canViewMeetingUrls) {
            $session->makeHidden(['join_url', 'host_start_url', 'zoom_meeting_id']);
        }

        $session->load([
            'appointment.patient',
            'appointment.provider',
            'participants.user',
            'intake',
            'aiSummary',
            'referrals.department',
        ]);

        if ($request->boolean('modal') || $request->expectsJson() || $request->wantsJson() || $request->ajax()) {
            return view('telehealth.modal-detail', [
                'session' => $session,
                'zoomEnabled' => config('services.zoom.enabled'),
                'isSessionDoctor' => $isSessionDoctor,
                'canViewMeetingUrls' => $canViewMeetingUrls,
            ]);
        }

        return view('telehealth.show', [
            'session' => $session,
            'zoomEnabled' => config('services.zoom.enabled'),
            'isSessionDoctor' => $isSessionDoctor,
            'canViewMeetingUrls' => $canViewMeetingUrls,
        ]);
    }

    public function escalateToEr(EscalateTelehealthToErRequest $request, TelehealthSession $session)
    {
        $session->loadMissing('appointment.provider');
        $erVisit = DB::transaction(function () use ($request, $session): ErVisit {
            $lockedSession = TelehealthSession::query()
                ->with('appointment')
                ->lockForUpdate()
                ->findOrFail($session->id);
            abort_unless($lockedSession->isLive(), 409, 'Only an active telehealth session can be escalated.');
            abort_unless(
                $request->user()->hasRole('doctor')
                    && $request->user()->provider?->id === $lockedSession->appointment?->provider_id,
                403,
                'Only the doctor assigned to this telehealth session can escalate it.'
            );

            $visit = ErVisit::create([
                'visit_number' => $this->triage->generateVisitNumber(),
                'patient_id' => $lockedSession->appointment->patient_id,
                'telehealth_session_id' => $lockedSession->id,
                'arrived_at' => now(),
                'arrival_method' => 'TELEHEALTH',
                'chief_complaint' => 'Emergency escalation from telehealth session',
                'referral_details' => 'Telehealth session '.$lockedSession->id.' escalated by the assigned doctor.',
                'status' => ErVisit::STATUS_ARRIVED,
                'created_by' => $request->user()->id,
            ]);

            $lockedSession->update([
                'status' => TelehealthSession::STATUS_ESCALATED,
                'escalated_at' => now(),
            ]);
            $this->audit->log(
                'ESCALATE_TELEHEALTH_TO_ER',
                'telehealth_session',
                $lockedSession->id,
                'SUCCESS',
                ['er_visit_id' => $visit->id],
                $request->user()->id
            );

            return $visit;
        });

        $this->emergencyAlerts->telehealthEscalated();

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'The patient has been escalated to the Emergency Department.',
                'data' => [
                    'er_visit' => $erVisit,
                    'telehealth_session' => $session->fresh(),
                ],
            ], 201);
        }

        return redirect()->route('emergency.show', $erVisit)
            ->with('success', 'The patient has been escalated to the Emergency Department.');
    }

public function join(TelehealthSession $session, Request $request)
    {
        $user = auth()->user();

        $this->authorize('view', $session->appointment);
        $isAssignedDoctor = $user?->hasRole('doctor')
            && $user->provider?->id === $session->appointment?->provider_id;
        $isSessionPatient = $user?->hasRole('patient')
            && $user->patient?->id === $session->appointment?->patient_id;
        $canViewJoinUrl = $isSessionPatient || $isAssignedDoctor || $user?->hasRole('super-admin');

        if (! $canViewJoinUrl) {
            $session->makeHidden(['join_url', 'host_start_url', 'zoom_meeting_id']);
        } elseif (! $isAssignedDoctor && ! $user?->hasRole('super-admin')) {
            $session->makeHidden(['host_start_url', 'zoom_meeting_id']);
        }

        abort_unless(
            $session->status !== TelehealthSession::STATUS_ESCALATED,
            403,
            'This telehealth session has been escalated to the Emergency Department.'
        );
        $patient = $session->appointment?->patient;
        if (! $patient || ! $this->consents->hasCurrentGrant($patient, 'TELEHEALTH')) {
            if ($user?->hasRole('patient')) {
                return redirect()->route('patient-consents.portal', ['required' => 'TELEHEALTH'])
                    ->with('warning', 'Current telehealth consent is required before joining this session.');
            }

            abort(403, 'The patient must provide current telehealth consent before this session can be joined.');
        }

        abort_unless($session->isLive(), 409, 'Your doctor has not started this telehealth session yet.');

        $token = $request->query('token');
        abort_unless($this->telehealth->verifyJoinToken($session, $token), 403, 'Invalid or expired telehealth session link.');
        abort_unless(Gate::allows('join-telehealth'), 403, 'You are not authorized to join this telehealth room.');

        if ($user && $user->hasRole('patient')) {
            $sessionPatientId = $session->appointment?->patient_id;
            $patientUserId = $user->patient?->id;

            if ($patientUserId === null || $sessionPatientId !== $patientUserId) {
                abort(403, 'You can only join your own telehealth session.');
            }
        }

        return view('telehealth.join', compact('session', 'canViewJoinUrl'));
    }

    public function createMeeting(Request $request, TelehealthSession $session)
    {
        $session->loadMissing('appointment');
        abort_unless(
            $request->user()->hasRole('super-admin')
                || ($request->user()->hasRole('doctor')
                    && $request->user()->provider?->id === $session->appointment?->provider_id),
            403,
            'Only the assigned doctor or a Super Admin can create this meeting.'
        );

        $data = $request->validate([
            'start_time' => ['nullable', 'date'],
            'duration' => ['nullable', 'integer', 'min:5', 'max:240'],
        ]);

        $session->update([
            'start_time' => $data['start_time'] ? Carbon::parse($data['start_time']) : $session->start_time,
            'duration' => $data['duration'] ?? $session->duration,
        ]);

        if (! $this->telehealth->isConfigured()) {
            if ($request->expectsJson() || $request->wantsJson() || $request->ajax()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Zoom is not configured. Session details saved locally. Enable ZOOM_ENABLED and credentials to create a live meeting.',
                    'data' => $session->fresh(),
                ]);
            }

            return back()->with('warning', 'Zoom is not configured. Session details saved locally. Enable ZOOM_ENABLED and credentials to create a live meeting.');
        }

        $createdSession = $this->telehealth->createSession($session->appointment);
        $message = $createdSession->zoom_meeting_id
            ? 'Zoom meeting created successfully.'
            : 'Zoom meeting could not be created. A protected local telehealth room is ready.';

        if ($request->expectsJson() || $request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => $createdSession,
            ]);
        }

        return back()->with('success', $message);
    }
}
