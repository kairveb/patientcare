<?php

namespace App\Http\Requests;

use App\Models\SymptomCheck;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewSymptomCheck extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $symptomCheck = $this->route('symptomCheck');

        if (! $user || ! $symptomCheck instanceof SymptomCheck
            || ! $user->hasAnyRole(['doctor', 'nurse', 'registration', 'admin', 'super-admin'])) {
            return false;
        }

        if (! $user->hasRole('doctor')) {
            return true;
        }

        $providerId = $user->provider?->id;

        return $providerId !== null
            && $symptomCheck->patient?->encounters()
                ->where('provider_id', $providerId)
                ->exists();
    }

    public function rules(): array
    {
        return [
            'clinician_final_urgency' => ['required', Rule::in(['Emergency', 'Urgent', 'Routine', 'Self-Care'])],
            'clinician_agreement' => ['required', Rule::in(['agree', 'too_low', 'too_high'])],
        ];
    }
}
