<?php

namespace App\Http\Requests;

use App\Models\AppointmentReassignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DecideAppointmentReassignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('decide', $this->route('reassignment')) ?? false;
    }

    public function rules(): array
    {
        $pickSlot = in_array($this->input('action'), ['pick', 'reschedule'], true);

        return [
            'action' => ['required', Rule::in(['approve', 'pick', 'reschedule', 'waitlist'])],
            'provider_id' => [Rule::requiredIf($pickSlot), 'nullable', 'integer', 'exists:providers,id'],
            'appointment_slot_id' => [Rule::requiredIf($pickSlot), 'nullable', 'integer', 'exists:appointment_slots,id'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
