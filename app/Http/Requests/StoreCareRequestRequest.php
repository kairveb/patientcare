<?php

namespace App\Http\Requests;

use App\Models\CareRequest;
use App\Models\Encounter;
use Illuminate\Foundation\Http\FormRequest;

class StoreCareRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $encounter = $this->route('encounter');

        return $encounter instanceof Encounter
            && ($this->user()?->can('create', [CareRequest::class, $encounter]) ?? false);
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'in:lab,imaging,procedure'],
            'description' => ['required', 'string', 'min:3', 'max:5000'],
            'urgency' => ['required', 'in:routine,urgent'],
            'coverage_code' => ['nullable', 'required_with:loa_requested_amount', 'string', 'max:80'],
            'loa_requested_amount' => ['nullable', 'required_with:coverage_code', 'numeric', 'min:0.01', 'decimal:0,2'],
        ];
    }
}
