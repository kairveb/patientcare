<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTriageDecisionRequest;
use App\Models\ErVisit;
use App\Models\Patient;
use App\Models\TriageAssessment;
use App\Services\EmergencyAlertService;
use App\Services\TriageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class TriageAssessmentController extends Controller
{
    public function __construct(
        protected EmergencyAlertService $emergencyAlerts,
        protected TriageService $triage,
    ) {
    }

    public function create(): View
    {
        $patients = Patient::orderBy('last_name')->get();

        return view('triage.create', compact('patients'));
    }

    public function store(StoreTriageDecisionRequest $request): RedirectResponse|JsonResponse
    {
        $data = $request->validated();

        $symptoms = array_values(array_filter(array_map('trim', preg_split('/[,;\n]/', (string) ($data['symptoms'] ?? '')))));
        $result = $request->aiAnalysis();
        $finalPriority = $request->finalLevel();
        $finalNotes = $data['notes'] ?? $result['recommendation'];

        $visit = ErVisit::firstOrCreate(
            [
                'patient_id' => $data['patient_id'],
                'chief_complaint' => $data['chief_complaint'],
            ],
            [
                'visit_number' => app(\App\Services\TriageService::class)->generateVisitNumber(),
                'arrived_at' => now(),
                'arrival_method' => 'Walk-in',
                'referral_details' => $data['notes'] ?? null,
                'status' => ErVisit::STATUS_ARRIVED,
                'created_by' => auth()->id(),
            ]
        );
        $newVisit = $visit->wasRecentlyCreated;

        $assessment = TriageAssessment::updateOrCreate(
            ['er_visit_id' => $visit->id],
            [
                'patient_id' => $data['patient_id'],
                'triage_nurse_id' => auth()->id(),
                'triaged_at' => now(),
                'chief_complaint' => $data['chief_complaint'],
                'symptoms' => $symptoms,
                'pain_score' => $data['pain_score'] ?? null,
                'gcs' => $data['gcs'] ?? null,
                'priority' => $finalPriority,
                'priority_score' => $this->priorityScoreFor($finalPriority),
                'triage_color' => $this->priorityColorFor($finalPriority),
                'notes' => $finalNotes . (empty($data['notes']) ? '' : ' | AI rationale: ' . implode(' ', $result['reasons'])),
                'status' => 'COMPLETE',
            ]
        );
        $assessment = $this->triage->recordDecision(
            $assessment,
            $result,
            $finalPriority,
            $request->overrideReason(),
            $request->user()
        );

        $assessment->vitals()->updateOrCreate(
            ['triage_assessment_id' => $assessment->id],
            [
                'patient_id' => $data['patient_id'],
                'blood_pressure' => $data['blood_pressure'] ?? null,
                'heart_rate' => $data['heart_rate'] ?? null,
                'respiratory_rate' => $data['respiratory_rate'] ?? null,
                'temperature' => $data['temperature'] ?? null,
                'spo2' => $data['spo2'] ?? null,
                'recorded_at' => now(),
            ]
        );

        if ($newVisit) {
            $this->emergencyAlerts->visitCreated();
        }
        $this->emergencyAlerts->triageRecorded($assessment);

        if ($request->expectsJson()) {
            $assessment->load(['vitals', 'erVisit']);
            $prefill = [
                'triage_assessment_id' => $assessment->id,
                'patient_id' => $assessment->patient_id,
                'chief_complaint' => $assessment->chief_complaint,
                'symptoms' => is_array($assessment->symptoms) ? implode(', ', $assessment->symptoms) : $assessment->symptoms,
                'pain_score' => $assessment->pain_score,
                'blood_pressure' => $assessment->vitals?->blood_pressure,
                'heart_rate' => $assessment->vitals?->heart_rate,
                'respiratory_rate' => $assessment->vitals?->respiratory_rate,
                'temperature' => $assessment->vitals?->temperature,
                'spo2' => $assessment->vitals?->spo2 !== null ? (int) round((float) $assessment->vitals->spo2) : null,
                'referral_details' => $assessment->notes,
                'arrival_method' => $assessment->erVisit?->arrival_method,
                'arrived_at' => $assessment->erVisit?->arrived_at?->format('Y-m-d\TH:i') ?? now()->format('Y-m-d\TH:i'),
            ];

            return response()->json([
                'success' => true,
                'step' => 2,
                'assessment_id' => $assessment->id,
                'visit_id' => $assessment->er_visit_id,
                'html' => view('emergency.partials.intake-form', [
                    'patients' => Patient::orderBy('last_name')->get(),
                    'prefill' => $prefill,
                ])->render(),
            ]);
        }

        return redirect()->route('triage.er-intake', $assessment)->with('success', 'AI triage complete. Priority: ' . $finalPriority . ' (' . ucfirst($this->priorityColorFor($finalPriority)) . ')');
    }

    protected function priorityScoreFor(string $priority): int
    {
        return match (strtolower(trim($priority))) {
            'emergency' => 1,
            'urgent' => 2,
            'prompt' => 3,
            'non-urgent' => 4,
            default => 5,
        };
    }

    protected function priorityColorFor(string $priority): string
    {
        return match (strtolower(trim($priority))) {
            'emergency' => 'red',
            'urgent' => 'yellow',
            'prompt' => 'orange',
            default => 'green',
        };
    }
}
