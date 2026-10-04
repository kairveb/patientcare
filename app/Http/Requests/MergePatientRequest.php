<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class MergePatientRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && $user->hasRole('registration', 'super-admin')
            && $user->hasPermission('update-patients');
    }

    public function rules(): array
    {
        return [
            'target_patient_id' => ['nullable', 'integer', 'exists:patients,id'],
            'target_mrn' => ['nullable', 'string', 'max:50', 'exists:patients,mrn'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->filled('target_patient_id') === $this->filled('target_mrn')) {
                $validator->errors()->add('target_patient_id', 'Provide exactly one target patient ID or MRN.');
            }
        });
    }
}
