<?php

namespace App\Services;

class AiTriageService
{
    public const LEVEL_EMERGENCY = 1;
    public const LEVEL_URGENT = 2;
    public const LEVEL_PROMPT = 3;
    public const LEVEL_NON_URGENT = 4;
    public const LEVEL_ROUTINE = 5;

    protected array $criticalKeywords = [
        'cardiac arrest',
        'respiratory arrest',
        'unresponsive',
        'difficulty breathing',
        'cyanosis',
        'severe bleeding',
        'severe trauma',
        'stroke',
        'loss of consciousness',
        'airway obstruction',
        'seizure',
    ];

    protected array $urgentKeywords = [
        'high fever',
        'fever',
        'abdominal pain',
        'vomiting',
        'dizziness',
        'syncope',
        'headache',
        'wheezing',
        'confusion',
        'weakness',
        'dehydration',
        'shortness of breath',
        'chest pain',
    ];

    protected array $routineKeywords = [
        'rash',
        'sore throat',
        'cold',
        'cough',
        'sprain',
        'minor wound',
        'follow-up',
        'medication refill',
        'fatigue',
    ];

    public function analyze(array $data): array
    {
        $complaint = strtolower(trim((string) ($data['chief_complaint'] ?? '')));
        $symptoms = array_map(fn ($value) => strtolower(trim((string) $value)), $data['symptoms'] ?? []);
        $vitals = $data['vitals'] ?? [];
        $painScore = (int) ($data['pain_score'] ?? 0);
        $ageGroup = $this->ageGroup($data['age'] ?? null);
        $vitalThresholds = $this->vitalThresholds($ageGroup);
        $preArrival = $data['pre_arrival_profile'] ?? [];
        $profileText = implode(' ', array_filter([
            $preArrival['visit_reason'] ?? '',
            $preArrival['medical_history'] ?? '',
            $preArrival['current_medications'] ?? '',
            $preArrival['allergies'] ?? '',
            $preArrival['initial_notes'] ?? '',
        ]));

        $combinedText = implode(' ', array_filter([$complaint, ...$symptoms, strtolower(trim((string) $profileText))]));

        $score = self::LEVEL_ROUTINE;
        $reasons = [];

        $profileSignals = $this->extractProfileSignals($preArrival);
        if (! empty($profileSignals)) {
            $reasons[] = 'Pre-arrival profile added elevated-risk context: ' . implode('; ', $profileSignals) . '.';
        }

        $lowGcs = isset($vitals['gcs']) && (int) $vitals['gcs'] <= 8;
        if ($lowGcs || $this->hasCriticalPattern($combinedText, $vitals, $vitalThresholds)) {
            $score = self::LEVEL_EMERGENCY;
            $reasons[] = $lowGcs
                ? 'Glasgow Coma Scale (GCS) of 8 or below is a critical red flag.'
                : 'Life-threatening symptoms or critical physiology were detected.';
        } elseif ($this->hasUrgentPattern($combinedText, $vitals, $painScore, $vitalThresholds) || ! empty($profileSignals)) {
            $score = self::LEVEL_URGENT;
            $reasons[] = 'Urgent symptoms or abnormal vital signs require rapid review.';
        } elseif ($this->hasPromptPattern($combinedText, $vitals, $painScore, $vitalThresholds)) {
            $score = self::LEVEL_PROMPT;
            $reasons[] = 'The patient is stable but requires timely clinical assessment.';
        } elseif ($this->hasNonUrgentPattern($combinedText, $vitals, $painScore)) {
            $score = self::LEVEL_NON_URGENT;
            $reasons[] = 'Symptoms are mild and stable, suitable for routine evaluation.';
        } else {
            $reasons[] = 'No acute red flags were detected during AI triage.';
        }

        if ($ageGroup !== null) {
            $reasons[] = ucfirst($ageGroup).' age-adjusted vital-sign thresholds are provisional and require clinical validation.';
        }

        $priorityMap = [
            1 => 'Emergency',
            2 => 'Urgent',
            3 => 'Prompt',
            4 => 'Non-Urgent',
            5 => 'Routine',
        ];

        $colorMap = [
            1 => 'red',
            2 => 'yellow',
            3 => 'orange',
            4 => 'green',
            5 => 'green',
        ];

        $priorityBandMap = [
            1 => 'Red',
            2 => 'Yellow',
            3 => 'Green',
            4 => 'Green',
            5 => 'Green',
        ];

        $severityScoreMap = [
            1 => 96,
            2 => 85,
            3 => 68,
            4 => 46,
            5 => 22,
        ];

        $recommendation = match ($score) {
            self::LEVEL_EMERGENCY => 'Emergency — immediate resuscitation/rapid physician assessment and possible escalation to emergency care.',
            self::LEVEL_URGENT => 'Urgent — prioritize this patient for immediate nursing review and doctor assessment.',
            self::LEVEL_PROMPT => 'Prompt — evaluate within the next treatment window; monitor closely.',
            self::LEVEL_NON_URGENT => 'Non-Urgent — provide standard clinical evaluation and follow-up as needed.',
            default => 'Routine — continue routine evaluation and observation.',
        };

        $confidence = $this->confidenceForLevel($score);

        return [
            'level' => $score,
            'score' => $score,
            'severity_score' => $severityScoreMap[$score] ?? 22,
            'priority' => $priorityMap[$score],
            'severity_category' => in_array($score, [self::LEVEL_EMERGENCY, self::LEVEL_URGENT], true)
                ? 'Severe (critical)'
                : 'Not Severe',
            'priority_band' => $priorityBandMap[$score] ?? 'Green',
            'color' => $colorMap[$score],
            'recommendation' => $recommendation,
            'reasons' => $reasons,
            'confidence' => $confidence,
        ];
    }

    protected function hasCriticalPattern(string $combinedText, array $vitals, array $thresholds): bool
    {
        if (isset($vitals['gcs']) && (int) $vitals['gcs'] <= 8) {
            return true;
        }

        if ($this->containsAny($combinedText, $this->criticalKeywords)) {
            return true;
        }

        if (($vitals['spo2'] ?? null) !== null && (float) $vitals['spo2'] < 90) {
            return true;
        }

        if (($vitals['blood_pressure'] ?? null) !== null && (float) $vitals['blood_pressure'] < 90) {
            return true;
        }

        if (($vitals['respiratory_rate'] ?? null) !== null && ((int) $vitals['respiratory_rate'] > $thresholds['critical_respiratory_rate_high'] || (int) $vitals['respiratory_rate'] < $thresholds['critical_respiratory_rate_low'])) {
            return true;
        }

        if (($vitals['heart_rate'] ?? null) !== null && ((int) $vitals['heart_rate'] > $thresholds['critical_heart_rate_high'] || (int) $vitals['heart_rate'] < $thresholds['critical_heart_rate_low'])) {
            return true;
        }

        return false;
    }

    protected function hasUrgentPattern(string $combinedText, array $vitals, int $painScore, array $thresholds): bool
    {
        if ($this->containsAny($combinedText, $this->urgentKeywords)) {
            return true;
        }

        if ($painScore >= 7 && ($this->containsAny($combinedText, ['chest pain', 'abdominal pain', 'headache', 'breathing', 'trauma']) || ($vitals['heart_rate'] ?? null) !== null)) {
            return true;
        }

        if (($vitals['temperature'] ?? null) !== null && (float) $vitals['temperature'] >= $thresholds['urgent_temperature']) {
            return true;
        }

        if (($vitals['spo2'] ?? null) !== null && (float) $vitals['spo2'] >= 90 && (float) $vitals['spo2'] < 94) {
            return true;
        }

        if (($vitals['respiratory_rate'] ?? null) !== null && (int) $vitals['respiratory_rate'] > $thresholds['urgent_respiratory_rate']) {
            return true;
        }

        if (($vitals['heart_rate'] ?? null) !== null
            && (($thresholds['urgent_heart_rate_high'] !== null && (int) $vitals['heart_rate'] > $thresholds['urgent_heart_rate_high'])
                || ($thresholds['urgent_heart_rate_low'] !== null && (int) $vitals['heart_rate'] < $thresholds['urgent_heart_rate_low']))) {
            return true;
        }

        return false;
    }

    protected function hasPromptPattern(string $combinedText, array $vitals, int $painScore, array $thresholds): bool
    {
        if ($this->containsAny($combinedText, ['fracture', 'wound', 'sprain', 'infection', 'cough', 'vomiting'])) {
            return true;
        }

        if ($painScore >= 4 && $painScore < 7) {
            return true;
        }

        if (($vitals['temperature'] ?? null) !== null
            && (float) $vitals['temperature'] >= $thresholds['prompt_temperature']
            && (float) $vitals['temperature'] < $thresholds['urgent_temperature']) {
            return true;
        }

        return false;
    }

    protected function hasNonUrgentPattern(string $combinedText, array $vitals, int $painScore): bool
    {
        if ($this->containsAny($combinedText, $this->routineKeywords)) {
            return true;
        }

        if ($painScore >= 0 && $painScore < 4) {
            return true;
        }

        if (($vitals['spo2'] ?? null) !== null && (float) $vitals['spo2'] >= 94 && (float) $vitals['spo2'] <= 100) {
            return true;
        }

        return false;
    }

    protected function extractProfileSignals(array $preArrival): array
    {
        $signals = [];
        $combinedText = strtolower(implode(' ', array_filter([
            $preArrival['medical_history'] ?? '',
            $preArrival['allergies'] ?? '',
            $preArrival['visit_reason'] ?? '',
            $preArrival['current_medications'] ?? '',
        ])));

        foreach ([
            'asthma' => 'Asthma history',
            'cardiac' => 'Cardiac history',
            'heart' => 'Cardiac history',
            'diabetes' => 'Diabetes history',
            'stroke' => 'Prior stroke history',
            'sepsis' => 'Sepsis history',
            'pregnancy' => 'Pregnancy risk',
            'anaphylaxis' => 'Severe allergy risk',
            'allergy' => 'Allergy history',
            'shortness of breath' => 'Shortness of breath noted in pre-arrival profile',
            'chest pain' => 'Chest pain noted in pre-arrival profile',
        ] as $keyword => $label) {
            if (str_contains($combinedText, $keyword)) {
                $signals[] = $label;
            }
        }

        return array_values(array_unique($signals));
    }

    protected function containsAny(string $text, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if ($keyword !== '' && str_contains($text, strtolower(trim($keyword)))) {
                return true;
            }
        }

        return false;
    }

    protected function confidenceForLevel(int $level): int
    {
        return match ($level) {
            self::LEVEL_EMERGENCY => 96,
            self::LEVEL_URGENT => 90,
            self::LEVEL_PROMPT => 82,
            self::LEVEL_NON_URGENT => 74,
            default => 68,
        };
    }

    protected function ageGroup(mixed $age): ?string
    {
        if (! is_numeric($age) || (float) $age < 0 || (float) $age > 120 || (float) (int) $age !== (float) $age) {
            return null;
        }

        $age = (int) $age;

        return $age < 18 ? 'pediatric' : ($age >= 65 ? 'geriatric' : null);
    }

    protected function vitalThresholds(?string $ageGroup): array
    {
        $thresholds = [
            'critical_heart_rate_high' => 130,
            'critical_heart_rate_low' => 40,
            'critical_respiratory_rate_high' => 30,
            'critical_respiratory_rate_low' => 8,
            'urgent_heart_rate_high' => null,
            'urgent_heart_rate_low' => null,
            'urgent_respiratory_rate' => 22,
            'urgent_temperature' => 38.5,
            'prompt_temperature' => 37.8,
        ];

        if ($ageGroup === 'pediatric') {
            return array_replace($thresholds, [
                'critical_heart_rate_high' => 160,
                'critical_heart_rate_low' => 50,
                'critical_respiratory_rate_high' => 40,
                'critical_respiratory_rate_low' => 10,
                'urgent_heart_rate_high' => 120,
                'urgent_heart_rate_low' => 60,
                'urgent_respiratory_rate' => 30,
                'urgent_temperature' => 38.0,
            ]);
        }

        if ($ageGroup === 'geriatric') {
            return array_replace($thresholds, [
                'critical_heart_rate_high' => 120,
                'critical_heart_rate_low' => 50,
                'critical_respiratory_rate_high' => 28,
                'critical_respiratory_rate_low' => 10,
                'urgent_heart_rate_high' => 100,
                'urgent_heart_rate_low' => 55,
                'urgent_respiratory_rate' => 20,
                'urgent_temperature' => 38.0,
                'prompt_temperature' => 37.5,
            ]);
        }

        return $thresholds;
    }
}
