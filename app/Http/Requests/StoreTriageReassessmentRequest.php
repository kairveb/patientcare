<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTriageReassessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('nurse') ?? false;
    }

    public function rules(): array
    {
        return [
            'vitals' => ['required', 'array'],
            'vitals.blood_pressure' => ['required', 'string', 'max:20'],
            'vitals.heart_rate' => ['required', 'integer', 'between:1,300'],
            'vitals.respiratory_rate' => ['required', 'integer', 'between:1,100'],
            'vitals.temperature' => ['required', 'numeric', 'between:25,45'],
            'vitals.spo2' => ['required', 'numeric', 'between:0,100'],
            'vitals.gcs' => ['nullable', 'integer', 'between:3,15'],
        ];
    }
}
