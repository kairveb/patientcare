<?php

namespace App\Services;

use App\Models\Admission;
use App\Models\Appointment;
use App\Models\Bed;
use App\Models\Encounter;
use App\Models\ErQueue;
use App\Models\ErVisit;
use App\Models\Patient;
use App\Models\TelehealthSession;
use App\Models\User;

class DashboardDataService
{
    public function forUser(User $user): array
    {
        $role = $user->hasRole('super-admin')
            ? 'super-admin'
            : ($user->roles()->value('name') ?? 'guest');
        $patient = $role === 'patient' ? $user->patient : null;
        $summary = [];
        $items = [
            'appointments' => [],
            'encounters' => [],
            'queue' => [],
            'admissions' => [],
            'patient_count' => 0,
        ];

        if ($role === 'super-admin') {
            $summary = $this->systemSummary();
            $items['appointments'] = $this->appointmentItems(
                Appointment::with(['patient', 'provider'])->whereDate('starts_at', today())->limit(5)->get(),
                'Admin'
            );
            $items['queue'] = $this->queueItems();
            $items['admissions'] = $this->admissionItems();
        } elseif ($role === 'doctor') {
            $providerId = $user->provider?->id;
            $appointments = Appointment::with(['patient', 'provider'])
                ->where('provider_id', $providerId ?? 0)
                ->whereDate('starts_at', today());
            $encounters = Encounter::with('patient')->where('provider_id', $providerId ?? 0);
            $summary = [
                'appointments_today' => (clone $appointments)->count(),
                'patients_today' => (clone $encounters)->whereDate('started_at', today())->distinct('patient_id')->count('patient_id'),
                'follow_up_due' => (clone $encounters)
                    ->whereNotNull('follow_up_date')
                    ->whereDate('follow_up_date', '<=', now()->addDays(7)->toDateString())
                    ->count(),
            ];
            $items['appointments'] = $this->appointmentItems((clone $appointments)->limit(5)->get(), 'Appointment');
            $items['encounters'] = (clone $encounters)
                ->orderByDesc('started_at')
                ->limit(3)
                ->get()
                ->map(fn (Encounter $encounter) => [
                    'id' => $encounter->id,
                    'title' => $encounter->patient?->full_name ?? 'Patient',
                    'detail' => 'Encounter updated',
                    'time' => $encounter->started_at?->format('M d, Y'),
                    'module' => 'Encounter',
                ])
                ->values()
                ->all();
            $items['patient_count'] = $summary['patients_today'];
        } elseif ($role === 'nurse') {
            $summary = [
                'triage_queue' => ErQueue::where('status', ErQueue::STATUS_WAITING)->count(),
                'admissions_pending' => Admission::whereIn('status', [Admission::STATUS_REQUESTED, Admission::STATUS_APPROVED])->count(),
            ];
            $items['queue'] = $this->queueItems();
            $items['admissions'] = $this->admissionItems();
        } elseif ($role === 'registration') {
            $appointments = Appointment::with(['patient', 'provider'])->whereDate('starts_at', today());
            $summary = [
                'registration_queue' => (clone $appointments)->count(),
                'checked_in_today' => (clone $appointments)->where('status', Appointment::STATUS_CHECKED_IN)->count(),
            ];
            $items['appointments'] = $this->appointmentItems((clone $appointments)->limit(5)->get(), 'Registration');
        } elseif ($role === 'patient') {
            $appointments = $patient
                ? Appointment::with(['patient', 'provider'])
                    ->where('patient_id', $patient->id)
                    ->whereDate('starts_at', '>=', today())
                : Appointment::query()->whereRaw('0 = 1');
            $summary = [
                'appointments_today' => $patient
                    ? Appointment::where('patient_id', $patient->id)->whereDate('starts_at', today())->count()
                    : 0,
                'upcoming_appointments' => (clone $appointments)->count(),
                'unread_notifications' => 2,
            ];
            $items['queue'] = (clone $appointments)
                ->orderBy('starts_at')
                ->limit(3)
                ->get()
                ->map(fn (Appointment $appointment) => [
                    'title' => $appointment->provider?->user?->name ?? 'Care Team',
                    'detail' => $appointment->starts_at?->format('M d, Y h:i A'),
                    'time' => 'Upcoming',
                    'module' => 'Care',
                ])
                ->values()
                ->all();
        }

        $data = [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $role,
                'roles' => $user->roles->pluck('name')->values(),
            ],
            'summary' => $summary,
            'items' => $items,
        ];

        if ($role === 'super-admin') {
            $data['overview'] = $this->systemOverview();
        }

        return $data;
    }

    private function systemSummary(): array
    {
        return [
            'appointments_today' => Appointment::whereDate('starts_at', today())->count(),
            'patients_today' => Patient::whereDate('created_at', today())->count(),
            'er_patients' => ErVisit::whereDate('arrived_at', today())->count(),
            'available_beds' => Bed::where('status', Bed::STATUS_AVAILABLE)->count(),
            'occupied_beds' => Bed::where('status', Bed::STATUS_OCCUPIED)->count(),
            'telehealth_appointments' => TelehealthSession::whereDate('start_time', today())->count(),
            'follow_up_due' => Encounter::whereNotNull('follow_up_date')
                ->whereDate('follow_up_date', '<=', now()->addDays(7)->toDateString())
                ->count(),
        ];
    }

    private function systemOverview(): array
    {
        return [
            'today_patients' => Patient::whereDate('created_at', today())->count(),
            'today_appointments' => Appointment::whereDate('starts_at', today())->count(),
            'er_patients' => ErVisit::whereDate('arrived_at', today())->count(),
            'available_beds' => Bed::where('status', Bed::STATUS_AVAILABLE)->count(),
            'occupied_beds' => Bed::where('status', Bed::STATUS_OCCUPIED)->count(),
            'telehealth_appointments' => TelehealthSession::whereDate('start_time', today())->count(),
        ];
    }

    private function appointmentItems($appointments, string $module): array
    {
        return $appointments->map(fn (Appointment $appointment) => [
            'id' => $appointment->id,
            'title' => $appointment->patient?->full_name ?? 'Patient',
            'detail' => $appointment->provider?->user?->name ?? 'Provider',
            'time' => $appointment->starts_at?->format('h:i A'),
            'module' => $module,
        ])->values()->all();
    }

    private function queueItems(): array
    {
        return ErQueue::with(['erVisit.patient'])
            ->where('status', ErQueue::STATUS_WAITING)
            ->orderBy('queued_at')
            ->limit(4)
            ->get()
            ->map(fn (ErQueue $queueItem) => [
                'title' => $queueItem->erVisit?->patient?->full_name ?? 'Patient',
                'detail' => 'Awaiting triage',
                'time' => $queueItem->queued_at?->format('h:i A'),
                'module' => 'ER',
            ])
            ->values()
            ->all();
    }

    private function admissionItems(): array
    {
        return Admission::with('patient')
            ->whereIn('status', [Admission::STATUS_REQUESTED, Admission::STATUS_APPROVED])
            ->orderByDesc('created_at')
            ->limit(4)
            ->get()
            ->map(fn (Admission $admission) => [
                'title' => $admission->patient?->full_name ?? 'Patient',
                'detail' => 'Admission pending review',
                'time' => $admission->created_at?->format('M d'),
                'module' => 'Admission',
            ])
            ->values()
            ->all();
    }
}
