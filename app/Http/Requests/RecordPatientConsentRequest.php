<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordPatientConsentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $patient = $this->route('patient');

        if ($patient !== null) {
            return $user?->hasRole('registration')
                && $user->hasPermission('create-patients');
        }

        return $user?->hasRole('patient')
            && $user->hasPermission('portal-dashboard')
            && $user->patient()->exists();
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(array_keys(config('consents.types', [])))],
            'granted' => ['required', 'boolean'],
        ];
    }
}
