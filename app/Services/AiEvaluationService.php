<?php

namespace App\Services;

use App\Models\SymptomCheck;
use App\Models\Provider;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class AiEvaluationService
{
    public function query(array $filters = [], ?User $user = null): Builder
    {
        $query = SymptomCheck::query()->with('patient:id,sex');
        $query->whereBetween('created_at', [
            CarbonImmutable::parse($filters['start'] ?? today()->subDays(30)->toDateString())->startOfDay(),
            CarbonImmutable::parse($filters['end'] ?? today()->toDateString())->endOfDay(),
        ]);

        if (filled($filters['department_id'] ?? null)) {
            $departmentName = $filters['department_name']
                ?? \App\Models\Department::query()->whereKey((int) $filters['department_id'])->value('name');
            $query->where('recommended_department', $departmentName ?? '');
        }

        if (filled($filters['doctor_id'] ?? null)) {
            $reviewerId = Provider::query()->whereKey((int) $filters['doctor_id'])->value('user_id');
            $query->where('clinician_reviewed_by', $reviewerId ?? 0);
        }

        if ($user?->hasRole('doctor')) {
            $query->where('clinician_reviewed_by', $user->id);
        }

        return $query;
    }

    public function metrics(array $filters = [], ?User $user = null): array
    {
        $checks = $this->query($filters, $user)->get();
        $reviewed = $checks->whereNotNull('clinician_agreement');
        $triageCount = $checks->count();
        $reviewCount = $reviewed->count();
        $agreementCount = $reviewed->where('clinician_agreement', 'agree')->count();
        $providerCounts = $checks->groupBy(fn (SymptomCheck $check): string =>
            $check->ai_provider === 'rules' ? 'rules' : 'ai'
        )->map->count();

        $urgencies = ['Emergency', 'Urgent', 'Routine', 'Self-Care'];
        $urgencyCounts = array_fill_keys($urgencies, 0);
        foreach ($checks as $check) {
            if (isset($urgencyCounts[$check->urgency_level])) {
                $urgencyCounts[$check->urgency_level]++;
            }
        }

        $weekly = $checks->groupBy(fn (SymptomCheck $check): string =>
            $check->created_at->format('o-\WW')
        )->map->count()->sortKeys();

        return [
            'triages' => $triageCount,
            'rules_count' => (int) $providerCounts->get('rules', 0),
            'ai_count' => (int) $providerCounts->get('ai', 0),
            'rules_share' => $triageCount ? round($providerCounts->get('rules', 0) / $triageCount * 100, 1) : null,
            'ai_share' => $triageCount ? round($providerCounts->get('ai', 0) / $triageCount * 100, 1) : null,
            'override_count' => $checks->where('rule_overrode_ai', true)->count(),
            'reviewed_count' => $reviewCount,
            'agreement_count' => $agreementCount,
            'agreement_rate' => $reviewCount ? round($agreementCount / $reviewCount * 100, 1) : null,
            'under_triage_count' => $reviewed->where('clinician_agreement', 'too_low')->count(),
            'over_triage_count' => $reviewed->where('clinician_agreement', 'too_high')->count(),
            'urgency_counts' => $urgencyCounts,
            'weekly' => $weekly,
            'checks' => $checks,
        ];
    }

    public function dataset(array $filters = [], ?User $user = null): Collection
    {
        return $this->query($filters, $user)
            ->whereNotNull('clinician_agreement')
            ->get()
            ->map(fn (SymptomCheck $check): array => [
                'urgency' => $check->clinician_final_urgency,
                'specialty' => $check->recommended_department,
                'age range' => $this->ageRange((int) $check->age),
                'sex' => $check->patient?->sex,
                'clinician agreement' => $check->clinician_agreement,
                'rule override flag' => $check->rule_overrode_ai ? 'true' : 'false',
                'week' => $check->created_at->format('o-\WW'),
            ]);
    }

    private function ageRange(int $age): string
    {
        $from = intdiv(max(0, $age), 10) * 10;

        return $from.'-'.($from + 9);
    }
}
