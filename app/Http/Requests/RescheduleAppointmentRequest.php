<?php

namespace App\Http\Requests;

use App\Models\Appointment;
use Illuminate\Foundation\Http\FormRequest;

class RescheduleAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $appointment = $this->route('appointment');
        if (! $appointment instanceof Appointment) {
            $appointment = Appointment::query()->find($this->route('id'));
        }

        return $appointment instanceof Appointment
            && $this->user()?->can('reschedule', $appointment) === true;
    }

    public function rules(): array
    {
        return [
            'starts_at' => ['required', 'date'],
            'appointment_slot_id' => ['required', 'integer', 'exists:appointment_slots,id'],
            'duration' => ['nullable', 'integer', 'min:5', 'max:240'],
        ];
    }
}
