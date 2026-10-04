<?php

namespace App\Http\Requests;

use App\Services\PatientIdentifierService;
use Illuminate\Foundation\Http\FormRequest;

class VerifyPatientIdentifierRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && $user->hasRole('registration')
            && $user->hasPermission('update-patients')
            && array_key_exists(
                (string) $this->route('identifier'),
                PatientIdentifierService::verificationColumns()
            );
    }

    public function rules(): array
    {
        return [];
    }
}
