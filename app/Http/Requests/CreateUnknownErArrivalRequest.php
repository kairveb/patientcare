<?php

namespace App\Http\Requests;

use App\Rules\PhilippineMobilePhone;
use Illuminate\Foundation\Http\FormRequest;

class CreateUnknownErArrivalRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && $user->hasRole('registration', 'nurse', 'super-admin')
            && $user->hasPermission('create-er-visits');
    }

    public function rules(): array
    {
        return [
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'date_of_birth' => ['nullable', 'date', 'before_or_equal:today'],
            'sex' => ['nullable', 'in:Male,Female,Other'],
            'phone' => ['nullable', 'string', 'max:30', new PhilippineMobilePhone],
            'email' => ['nullable', 'email', 'max:255'],
            'arrived_at' => ['nullable', 'date'],
            'arrival_method' => ['nullable', 'string', 'max:50'],
            'chief_complaint' => ['required', 'string', 'max:2000'],
            'referral_details' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
