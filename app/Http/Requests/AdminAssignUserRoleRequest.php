<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminAssignUserRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('super-admin') === true
            && $this->user()->hasPermission('manage-users');
    }

    public function rules(): array
    {
        $user = $this->route('user');
        $needsPatientRecord = $this->input('role') === 'patient'
            && $user instanceof User
            && ! $user->patient()->exists();
        $patientFieldRule = $needsPatientRecord ? 'required' : 'nullable';

        return [
            'role' => ['required', Rule::in(['super-admin', 'patient', 'nurse', 'doctor', 'registration'])],
            'first_name' => [$patientFieldRule, 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => [$patientFieldRule, 'string', 'max:100'],
            'date_of_birth' => [$patientFieldRule, 'date', 'before_or_equal:today'],
            'sex' => [$patientFieldRule, 'in:Male,Female,Other'],
        ];
    }
}
