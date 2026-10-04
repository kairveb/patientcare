<?php

namespace App\Http\Requests;

use App\Models\Bed;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class TransferBedRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && ($user->hasRole('nurse') || $user->isSuperAdmin())
            && $user->can('transfer-patients');
    }

    protected function prepareForValidation(): void
    {
        $values = [];

        if (!$this->has('admission_id') && $this->route('id') !== null) {
            $values['admission_id'] = $this->route('id');
        }

        if (!$this->has('destination_bed_id') && $this->has('to_bed_id')) {
            $values['destination_bed_id'] = $this->input('to_bed_id');
        }

        if ($values !== []) {
            $this->merge($values);
        }
    }

    public function rules(): array
    {
        return [
            'admission_id' => ['required', 'integer', 'exists:admissions,id'],
            'destination_bed_id' => [
                'required',
                'integer',
                Rule::exists('beds', 'id')->where('status', Bed::STATUS_AVAILABLE),
            ],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'destination_bed_id.exists' => 'The destination bed does not exist or is not available.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $routeAdmissionId = $this->route('id');
            if ($routeAdmissionId !== null
                && (string) $this->input('admission_id') !== (string) $routeAdmissionId) {
                $validator->errors()->add('admission_id', 'The admission ID must match the requested admission.');
            }
        });
    }
}
