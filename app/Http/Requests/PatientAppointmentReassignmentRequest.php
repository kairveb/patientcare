<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PatientAppointmentReassignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('respond', $this->route('reassignment')) ?? false;
    }

    public function rules(): array
    {
        $choosing = $this->input('action') === 'pick';

        return [
            'action' => ['required', Rule::in(['accept', 'pick', 'decline', 'cancel'])],
            'provider_id' => [Rule::requiredIf($choosing), 'nullable', 'integer', 'exists:providers,id'],
            'appointment_slot_id' => [Rule::requiredIf($choosing), 'nullable', 'integer', 'exists:appointment_slots,id'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
