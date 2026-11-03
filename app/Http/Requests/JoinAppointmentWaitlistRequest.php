<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class JoinAppointmentWaitlistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('patient') ?? false;
    }

    public function rules(): array
    {
        return [
            'provider_id' => ['required', 'integer', 'exists:providers,id'],
            'appointment_type_id' => ['required', 'integer', 'exists:appointment_types,id'],
            'preferred_date' => ['nullable', 'date', 'after_or_equal:today'],
        ];
    }
}
