<?php

namespace App\Http\Controllers;

use App\Models\TriageAssessment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DoctorQueueController extends Controller
{
    public function index(Request $request): View
    {
        $user = auth()->user();
        $limitedQueue = $user->hasAnyRole(['registration', 'nurse']);
        $query = TriageAssessment::query()->whereNotNull('patient_id');

        if ($limitedQueue) {
            $query->select([
                'id', 'patient_id', 'er_visit_id', 'status', 'priority_score', 'triaged_at',
                'created_at', 'updated_at',
            ])->with(['patient:id,first_name,last_name,mrn']);
        } else {
            $query->with(['patient', 'vitals']);
        }

        if ($user->hasRole('doctor')) {
            $providerId = $user->provider?->id;
            $query->whereIn('status', ['WAITING', 'SEEN', 'IN_CONSULT'])
                ->whereNotNull('priority_score');

            if ($providerId === null) {
                $query->whereRaw('0 = 1');
            } else {
                $query->whereHas('erVisit.queue', fn ($queueQuery) => $queueQuery->where('provider_id', $providerId));
            }
        }

        if ($request->filled('q')) {
            $term = trim($request->q);
            $query->where(function ($patientQuery) use ($term) {
                $patientQuery->whereHas('patient', function ($patient) use ($term) {
                    $patient->where(function ($nameQuery) use ($term) {
                        $nameQuery->where('first_name', 'like', "%{$term}%")
                            ->orWhere('last_name', 'like', "%{$term}%")
                            ->orWhere('mrn', 'like', "%{$term}%");
                    });
                });
            });
        }

        if ($request->filled('priority')) {
            $query->where('priority_score', (int) $request->priority);
        }

        $statusFilter = $request->input('status');
        if ($statusFilter !== null && $statusFilter !== '') {
            $statusValue = strtolower((string) $statusFilter);
            if (in_array($statusValue, ['live', 'active'], true)) {
                $query->whereIn('status', ['WAITING', 'SEEN', 'IN_CONSULT']);
            } else {
                $query->where('status', $statusFilter);
            }
        }

        $queue = $query->orderByRaw("CASE
                WHEN priority_score IS NULL THEN 99
                WHEN priority_score = 1 THEN 1
                WHEN priority_score = 2 THEN 2
                WHEN priority_score = 3 THEN 3
                WHEN priority_score = 4 THEN 4
                ELSE 5
            END ASC")
            ->orderByDesc('triaged_at')
            ->get();

        $summary = [
            'level_1' => $queue->where('priority_score', 1)->count(),
            'level_2' => $queue->where('priority_score', 2)->count(),
            'level_3' => $queue->where('priority_score', 3)->count(),
            'total' => $queue->count(),
            'live' => $queue->filter(fn ($item) => in_array($item->status, ['WAITING', 'SEEN', 'IN_CONSULT'], true))->count(),
        ];

        return view('doctors.queue', compact('queue', 'summary', 'limitedQueue'));
    }

    public function show(Request $request, TriageAssessment $triageAssessment): RedirectResponse
    {
        $this->authorizeDoctorQueueAssessment($request, $triageAssessment);

        return redirect()->route('doctors.queue', ['detail' => $triageAssessment->getKey()]);
    }

    public function updateStatus(Request $request, TriageAssessment $triageAssessment): RedirectResponse
    {
        $this->authorizeDoctorQueueAssessment($request, $triageAssessment);
        $status = $request->validate([
            'status' => ['required', 'in:SEEN,IN_CONSULT,COMPLETED'],
        ])['status'];

        $triageAssessment->update([
            'status' => $status,
        ]);

        $label = match ($status) {
            'SEEN' => 'seen',
            'IN_CONSULT' => 'consult started',
            'COMPLETED' => 'consult completed',
            default => 'updated',
        };

        return back()->with('success', 'Patient marked as ' . $label . '.');
    }

    private function authorizeDoctorQueueAssessment(Request $request, TriageAssessment $assessment): void
    {
        $user = $request->user();
        abort_unless($user->hasAnyRole(['doctor', 'registration', 'super-admin']), 403);

        if ($user->hasRole('doctor')) {
            $providerId = $user->provider?->id;
            abort_unless(
                $providerId !== null
                && $assessment->erVisit()
                    ->whereHas('queue', fn ($queueQuery) => $queueQuery->where('provider_id', $providerId))
                    ->exists(),
                404
            );
        }
    }
}
