<?php

namespace App\Http\Controllers;

use App\Models\Admission;
use App\Models\Appointment;
use App\Models\AppointmentReassignment;
use App\Models\AuditLog;
use App\Models\Bed;
use App\Models\CareRequest;
use App\Models\DataSubjectRequest;
use App\Models\ErVisit;
use App\Models\Patient;
use App\Models\TelehealthSession;
use Illuminate\Http\Request;
use App\Services\AiEvaluationService;

class DashboardController extends Controller
{
    public function __invoke(Request $request, AiEvaluationService $evaluations)
    {
        $user = $request->user();
        $isSuperAdmin = $user->hasRole('super-admin');
        $providerId = $user->provider?->id;

        $data = [
            'currentRole' => $isSuperAdmin ? 'super-admin' : ($user->roles()->first()?->name ?? 'guest'),
        ];

        if ($user->hasAnyRole(['doctor', 'nurse', 'registration', 'admin', 'super-admin', 'patient'])) {
            $todayAppointmentsQuery = Appointment::query()->whereDate('starts_at', today());
            if ($user->hasRole('doctor')) {
                $todayAppointmentsQuery->where('provider_id', $providerId ?? 0);
            } elseif ($user->hasRole('patient')) {
                $todayAppointmentsQuery->where('patient_id', $user->patient?->id ?? 0);
            }
            $data['appointmentsTodayCount'] = $todayAppointmentsQuery->count();
        }

        if ($user->hasAnyRole(['registration', 'admin', 'super-admin'])) {
            $data['openPrivacyRequestCount'] = DataSubjectRequest::query()
                ->whereIn('status', [DataSubjectRequest::STATUS_SUBMITTED, DataSubjectRequest::STATUS_IN_REVIEW])
                ->count();
        }

        if ($user->hasAnyRole(['doctor', 'admin', 'super-admin'])) {
            $data['averageTriageAgreement'] = $evaluations->metrics([], $user)['agreement_rate'] ?? null;
        }

        if ($user->hasAnyRole(['doctor', 'nurse', 'registration', 'admin', 'super-admin'])) {
            $since = today()->subWeeks(7)->startOfWeek();
            $appointmentQuery = Appointment::query()
                ->whereBetween('starts_at', [$since, now()->endOfWeek()]);
            if ($user->hasRole('doctor')) {
                $appointmentQuery->where('provider_id', $providerId ?? 0);
            }
            $weeklyRows = $appointmentQuery->get(['starts_at', 'status']);
            $weeks = collect(range(0, 7))->map(fn (int $offset) => $since->copy()->addWeeks($offset)->format('o-\\WW'));
            $statusNames = [
                Appointment::STATUS_PENDING,
                Appointment::STATUS_CONFIRMED,
                Appointment::STATUS_CHECKED_IN,
                Appointment::STATUS_IN_CONSULTATION,
                Appointment::STATUS_COMPLETED,
                Appointment::STATUS_CANCELLED,
                Appointment::STATUS_NO_SHOW,
                Appointment::STATUS_REJECTED,
            ];
            $data['appointmentChart'] = $weeks->mapWithKeys(fn (string $week) => [
                $week => collect($statusNames)->mapWithKeys(fn (string $status) => [
                    $status => $weeklyRows->filter(fn (Appointment $appointment): bool =>
                        $appointment->starts_at->format('o-\\WW') === $week && $appointment->status === $status
                    )->count(),
                ]),
            ]);
            $data['triageUrgencyChart'] = $evaluations->metrics([], $user)['urgency_counts'];
            $consultationQuery = Appointment::query()
                ->with('appointmentType:id,telehealth')
                ->whereBetween('starts_at', [$since, now()->endOfWeek()]);
            if ($user->hasRole('doctor')) {
                $consultationQuery->where('provider_id', $providerId ?? 0);
            }
            $data['consultationTypeChart'] = $consultationQuery->get()
                ->groupBy(fn (Appointment $appointment): string =>
                    $appointment->appointmentType?->telehealth ? 'Virtual' : 'In-person'
                )->map->count();
        }

        if ($user->hasAnyRole(['registration', 'admin', 'super-admin'])) {
            $data['openReassignmentCount'] = AppointmentReassignment::query()
                ->whereIn('status', [
                    AppointmentReassignment::STATUS_PENDING_REVIEW,
                    AppointmentReassignment::STATUS_DECLINED_BY_PATIENT,
                ])
                ->count();
        }
        if ($user->hasAnyRole(['registration', 'nurse', 'admin', 'super-admin'])) {
            $data['pendingAppointmentApprovals'] = Appointment::query()
                ->where('status', Appointment::STATUS_PENDING)
                ->count();
        }

        if ($isSuperAdmin) {
            $data += [
                'todayPatients' => Patient::whereDate('created_at', today())->count(),
                'todayAppointments' => Appointment::whereDate('starts_at', today())->count(),
                'erPatients' => ErVisit::whereDate('arrived_at', today())->count(),
                'availableBeds' => Bed::where('status', Bed::STATUS_AVAILABLE)->count(),
                'occupiedBeds' => Bed::where('status', Bed::STATUS_OCCUPIED)->count(),
                'telehealthAppointments' => TelehealthSession::whereDate('start_time', today())->count(),
                'recentAppointments' => Appointment::with(['patient', 'provider', 'appointmentType'])
                    ->orderBy('starts_at', 'desc')
                    ->limit(10)
                    ->get(),
                'followUpDue' => \App\Models\Encounter::with('patient')
                    ->whereNotNull('follow_up_date')
                    ->whereDate('follow_up_date', '<=', now()->addDays(7)->toDateString())
                    ->orderBy('follow_up_date', 'asc')
                    ->limit(5)
                    ->get(),
                'erQueue' => \App\Models\ErQueue::with(['erVisit.patient'])
                    ->orderByRaw("CASE priority
                        WHEN 'Level 1' THEN 1
                        WHEN 'Level 2' THEN 2
                        WHEN 'Level 3' THEN 3
                        WHEN 'Level 4' THEN 4
                        WHEN 'Level 5' THEN 5
                        ELSE 99
                    END")
                    ->orderBy('queued_at')
                    ->limit(10)
                    ->get(),
                'recentAuditLogs' => AuditLog::with('user')
                    ->orderBy('created_at', 'desc')
                    ->limit(10)
                    ->get(),
                'bedOccupancy' => Bed::selectRaw('status, count(*) as total')
                    ->groupBy('status')
                    ->pluck('total', 'status'),
            ];
        }

        // Role-specific data
        if ($user->hasRole('doctor')) {
            $data['myAppointments'] = $providerId
                ? Appointment::with(['patient', 'appointmentType'])->where('provider_id', $providerId)->whereDate('starts_at', today())->get()
                : collect();
            $data['myEncounters'] = $providerId
                ? \App\Models\Encounter::with('patient')->where('provider_id', $providerId)->orderBy('started_at', 'desc')->limit(10)->get()
                : collect();
            $data['myPatientCount'] = $providerId
                ? \App\Models\Encounter::where('provider_id', $providerId)->whereDate('started_at', today())->distinct('patient_id')->count('patient_id')
                : 0;
            if (! $isSuperAdmin) {
                $data['followUpDue'] = $providerId
                    ? \App\Models\Encounter::with('patient')
                        ->where('provider_id', $providerId)
                        ->whereNotNull('follow_up_date')
                        ->whereDate('follow_up_date', '<=', now()->addDays(7)->toDateString())
                        ->orderBy('follow_up_date', 'asc')
                        ->limit(5)
                        ->get()
                    : collect();
            }
        }

        if ($user->hasRole('patient')) {
            $patient = $user->patient;
            $data['patientReassignmentCount'] = $patient
                ? AppointmentReassignment::query()
                    ->whereIn('status', [
                        AppointmentReassignment::STATUS_PENDING_REVIEW,
                        AppointmentReassignment::STATUS_DECLINED_BY_PATIENT,
                        AppointmentReassignment::STATUS_WAITLISTED,
                    ])
                    ->whereHas('appointment', fn ($query) => $query->where('patient_id', $patient->id))
                    ->count()
                : 0;
            $data['myAppointments'] = $patient
                ? Appointment::with('provider')->where('patient_id', $patient->id)->orderBy('starts_at', 'desc')->limit(10)->get()
                : collect();
            $data['myNotifications'] = $user->notifications->take(10);
            $data['myFollowUps'] = $patient
                ? \App\Models\Encounter::with('provider')->where('patient_id', $patient->id)->whereNotNull('follow_up_date')->orderBy('follow_up_date', 'asc')->limit(5)->get()
                : collect();
        }

        if ($user->hasRole('nurse')) {
            $data['triageQueue'] = \App\Models\ErQueue::with(['erVisit.patient'])
                ->where('status', \App\Models\ErQueue::STATUS_WAITING)
                ->orderByRaw("CASE priority
                    WHEN 'Level 1' THEN 1
                    WHEN 'Level 2' THEN 2
                    WHEN 'Level 3' THEN 3
                    WHEN 'Level 4' THEN 4
                    WHEN 'Level 5' THEN 5
                    ELSE 99
                END")
                ->orderBy('queued_at')
                ->limit(10)
                ->get();
            $data['pendingAdmissions'] = Admission::with('patient')
                ->whereIn('status', [Admission::STATUS_REQUESTED, Admission::STATUS_APPROVED])
                ->limit(10)
                ->get();
        }

        if ($user->hasRole('registration')) {
            $data['registrationDeskQueue'] = Appointment::with(['patient', 'provider'])
                ->whereDate('starts_at', today())
                ->orderBy('starts_at', 'asc')
                ->limit(10)
                ->get();
            $data['checkedInToday'] = Appointment::whereDate('starts_at', today())
                ->where('status', 'CHECKED_IN')
                ->count();
        }

        if ($user->hasRole('super-admin')) {
            $data['systemAlerts'] = [
                'Acuity board active',
                'Bed assignments updated',
                'Daily reporting available',
            ];
        }

        if ($user->hasAnyRole(['super-admin', 'nurse'])) {
            $data['pendingAdmissions'] = Admission::with('patient')
                ->whereIn('status', [Admission::STATUS_REQUESTED, Admission::STATUS_APPROVED])
                ->limit(10)
                ->get();
        }

        return view('dashboard', $data);
    }
}
