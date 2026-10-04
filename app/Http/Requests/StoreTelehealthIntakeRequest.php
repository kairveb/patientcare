<?php

namespace App\Http\Requests;

use App\Models\TelehealthSession;
use Illuminate\Foundation\Http\FormRequest;

class StoreTelehealthIntakeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $session = $this->route('session');
        $user = $this->user();

        return $user !== null
            && $user->hasRole('patient')
            && $user->hasPermission('portal-dashboard')
            && $session instanceof TelehealthSession
            && $user->patient?->id === $session->appointment?->patient_id;
    }

    public function rules(): array
    {
        return [
            'symptoms' => ['required', 'string', 'min:3', 'max:2000'],
            'duration' => ['required', 'string', 'max:100'],
            'severity' => ['required', 'integer', 'between:1,10'],
            'allergies' => ['nullable', 'string', 'max:2000'],
            'current_medications' => ['nullable', 'string', 'max:2000'],
            'vitals' => ['nullable', 'array'],
            'vitals.gcs' => ['nullable', 'integer', 'between:3,15'],
            'vitals.spo2' => ['nullable', 'numeric', 'between:0,100'],
            'vitals.blood_pressure' => ['nullable', 'numeric', 'between:1,300'],
            'vitals.heart_rate' => ['nullable', 'integer', 'between:1,300'],
            'vitals.respiratory_rate' => ['nullable', 'integer', 'between:1,100'],
            'vitals.temperature' => ['nullable', 'numeric', 'between:25,45'],
        ];
    }
}
