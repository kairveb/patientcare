<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateAdmissionFromErVisitRequest extends FormRequest
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
            'attending_provider_id' => ['required', 'integer', 'exists:providers,id'],
            'reason' => ['nullable', 'string', 'max:500'],
            'guarantor_name' => ['nullable', 'string', 'max:150'],
            'guarantor_relationship' => ['nullable', 'string', 'max:100'],
            'guarantor_contact' => ['nullable', 'string', 'max:30'],
            'loa_reference' => ['nullable', 'string', 'max:255'],
            'coverage_code' => ['nullable', 'required_with:loa_requested_amount', 'string', 'max:80'],
            'loa_requested_amount' => ['nullable', 'required_with:coverage_code', 'numeric', 'min:0.01', 'decimal:0,2'],
        ];
    }
}
