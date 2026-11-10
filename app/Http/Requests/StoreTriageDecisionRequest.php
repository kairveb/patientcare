<?php

namespace App\Http\Requests;

use App\Models\ErVisit;
use App\Services\TriageService;
use App\Support\TriageDecisionRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreTriageDecisionRequest extends FormRequest
{
    private ?array $resolvedAiAnalysis = null;

    public function authorize(): bool
    {
        return $this->user()?->hasRole('nurse', 'doctor', 'super-admin') ?? false;
    }

    public function rules(): array
    {
        return [
            'patient_id' => ['nullable', 'exists:patients,id'],
            'chief_complaint' => ['nullable', 'string', 'max:500'],
            'symptoms' => [
                'nullable',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) && ! is_array($value)) {
                        $fail('Symptoms must be text or a list of symptom names.');
                    }
                },
            ],
            'symptoms.*' => ['string', 'max:255'],
            'pain_score' => ['nullable', 'integer', 'min:0', 'max:10'],
            'gcs' => ['nullable', 'integer', 'between:3,15'],
            'priority' => ['nullable', 'in:Level 1,Level 2,Level 3,Level 4,Level 5,Emergency,Urgent,Prompt,Non-Urgent,Routine,Red,Yellow,Orange,Green'],
            'priority_override' => ['nullable', 'in:Level 1,Level 2,Level 3,Level 4,Level 5,Emergency,Urgent,Prompt,Non-Urgent,Routine,Red,Yellow,Orange,Green'],
            'override_reason' => ['nullable', 'string'],
            'ai_confirmed' => ['accepted'],
            'notes' => ['nullable', 'string'],
            'treatment_area' => ['nullable', 'string'],
            'provider_id' => ['nullable', 'exists:providers,id'],
            'blood_pressure' => ['nullable', 'string', 'max:20'],
            'heart_rate' => ['nullable', 'integer', 'min:0', 'max:220'],
            'respiratory_rate' => ['nullable', 'integer', 'min:0', 'max:80'],
            'temperature' => ['nullable', 'numeric', 'min:30', 'max:45'],
            'spo2' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'vitals' => ['nullable', 'array'],
            'vitals.blood_pressure' => ['nullable', 'string', 'max:20'],
            'vitals.heart_rate' => ['nullable', 'integer', 'min:0', 'max:220'],
            'vitals.respiratory_rate' => ['nullable', 'integer', 'min:0', 'max:80'],
            'vitals.temperature' => ['nullable', 'numeric', 'min:30', 'max:45'],
            'vitals.spo2' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'vitals.gcs' => ['nullable', 'integer', 'between:3,15'],
            'vitals.weight' => ['nullable', 'numeric'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $data = $validator->validated();
            $analysis = $this->analyze($data);
            $finalLevel = TriageDecisionRules::normalizeLevel($data['priority_override'] ?? $data['priority'] ?? $analysis['priority']);
            TriageDecisionRules::validateOverride(
                $validator,
                $analysis['priority'],
                $finalLevel,
                $data['override_reason'] ?? null
            );

            $this->resolvedAiAnalysis = $analysis;
        });
    }

    public function aiAnalysis(): array
    {
        if ($this->resolvedAiAnalysis !== null) {
            return $this->resolvedAiAnalysis;
        }

        return $this->analyze($this->validated());
    }

    public function finalLevel(): string
    {
        $data = $this->validated();

        return TriageDecisionRules::normalizeLevel($data['priority_override'] ?? $data['priority'] ?? $this->aiAnalysis()['priority']);
    }

    public function overrideReason(): ?string
    {
        $reason = trim((string) ($this->validated()['override_reason'] ?? ''));

        return $reason === '' ? null : $reason;
    }

    public function normalizedSymptoms(): array
    {
        return $this->symptomList($this->validated()['symptoms'] ?? null);
    }

    private function analyze(array $data): array
    {
        $symptoms = $this->symptomList($data['symptoms'] ?? null);

        $visit = $this->route('visit');
        if (! $visit instanceof ErVisit && $this->route('id')) {
            $visit = ErVisit::find($this->route('id'));
        }

        $existingAssessment = $visit?->triageAssessments()
            ->with('triageVital')
            ->latest('triaged_at')
            ->first();
        if ($existingAssessment) {
            $data['patient_id'] ??= $existingAssessment->patient_id;
            $data['chief_complaint'] ??= $existingAssessment->chief_complaint;
            $data['pain_score'] ??= $existingAssessment->pain_score;
            $data['gcs'] ??= $existingAssessment->gcs;
            if (empty($symptoms) && is_array($existingAssessment->symptoms)) {
                $symptoms = $existingAssessment->symptoms;
            }
        }

        $vitals = $data['vitals'] ?? [];
        foreach (['blood_pressure', 'heart_rate', 'respiratory_rate', 'temperature', 'spo2'] as $field) {
            if (! array_key_exists($field, $vitals)) {
                $vitals[$field] = $data['vitals_'.$field] ?? $data[$field] ?? null;
            }
            if ($vitals[$field] === null && $existingAssessment?->triageVital) {
                $vitals[$field] = $existingAssessment->triageVital->{$field};
            }
        }
        $vitals['gcs'] ??= $data['gcs'] ?? null;

        return app(TriageService::class)->score([
            'patient_id' => $data['patient_id'] ?? $visit?->patient_id,
            'chief_complaint' => $data['chief_complaint'] ?? $visit?->chief_complaint,
            'symptoms' => $symptoms,
            'pain_score' => $data['pain_score'] ?? 0,
            'vitals' => $vitals,
        ]);
    }

    private function symptomList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[,;\n]/', $value) ?: [];
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn ($symptom): string => trim((string) $symptom), $value),
            static fn (string $symptom): bool => $symptom !== ''
        ));
    }
}
