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
        return $this->user()?->hasRole('nurse', 'doctor') ?? false;
    }

    public function rules(): array
    {
        return [
            'patient_id' => ['nullable', 'exists:patients,id'],
            'chief_complaint' => ['nullable', 'string', 'max:500'],
            'symptoms' => ['nullable'],
            'pain_score' => ['nullable', 'integer', 'min:0', 'max:10'],
            'gcs' => ['nullable', 'integer', 'between:3,15'],
            'priority' => ['nullable', 'in:Level 1,Level 2,Level 3,Level 4,Level 5,Emergency,Urgent,Prompt,Non-Urgent,Routine'],
            'priority_override' => ['nullable', 'in:Level 1,Level 2,Level 3,Level 4,Level 5,Emergency,Urgent,Prompt,Non-Urgent,Routine'],
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

    private function analyze(array $data): array
    {
        $symptoms = $data['symptoms'] ?? [];
        if (is_string($symptoms)) {
            $symptoms = preg_split('/[,;\n]/', $symptoms) ?: [];
        }

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
            'symptoms' => array_values(array_filter(array_map('trim', $symptoms))),
            'pain_score' => $data['pain_score'] ?? 0,
            'vitals' => $vitals,
        ]);
    }
}
