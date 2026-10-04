<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RequestBillingClearanceFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && $user->hasRole('registration', 'nurse')
            && $user->hasPermission('view-admissions');
    }

    public function rules(): array
    {
        return [];
    }
}
