<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminCreateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAnyRole(['admin', 'super-admin']) === true
            && $this->user()->hasPermission('manage-users')
            && ($this->input('role') !== 'super-admin' || $this->user()->hasRole('super-admin'));
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'username' => ['nullable', 'string', 'min:3', 'max:190', 'regex:/^[A-Za-z0-9._-]+$/', 'unique:users,username'],
            'password' => [...User::PASSWORD_RULE, 'confirmed'],
            'role' => ['required', Rule::in(['super-admin', 'patient', 'nurse', 'doctor', 'registration', 'admin'])],
            'first_name' => ['required_if:role,patient', 'nullable', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required_if:role,patient', 'nullable', 'string', 'max:100'],
            'date_of_birth' => ['required_if:role,patient', 'nullable', 'date', 'before_or_equal:today'],
            'sex' => ['required_if:role,patient', 'nullable', 'in:Male,Female,Other'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->sometimes('role', ['prohibited'], fn () =>
            $this->user()?->hasRole('admin') && $this->input('role') === 'super-admin'
        );
        $validator->sometimes('role', ['prohibited'], fn () =>
            $this->input('role') === 'doctor'
                && $this->user()?->hasRole('admin')
                && ! $this->user()->hasPermission('manage-doctors')
        );
    }
}
