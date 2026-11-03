<?php

namespace App\Http\Requests;

use App\Models\DataSubjectRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDataSubjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', DataSubjectRequest::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in([
                DataSubjectRequest::TYPE_ACCESS_COPY,
                DataSubjectRequest::TYPE_CORRECTION,
                DataSubjectRequest::TYPE_RESTRICTION_OBJECTION,
                DataSubjectRequest::TYPE_ERASURE,
            ])],
            'note' => ['required', 'string', 'min:5', 'max:3000'],
        ];
    }
}
