<?php

namespace App\Http\Requests;

use App\Models\CareRequest;
use Illuminate\Foundation\Http\FormRequest;

class StoreCareRequestResultRequest extends FormRequest
{
    public function authorize(): bool
    {
        $careRequest = $this->route('careRequest');

        return $careRequest instanceof CareRequest
            && ($this->user()?->can('attachResult', $careRequest) ?? false);
    }

    public function rules(): array
    {
        return [
            'result_text' => ['required', 'string', 'min:1', 'max:20000'],
            'result_file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ];
    }
}
