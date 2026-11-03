<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RequestFollowUpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('encounter')) ?? false;
    }

    public function rules(): array
    {
        return [
            'follow_up_date' => ['required', 'date', 'after_or_equal:today'],
            'recommendation' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
