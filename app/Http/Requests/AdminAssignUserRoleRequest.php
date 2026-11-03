<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminAssignUserRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $target = $this->route('user');

        return $actor?->hasAnyRole(['admin', 'super-admin']) === true
            && $actor->hasPermission('manage-users')
            && ($actor->hasRole('super-admin') || ! $target?->hasRole('super-admin'))
            && ($this->input('role') !== 'super-admin' || $actor->hasRole('super-admin'));
    }

    public function rules(): array
    {
        $user = $this->route('user');
        $needsPatientRecord = $this->input('role') === 'patient'
            && $user instanceof User
            && ! $user->patient()->exists();
        $patientFieldRule = $needsPatientRecord ? 'required' : 'nullable';

        return [
            'role' => ['required', Rule::in(['super-admin', 'patient', 'nurse', 'doctor', 'registration', 'admin'])],
            'first_name' => [$patientFieldRule, 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => [$patientFieldRule, 'string', 'max:100'],
            'date_of_birth' => [$patientFieldRule, 'date', 'before_or_equal:today'],
            'sex' => [$patientFieldRule, 'in:Male,Female,Other'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->sometimes('role', ['prohibited'], fn () =>
            $this->input('role') === 'doctor'
                && $this->user()?->hasRole('admin')
                && ! $this->user()->hasPermission('manage-doctors')
        );
    }
}
