<?php

namespace App\Http\Requests;

use App\Models\Ward;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminWardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin', 'super-admin') === true
            && $this->user()->hasPermission('manage-wards');
    }

    public function rules(): array
    {
        $ward = $this->route('ward');

        return [
            'code' => ['required', 'string', 'max:30', Rule::unique('wards', 'code')->ignore($ward instanceof Ward ? $ward->id : null)],
            'name' => ['required', 'string', 'max:255', Rule::unique('wards', 'name')->ignore($ward instanceof Ward ? $ward->id : null)],
            'type' => ['nullable', 'string', 'max:100'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
