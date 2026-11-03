<?php

namespace App\Http\Requests;

use App\Models\DataSubjectRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewDataSubjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('review', $this->route('dataSubjectRequest')) ?? false;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([
                DataSubjectRequest::STATUS_IN_REVIEW,
                DataSubjectRequest::STATUS_COMPLETED,
                DataSubjectRequest::STATUS_REJECTED,
            ])],
            'response_note' => ['required', 'string', 'min:5', 'max:3000'],
        ];
    }
}
