<?php

namespace App\Http\Controllers;

use App\Http\Requests\EscalateTelehealthToErRequest;
use App\Models\Prescription;
use App\Models\ErVisit;
use App\Models\TelehealthSession;
use App\Models\Appointment;
use App\Models\TelehealthParticipant;
use App\Services\AuditLogService;
use App\Services\AppointmentService;
use App\Services\EmergencyAlertService;
use App\Services\PatientConsentService;
use App\Services\TelehealthService;
use App\Services\TriageService;
use App\Services\ZoomService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

class TelehealthController extends Controller
{
    public function __construct(
        protected TelehealthService $telehealth,
        protected TriageService $triage,
        protected AuditLogService $audit,
        protected EmergencyAlertService $emergencyAlerts,
        protected PatientConsentService $consents,
        protected AppointmentService $appointments
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
                $terms = preg_split('/\s+/', mb_strtolower($searchQuery), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $patientQuery->where(function ($inner) use ($terms) {
                    foreach ($terms as $term) {
                        $search = '%'.$term.'%';
                        $inner->where(function ($nameQuery) use ($search) {
                            $nameQuery->whereRaw('LOWER(first_name) LIKE ?', [$search])
                                ->orWhereRaw('LOWER(last_name) LIKE ?', [$search]);
                        });
                    }
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
    $session->loadMissing(['appointment.patient', 'appointment.provider', 'appointment.appointmentType']);
    $user = $request->user();
    $role = $this->authorizedParticipantRole($session, $user);
    if (! $role) {
        $this->denyJoin($session, $user, 'not_appointment_patient_or_assigned_doctor');
    }

    $reason = $this->telehealth->joinWindowReason($session);
    if ($reason !== null) {
        $this->denyJoin($session, $user, $reason, 403);
    }
    $patient = $session->appointment->patient;
    if (! $patient || ! $this->consents->hasCurrentGrant($patient, 'TELEHEALTH')) {
        $this->audit->log('TELEHEALTH_JOIN_DENIED', 'telehealth_session', $session->id, 'FAILURE', [
            'reason' => 'current_telehealth_consent_missing',
            'actor_role' => $role,
        ], $user->id);

        if ($role === 'patient') {
            return redirect()->route('patient-consents.portal', ['required' => 'TELEHEALTH'])
                ->with('warning', 'Current telehealth consent is required before joining this session.');
        }

        abort(403, 'The patient must provide current telehealth consent before this session can be joined.');
    }
    if ($role === 'patient' && ! $session->isLive()) {
        $this->denyJoin($session, $user, 'doctor_not_started', 409);
    }

    try {
        $demoMode = $this->telehealth->providerName() === 'jitsi_demo';
    } catch (RuntimeException $exception) {
        report($exception);

        abort(503, 'Telehealth is not configured. Please contact the hospital before joining.');
    }
    $joinToken = $this->telehealth->issueJoinToken($session, $user);
    $this->audit->log('TELEHEALTH_JOIN_TOKEN_ISSUED', 'telehealth_session', $session->id, 'SUCCESS', [
        'actor_role' => $role,
        'expires_in_minutes' => (int) config('telehealth.join_token_ttl_minutes', 5),
    ], $user->id);

    return view('telehealth.join', [
        'session' => $session,
        'role' => $role,
        'demoMode' => $demoMode,
        'videoDetails' => null,
        'joined' => false,
        'joinToken' => $joinToken,
    ]);
}

public function connect(TelehealthSession $session, Request $request)
{
    $data = $request->validate([
        'privacy_acknowledged' => ['required', 'accepted'],
        'join_token' => ['required', 'string', 'max:512'],
    ]);
    $session->loadMissing(['appointment.patient', 'appointment.provider', 'appointment.appointmentType']);
    $user = $request->user();
    $role = $this->authorizedParticipantRole($session, $user);
    if (! $role) {
        $this->denyJoin($session, $user, 'not_appointment_patient_or_assigned_doctor');
    }

    $reason = $this->telehealth->joinWindowReason($session);
    if ($reason !== null) {
        $this->denyJoin($session, $user, $reason, 403);
    }
    if (! $session->appointment->patient
        || ! $this->consents->hasCurrentGrant($session->appointment->patient, 'TELEHEALTH')) {
        $this->denyJoin($session, $user, 'current_telehealth_consent_missing');
    }
    if ($role === 'patient' && ! $session->isLive()) {
        $this->denyJoin($session, $user, 'doctor_not_started', 409);
    }

    $this->telehealth->consumeJoinToken($session, $user, $data['join_token']);
    $this->audit->log('TELEHEALTH_CONSENT_ACKNOWLEDGED', 'telehealth_session', $session->id, 'SUCCESS', [
        'actor_role' => $role,
    ], $user->id);

    $details = $this->telehealth->providerJoinDetails($session, $user);
    if ($role === 'doctor') {
        $appointment = Appointment::query()->findOrFail($session->appointment_id);
        if ($appointment->status === Appointment::STATUS_CONFIRMED) {
            $appointment = $this->appointments->checkIn($appointment, $user->id);
        }
        if ($appointment->status === Appointment::STATUS_CHECKED_IN) {
            $this->appointments->transitionStatus(
                $appointment,
                Appointment::STATUS_IN_CONSULTATION,
                $user->id,
                'Assigned doctor joined telehealth consultation'
            );
        }
        $this->audit->log('TELEHEALTH_DOCTOR_JOINED', 'telehealth_session', $session->id, 'SUCCESS', [
            'appointment_id' => $session->appointment_id,
        ], $user->id);
    }

    $this->telehealth->join($session, $user->id, $role, true);
    $this->audit->log('TELEHEALTH_JOIN_ALLOWED', 'telehealth_session', $session->id, 'SUCCESS', [
        'actor_role' => $role,
        'provider' => $details['provider'],
    ], $user->id);

    return view('telehealth.join', [
        'session' => $session->fresh(['appointment.patient', 'appointment.provider', 'appointment.appointmentType']),
        'role' => $role,
        'demoMode' => $details['provider'] === 'jitsi_demo',
        'videoDetails' => $details,
        'joined' => true,
    ]);
}

public function leave(TelehealthSession $session, Request $request)
{
    $session->loadMissing(['appointment.patient', 'appointment.provider']);
    $user = $request->user();
    $role = $this->authorizedParticipantRole($session, $user);
    if (! $role) {
        $this->denyJoin($session, $user, 'not_appointment_patient_or_assigned_doctor');
    }
    $endSession = $request->boolean('end_session');
    if ($endSession && $role !== 'doctor') {
        $this->denyJoin($session, $user, 'only_assigned_doctor_can_end_session');
    }

    $participant = TelehealthParticipant::query()
        ->where('telehealth_session_id', $session->id)
        ->where('user_id', $user->id)
        ->where('role', $role)
        ->whereNull('left_at')
        ->latest('joined_at')
        ->first();
    if (! $participant) {
        $this->denyJoin($session, $user, 'participant_not_joined');
    }
    $participant->update(['left_at' => now()]);

    $this->audit->log('TELEHEALTH_PARTICIPANT_LEFT', 'telehealth_session', $session->id, 'SUCCESS', [
        'actor_role' => $role,
    ], $user->id);

    if ($endSession) {
        $this->telehealth->complete($session);
        TelehealthParticipant::query()
            ->where('telehealth_session_id', $session->id)
            ->whereNull('left_at')
            ->update(['left_at' => now()]);
        $this->audit->log('TELEHEALTH_SESSION_ENDED', 'telehealth_session', $session->id, 'SUCCESS', [
            'ended_by_role' => $role,
        ], $user->id);
    }

    $destination = $role === 'patient'
        ? route('patients.portal.telehealth')
        : route('telehealth.index');

    return redirect($destination)->with('status', $endSession ? 'Consultation ended.' : 'You left the consultation.');
}

private function authorizedParticipantRole(TelehealthSession $session, $user): ?string
{
    if (! $user) {
        return null;
    }
    if ($user->hasRole('patient') && (int) $user->patient?->id === (int) $session->appointment?->patient_id) {
        return 'patient';
    }
    if ($user->hasRole('doctor') && (int) $user->provider?->id === (int) $session->appointment?->provider_id) {
        return 'doctor';
    }

    return null;
}

private function denyJoin(TelehealthSession $session, $user, string $reason, int $status = 403): never
{
    $this->audit->log('TELEHEALTH_JOIN_DENIED', 'telehealth_session', $session->id, 'FAILURE', [
        'reason' => $reason,
    ], $user?->id);
    abort($status, match ($reason) {
        'too_early' => 'You can join starting '.(int) config('telehealth.join_early_minutes', 15).' minutes before the appointment.',
        'too_late' => 'The telehealth joining window has ended. Contact the hospital for help.',
        'doctor_not_started' => 'Please wait. Your doctor has not started this consultation yet.',
        'appointment_not_confirmed' => 'This appointment is not confirmed for telehealth.',
        'session_not_joinable' => 'This telehealth session is no longer available.',
        default => 'You are not authorized to join this telehealth session.',
    });
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
            'start_time' => ! empty($data['start_time']) ? Carbon::parse($data['start_time']) : $session->start_time,
            'duration' => $data['duration'] ?? $session->duration,
        ]);

        try {
            $createdSession = $this->telehealth->createSession($session->appointment);
        } catch (\RuntimeException $exception) {
            report($exception);

            if ($request->expectsJson() || $request->wantsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Telehealth is not configured. Configure a live provider or explicitly select demo mode.',
                ], 503);
            }

            return back()->with('error', 'The video consultation could not be prepared. Please try again or contact support.');
        }
        if ($this->telehealth->providerName() === 'jitsi_demo') {
            return back()->with('warning', 'Demo telehealth session prepared. This mode is not for real patient consultations.');
        }

        $message = $createdSession->zoom_meeting_id
            ? 'Zoom meeting created successfully.'
            : 'The telehealth provider did not create a meeting.';

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
