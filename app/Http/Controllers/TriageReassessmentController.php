<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConfirmTriageReassessmentRequest;
use App\Http\Requests\StoreTriageReassessmentRequest;
use App\Models\ErVisit;
use App\Models\TriageAssessment;
use App\Models\TriageReassessment;
use App\Services\AuditLogService;
use App\Services\EmergencyAlertService;
use App\Services\TriageService;
use App\Support\TriageDecisionRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class TriageReassessmentController extends Controller
{
    public function __construct(
        protected TriageService $triage,
        protected AuditLogService $audit,
        protected EmergencyAlertService $emergencyAlerts
    ) {}

    public function store(StoreTriageReassessmentRequest $request, ErVisit $visit): RedirectResponse|JsonResponse
    {
        $data = $request->validated();

        $reassessment = DB::transaction(function () use ($request, $visit, $data): TriageReassessment {
            $lockedVisit = ErVisit::query()->lockForUpdate()->findOrFail($visit->id);
            $this->ensureVisitActive($lockedVisit);

            $assessment = TriageAssessment::query()
                ->where('er_visit_id', $lockedVisit->id)
                ->lockForUpdate()
                ->first();
            if (! $assessment) {
                throw ValidationException::withMessages([
                    'triage_assessment' => 'Complete the initial triage assessment before recording new vitals.',
                ]);
            }

            $currentFinalLevel = TriageDecisionRules::normalizeLevel(
                (string) ($assessment->final_level ?: $assessment->priority)
            );
            $vitals = $this->normalizeVitals($data['vitals']);
            $analysis = $this->triage->score([
                'patient_id' => $lockedVisit->patient_id,
                'chief_complaint' => $assessment->chief_complaint ?? $lockedVisit->chief_complaint,
                'symptoms' => $assessment->symptoms ?? [],
                'pain_score' => $assessment->pain_score,
                'vitals' => $vitals,
            ]);
            $moreSevere = $this->levelRank($analysis['priority']) < $this->levelRank($currentFinalLevel);

            $reassessment = TriageReassessment::create([
                'er_visit_id' => $lockedVisit->id,
                'triage_assessment_id' => $assessment->id,
                'recorded_by' => $request->user()->id,
                'recorded_at' => now(),
                'vitals' => $vitals,
                'ai_suggested_level' => $analysis['priority'],
                'ai_confidence' => $analysis['confidence'] ?? null,
                'ai_reasons' => $analysis['reasons'] ?? [],
                'current_final_level' => $currentFinalLevel,
                'more_severe' => $moreSevere,
                'status' => TriageReassessment::STATUS_PENDING,
            ]);

            $this->audit->log(
                'CREATE_TRIAGE_REASSESSMENT',
                'triage_reassessment',
                $reassessment->id,
                'SUCCESS',
                [
                    'er_visit_id' => $lockedVisit->id,
                    'ai_suggested_level' => $analysis['priority'],
                    'current_final_level' => $currentFinalLevel,
                    'more_severe' => $moreSevere,
                ],
                $request->user()->id
            );

            return $reassessment;
        });

        if ($reassessment->more_severe) {
            $this->emergencyAlerts->triageReassessmentMoreSevere($reassessment);
        }

        $message = $reassessment->more_severe
            ? 'AI reassessment suggests a more severe level. A nurse or doctor must review and confirm it.'
            : 'New vitals and AI reassessment were saved for clinical review.';

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => $reassessment,
            ], 201);
        }

        return redirect()->route('emergency.show', $visit)->with('success', $message);
    }

    public function confirm(
        ConfirmTriageReassessmentRequest $request,
        ErVisit $visit,
        TriageReassessment $reassessment
    ): RedirectResponse|JsonResponse {
        abort_unless($reassessment->er_visit_id === $visit->id, 404);
        $data = $request->validated();

        $assessment = DB::transaction(function () use ($request, $visit, $reassessment, $data): TriageAssessment {
            $lockedVisit = ErVisit::query()->lockForUpdate()->findOrFail($visit->id);
            $this->ensureVisitActive($lockedVisit);

            $lockedReassessment = TriageReassessment::query()
                ->lockForUpdate()
                ->findOrFail($reassessment->id);
            abort_unless($lockedReassessment->er_visit_id === $lockedVisit->id, 404);
            abort_unless(
                $lockedReassessment->status === TriageReassessment::STATUS_PENDING,
                409,
                'This AI reassessment has already been confirmed.'
            );

            $assessment = TriageAssessment::query()->lockForUpdate()->findOrFail($lockedReassessment->triage_assessment_id);
            $currentFinalLevel = TriageDecisionRules::normalizeLevel(
                (string) ($assessment->final_level ?: $assessment->priority)
            );
            abort_unless(
                $currentFinalLevel === $lockedReassessment->current_final_level,
                409,
                'The current triage level changed after this reassessment. Record new vitals before confirming.'
            );

            $finalLevel = TriageDecisionRules::normalizeLevel($data['final_level']);
            $finalRank = $this->levelRank($finalLevel);
            $assessment->update([
                'priority' => 'Level '.$finalRank,
                'priority_score' => $finalRank,
                'triage_color' => $this->priorityColor($finalRank),
                'triaged_at' => now(),
                'gcs' => $lockedReassessment->vitals['gcs'] ?? $assessment->gcs,
            ]);

            $this->triage->recordDecision(
                $assessment->fresh(),
                [
                    'priority' => $lockedReassessment->ai_suggested_level,
                    'confidence' => $lockedReassessment->ai_confidence,
                    'reasons' => $lockedReassessment->ai_reasons ?? [],
                ],
                $finalLevel,
                $data['override_reason'] ?? null,
                $request->user()
            );

            $lockedReassessment->update([
                'status' => TriageReassessment::STATUS_CONFIRMED,
                'final_level' => $finalLevel,
                'override_reason' => $data['override_reason'] ?? null,
                'confirmed_by' => $request->user()->id,
                'confirmed_at' => now(),
            ]);

            $queue = $lockedVisit->queue()->first();
            if ($queue) {
                $queue->update(['priority' => 'Level '.$finalRank]);
            }

            return $assessment->fresh();
        });

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'AI reassessment reviewed and the triage decision was recorded.',
                'data' => $reassessment->fresh(),
                'triage_assessment' => $assessment,
            ]);
        }

        return redirect()->route('emergency.show', $visit)->with('success', 'AI reassessment reviewed and the triage decision was recorded.');
    }

    private function ensureVisitActive(ErVisit $visit): void
    {
        abort_unless(
            in_array($visit->status, [
                ErVisit::STATUS_ARRIVED,
                ErVisit::STATUS_TRIAGED,
                ErVisit::STATUS_IN_TREATMENT,
            ], true),
            409,
            'New vitals can only be recorded for an active ER visit.'
        );
    }

    private function normalizeVitals(array $vitals): array
    {
        $vitals['heart_rate'] = (int) $vitals['heart_rate'];
        $vitals['respiratory_rate'] = (int) $vitals['respiratory_rate'];
        $vitals['temperature'] = (float) $vitals['temperature'];
        $vitals['spo2'] = (float) $vitals['spo2'];
        if (isset($vitals['gcs'])) {
            $vitals['gcs'] = (int) $vitals['gcs'];
        }

        return $vitals;
    }

    private function levelRank(string $level): int
    {
        return match (TriageDecisionRules::normalizeLevel($level)) {
            'Emergency' => 1,
            'Urgent' => 2,
            'Prompt' => 3,
            'Non-Urgent' => 4,
            'Routine' => 5,
            default => 5,
        };
    }

    private function priorityColor(int $rank): string
    {
        return match ($rank) {
            1 => 'red',
            2 => 'yellow',
            3 => 'orange',
            default => 'green',
        };
    }
}
