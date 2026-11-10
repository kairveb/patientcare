<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTriageDecisionRequest;
use App\Models\ErVisit;
use App\Models\Patient;
use App\Models\TriageAssessment;
use App\Services\EmergencyAlertService;
use App\Services\TriageService;
use App\Support\TriageDecisionRules;
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

        $symptoms = $request->normalizedSymptoms();
        $result = $request->aiAnalysis();
        $finalPriority = $request->finalLevel();
        $finalNotes = $data['notes'] ?? $result['recommendation'];

        $visit = ErVisit::firstOrCreate(
            [
                'patient_id' => $data['patient_id'],
                'chief_complaint' => $data['chief_complaint'],
            ],
            [
                'visit_number' => $this->triage->generateVisitNumber(),
                'arrived_at' => now(),
                'arrival_method' => 'Walk-in',
                'referral_details' => $data['notes'] ?? null,
                'status' => ErVisit::STATUS_ARRIVED,
                'created_by' => auth()->id(),
            ]
        );
        $newVisit = $visit->wasRecentlyCreated;

        $vitals = array_filter([
            'blood_pressure' => $data['blood_pressure'] ?? null,
            'heart_rate' => $data['heart_rate'] ?? null,
            'respiratory_rate' => $data['respiratory_rate'] ?? null,
            'temperature' => $data['temperature'] ?? null,
            'spo2' => $data['spo2'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');
        $assessment = $this->triage->assess($visit, [
            'patient_id' => $data['patient_id'],
            'chief_complaint' => $data['chief_complaint'],
            'symptoms' => $symptoms,
            'pain_score' => $data['pain_score'] ?? null,
            'gcs' => $data['gcs'] ?? null,
            'priority' => TriageDecisionRules::priorityCode($finalPriority),
            'notes' => $finalNotes . (empty($data['notes']) ? '' : ' | AI rationale: ' . implode(' ', $result['reasons'])),
            'provider_id' => $data['provider_id'] ?? null,
            'treatment_area' => $data['treatment_area'] ?? null,
            'vitals' => $vitals,
        ]);
        $assessment = $this->triage->recordDecision(
            $assessment,
            $result,
            $finalPriority,
            $request->overrideReason(),
            $request->user()
        );

        if ($newVisit) {
            $this->emergencyAlerts->visitCreated();
        }

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

        return redirect()->route('triage.er-intake', $assessment)->with('success', 'AI triage complete. Priority: ' . $finalPriority . ' (' . ucfirst(TriageDecisionRules::colorForLevel($finalPriority)) . ')');
    }

}
