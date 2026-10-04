<?php

namespace App\Http\Controllers;

use App\Models\Admission;
use App\Models\Appointment;
use App\Models\Bed;
use App\Models\Encounter;
use App\Models\ErVisit;
use App\Models\Patient;
use App\Models\TelehealthSession;
use App\Models\TriageAssessment;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ReportController extends Controller
{
    public function index()
    {
        return view('reports.index');
    }

    public function patients(Request $request)
    {
        $start = $request->get('start') ?: today()->subDays(30)->toDateString();
        $end = $request->get('end') ?: today()->toDateString();

        $data = Patient::whereBetween('created_at', [$start, $end])->get();

        $byDay = $data->groupBy(fn ($p) => $p->created_at->toDateString())->map->count();

        return view('reports.patients', compact('data', 'byDay', 'start', 'end'));
    }

    public function appointments(Request $request)
    {
        $start = $request->get('start') ?: today()->subDays(30)->toDateString();
        $end = $request->get('end') ?: today()->toDateString();

        $appointments = Appointment::whereBetween('starts_at', [$start, $end])->get();

        $total = $appointments->count();
        $cancelled = $appointments->where('status', Appointment::STATUS_CANCELLED)->count();
        $noShow = $appointments->where('status', Appointment::STATUS_NO_SHOW)->count();
        $byStatus = $appointments->groupBy('status')->map->count();

        return view('reports.appointments', compact('appointments', 'total', 'cancelled', 'noShow', 'byStatus', 'start', 'end'));
    }

    public function encounters(Request $request)
    {
        $start = $request->get('start') ?: today()->subDays(30)->toDateString();
        $end = $request->get('end') ?: today()->toDateString();

        $encounters = Encounter::whereBetween('started_at', [$start, $end])->get();
        $byType = $encounters->groupBy('type')->map->count();

        return view('reports.encounters', compact('encounters', 'byType', 'start', 'end'));
    }

    public function erVolume(Request $request)
    {
        $start = $request->get('start') ?: today()->subDays(30)->toDateString();
        $end = $request->get('end') ?: today()->toDateString();

        $visits = ErVisit::whereBetween('arrived_at', [$start, $end])->get();
        $triage = TriageAssessment::whereBetween('triaged_at', [$start, $end])->get();
        $byPriority = $triage->groupBy('priority')->map->count();

        return view('reports.er', compact('visits', 'byPriority', 'start', 'end'));
    }

    public function bedOccupancy()
    {
        $beds = Bed::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $admissions = Admission::with('patient')->where('status', Admission::STATUS_ADMITTED)->get();

        return view('reports.beds', ['beds' => $beds, 'admissions' => $admissions]);
    }

    public function telehealth()
    {
        $sessions = TelehealthSession::with('appointment.patient')->orderBy('start_time', 'desc')->limit(100)->get();
        $byStatus = $sessions->groupBy('status')->map->count();

        return view('reports.telehealth', compact('sessions', 'byStatus'));
    }

    public function aiTriageAccuracy(Request $request)
    {
        $report = $this->aiTriageAccuracyData($request);

        return view('reports.ai-triage-accuracy', $report);
    }

    public function exportAiTriageAccuracy(Request $request)
    {
        $report = $this->aiTriageAccuracyData($request);

        return response()->streamDownload(function () use ($report): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['AI Triage Accuracy Report']);
            fputcsv($output, ['Start date', $report['start']]);
            fputcsv($output, ['End date', $report['end']]);
            fputcsv($output, ['Total triage assessments', $report['total']]);
            fputcsv($output, ['Evaluated assessments', $report['evaluated']]);
            fputcsv($output, ['Excluded assessments', $report['excluded']]);
            fputcsv($output, ['Agreement count', $report['agreementCount']]);
            fputcsv($output, ['Agreement rate (%)', $report['agreementRate'] ?? 'N/A']);
            fputcsv($output, ['Override count', $report['overrideCount']]);
            fputcsv($output, ['Override rate (%)', $report['overrideRate'] ?? 'N/A']);
            fputcsv($output, []);
            fputcsv($output, ['Confusion matrix (rows: final level; columns: AI-suggested level)']);
            fputcsv($output, ['Final level / AI suggestion', ...$report['levels']]);

            foreach ($report['levels'] as $finalLevel) {
                fputcsv($output, [
                    $finalLevel,
                    ...array_map(fn (string $aiLevel): int => $report['matrix'][$finalLevel][$aiLevel], $report['levels']),
                ]);
            }

            fputcsv($output, []);
            fputcsv($output, ['Per-level metrics', 'AI predictions', 'Final cases', 'Correct', 'Precision (%)', 'Recall (%)']);
            foreach ($report['levelMetrics'] as $level => $metrics) {
                fputcsv($output, [
                    $level,
                    $metrics['aiPredictions'],
                    $metrics['finalCases'],
                    $metrics['correct'],
                    $metrics['precision'] ?? 'N/A',
                    $metrics['recall'] ?? 'N/A',
                ]);
            }

            fclose($output);
        }, 'ai-triage-accuracy-'.$report['start'].'-to-'.$report['end'].'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function aiTriageAccuracyData(Request $request): array
    {
        $dates = $request->validate([
            'start' => ['nullable', 'date_format:Y-m-d'],
            'end' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $end = CarbonImmutable::parse($dates['end'] ?? today()->toDateString())->endOfDay();
        $start = CarbonImmutable::parse($dates['start'] ?? $end->subDays(30)->toDateString())->startOfDay();
        if ($start->greaterThan($end)) {
            throw ValidationException::withMessages(['end' => 'The end date must be on or after the start date.']);
        }

        $levels = ['Emergency', 'Urgent', 'Prompt', 'Non-Urgent', 'Routine'];
        $assessments = TriageAssessment::query()
            ->whereBetween('triaged_at', [$start, $end])
            ->get(['ai_suggested_level', 'final_level', 'is_overridden']);
        $evaluated = $assessments->filter(fn (TriageAssessment $assessment): bool =>
            in_array($assessment->ai_suggested_level, $levels, true)
            && in_array($assessment->final_level, $levels, true)
        );

        $matrix = [];
        $aiCounts = array_fill_keys($levels, 0);
        $finalCounts = array_fill_keys($levels, 0);
        $correctCounts = array_fill_keys($levels, 0);
        foreach ($levels as $level) {
            $matrix[$level] = array_fill_keys($levels, 0);
        }

        $agreementCount = 0;
        $overrideCount = 0;
        foreach ($evaluated as $assessment) {
            $aiLevel = $assessment->ai_suggested_level;
            $finalLevel = $assessment->final_level;
            $matrix[$finalLevel][$aiLevel]++;
            $aiCounts[$aiLevel]++;
            $finalCounts[$finalLevel]++;
            if ($aiLevel === $finalLevel) {
                $agreementCount++;
                $correctCounts[$aiLevel]++;
            }
            if ($assessment->is_overridden) {
                $overrideCount++;
            }
        }

        $levelMetrics = [];
        foreach ($levels as $level) {
            $levelMetrics[$level] = [
                'aiPredictions' => $aiCounts[$level],
                'finalCases' => $finalCounts[$level],
                'correct' => $correctCounts[$level],
                'precision' => $aiCounts[$level] ? round($correctCounts[$level] / $aiCounts[$level] * 100, 2) : null,
                'recall' => $finalCounts[$level] ? round($correctCounts[$level] / $finalCounts[$level] * 100, 2) : null,
            ];
        }

        $evaluatedCount = $evaluated->count();

        return [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'levels' => $levels,
            'total' => $assessments->count(),
            'evaluated' => $evaluatedCount,
            'excluded' => $assessments->count() - $evaluatedCount,
            'agreementCount' => $agreementCount,
            'agreementRate' => $evaluatedCount ? round($agreementCount / $evaluatedCount * 100, 2) : null,
            'overrideCount' => $overrideCount,
            'overrideRate' => $evaluatedCount ? round($overrideCount / $evaluatedCount * 100, 2) : null,
            'matrix' => $matrix,
            'levelMetrics' => $levelMetrics,
        ];
    }
}
