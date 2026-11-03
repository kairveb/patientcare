<?php

namespace App\Http\Requests;

use App\Models\CareRequest;
use Illuminate\Foundation\Http\FormRequest;

class DecideCareRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $careRequest = $this->route('careRequest');

        return $careRequest instanceof CareRequest
            && ($this->user()?->can('decide', $careRequest) ?? false);
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', 'in:APPROVED,REJECTED'],
            'remarks' => ['required_if:decision,REJECTED', 'nullable', 'string', 'max:2000'],
        ];
    }
}
