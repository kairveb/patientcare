<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateAdmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && $user->hasRole('doctor', 'super-admin')
            && $user->hasPermission('create-admissions');
    }

    public function rules(): array
    {
        return [
            'patient_id' => ['required', 'integer', 'exists:patients,id'],
            'er_visit_id' => ['nullable', 'integer', 'exists:er_visits,id'],
            'attending_provider_id' => ['required', 'integer', 'exists:providers,id'],
            'reason' => ['nullable', 'string', 'max:500'],
            'guarantor_name' => ['nullable', 'string', 'max:150'],
            'guarantor_relationship' => ['nullable', 'string', 'max:100'],
            'guarantor_contact' => ['nullable', 'string', 'max:30'],
            'loa_reference' => ['nullable', 'string', 'max:255'],
        ];
    }
}
