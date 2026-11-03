<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReviewSymptomCheck;
use App\Models\Department;
use App\Models\SymptomCheck;
use App\Models\Provider;
use App\Services\AiEvaluationService;
use App\Services\AuditLogService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;

class AiEvaluationController extends Controller
{
    public function index(Request $request, AiEvaluationService $evaluations): View
    {
        $filters = $request->validate([
            'start' => ['nullable', 'date_format:Y-m-d'],
            'end' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'doctor_id' => ['nullable', 'integer', 'exists:providers,id'],
        ]);
        if (isset($filters['department_id'])) {
            $filters['department_name'] = Department::query()->findOrFail($filters['department_id'])->name;
        }
        $metrics = $evaluations->metrics($filters, $request->user());
        $start = $filters['start'] ?? today()->subDays(30)->toDateString();
        $end = $filters['end'] ?? today()->toDateString();
        $checksQuery = SymptomCheck::query()
            ->with('patient:id,reference_code')
            ->whereBetween('created_at', [
                CarbonImmutable::parse($start)->startOfDay(),
                CarbonImmutable::parse($end)->endOfDay(),
            ]);

        if (isset($filters['department_name'])) {
            $checksQuery->where('recommended_department', $filters['department_name']);
        }
        if ($request->user()->hasRole('doctor')) {
            $providerId = $request->user()->provider?->id ?? 0;
            $checksQuery->whereHas('patient.encounters', fn ($query) => $query->where('provider_id', $providerId));
        }
        if (isset($filters['doctor_id'])) {
            $reviewerId = Provider::query()->whereKey((int) $filters['doctor_id'])->value('user_id');
            $checksQuery->where('clinician_reviewed_by', $reviewerId ?? 0);
        }

        $checks = $checksQuery->latest()->limit(50)->get();

        return view('reports.ai-evaluation', compact('metrics', 'checks', 'start', 'end'));
    }

    public function review(
        ReviewSymptomCheck $request,
        SymptomCheck $symptomCheck,
        AuditLogService $audit
    ): RedirectResponse {
        $data = $request->validated();
        $symptomCheck->forceFill([
            'clinician_final_urgency' => $data['clinician_final_urgency'],
            'clinician_agreement' => $data['clinician_agreement'],
            'clinician_reviewed_by' => $request->user()->id,
            'clinician_reviewed_at' => now(),
        ])->save();
        $audit->log('REVIEW_AI_TRIAGE', 'symptom_check', $symptomCheck->id, 'SUCCESS', [
            'clinician_final_urgency' => $data['clinician_final_urgency'],
            'clinician_agreement' => $data['clinician_agreement'],
        ], $request->user()->id);

        return back()->with('success', 'Clinician evaluation recorded.');
    }
}
