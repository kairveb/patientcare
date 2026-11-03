<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AdminSetUserStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $target = $this->route('user');

        return $actor?->hasAnyRole(['admin', 'super-admin']) === true
            && $actor->hasPermission('manage-users')
            && ($actor->hasRole('super-admin') || ! $target?->hasRole('super-admin'));
    }

    public function rules(): array
    {
        return [
            'is_active' => ['required', 'boolean'],
        ];
    }
}
