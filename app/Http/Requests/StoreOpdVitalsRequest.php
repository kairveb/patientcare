<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOpdVitalsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('nurse') ?? false;
    }

    public function rules(): array
    {
        return self::sharedRules();
    }

    public static function sharedRules(): array
    {
        return [
            'blood_pressure' => ['nullable', 'string', 'max:20', 'regex:/^\d{2,3}\/\d{2,3}$/'],
            'heart_rate' => ['nullable', 'integer', 'between:1,250'],
            'respiratory_rate' => ['nullable', 'integer', 'between:1,80'],
            'temperature' => ['nullable', 'numeric', 'between:25,45'],
            'spo2' => ['nullable', 'numeric', 'between:0,100'],
            'height_cm' => ['required', 'numeric', 'between:55,250'],
            'weight' => ['required', 'numeric', 'between:1,300'],
            'pain_score' => ['nullable', 'integer', 'between:0,10'],
        ];
    }
}
