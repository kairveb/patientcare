<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GenerateTelehealthSummaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('doctor') ?? false;
    }

    public function rules(): array
    {
        return [
            'doctor_notes' => ['required', 'string', 'min:3', 'max:5000'],
        ];
    }
}
