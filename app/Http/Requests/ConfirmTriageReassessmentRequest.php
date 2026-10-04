<?php

namespace App\Http\Requests;

use App\Models\TriageReassessment;
use App\Support\TriageDecisionRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ConfirmTriageReassessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('nurse', 'doctor') ?? false;
    }

    public function rules(): array
    {
        return [
            'final_level' => ['required', 'in:Emergency,Urgent,Prompt,Non-Urgent,Routine'],
            'override_reason' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $reassessment = $this->route('reassessment');
            if (! $reassessment instanceof TriageReassessment) {
                return;
            }

            TriageDecisionRules::validateOverride(
                $validator,
                $reassessment->ai_suggested_level,
                (string) $this->input('final_level'),
                $this->input('override_reason')
            );
        });
    }
}
