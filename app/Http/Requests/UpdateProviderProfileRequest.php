<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProviderProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('updateProfile', $this->route('provider')) ?? false;
    }

    public function rules(): array
    {
        return [
            'bio' => ['nullable', 'string', 'max:1000'],
            'years_of_practice' => ['nullable', 'integer', 'min:0', 'max:80'],
            'languages' => ['nullable', 'string', 'max:255'],
            'photo' => ['nullable', 'image', 'max:5120'],
            'accepts_virtual' => ['sometimes', 'boolean'],
        ];
    }
}
