<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSymptomCheckRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('patient', 'registration', 'super-admin') ?? false;
    }

    public function rules(): array
    {
        return [
            'patient_id' => ['required', 'exists:patients,id'],
            'symptoms' => ['required', 'string', 'min:3', 'max:2000'],
            'age' => ['required', 'integer', 'min:0', 'max:120'],
            'vitals.gcs' => ['nullable', 'integer', 'between:3,15'],
            'vitals.spo2' => ['nullable', 'numeric', 'between:0,100'],
            'vitals.blood_pressure' => ['nullable', 'numeric', 'between:1,300'],
            'vitals.heart_rate' => ['nullable', 'integer', 'between:1,300'],
            'vitals.respiratory_rate' => ['nullable', 'integer', 'between:1,100'],
            'vitals.temperature' => ['nullable', 'numeric', 'between:25,45'],
        ];
    }
}
