<?php

namespace App\Http\Requests;

use App\Models\CareRequest;
use Illuminate\Foundation\Http\FormRequest;

class CancelCareRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $careRequest = $this->route('careRequest');

        return $careRequest instanceof CareRequest
            && ($this->user()?->can('cancel', $careRequest) ?? false);
    }

    public function rules(): array
    {
        return [
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
