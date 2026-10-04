<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminCreateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('super-admin') === true
            && $this->user()->hasPermission('manage-users');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => [...User::PASSWORD_RULE, 'confirmed'],
            'role' => ['required', Rule::in(['super-admin', 'patient', 'nurse', 'doctor', 'registration'])],
            'first_name' => ['required_if:role,patient', 'nullable', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required_if:role,patient', 'nullable', 'string', 'max:100'],
            'date_of_birth' => ['required_if:role,patient', 'nullable', 'date', 'before_or_equal:today'],
            'sex' => ['required_if:role,patient', 'nullable', 'in:Male,Female,Other'],
        ];
    }
}
