<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DecideAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('decide', $this->route('appointment')) ?? false;
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['confirm', 'reject'])],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}
