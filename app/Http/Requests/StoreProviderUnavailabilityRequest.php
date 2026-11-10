<?php

namespace App\Http\Requests;

use App\Models\ProviderUnavailability;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProviderUnavailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', [ProviderUnavailability::class, (int) $this->input('provider_id')]) ?? false;
    }

    public function rules(): array
    {
        return [
            'provider_id' => ['required', 'integer', Rule::exists('providers', 'id')->where('active', true)],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'reason' => ['required', Rule::in([
                ProviderUnavailability::REASON_EMERGENCY,
                ProviderUnavailability::REASON_SICK_LEAVE,
                ProviderUnavailability::REASON_OTHER,
            ])],
            'details' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
