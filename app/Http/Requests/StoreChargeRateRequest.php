<?php

namespace App\Http\Requests;

use App\Models\ChargeRate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreChargeRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('super-admin') ?? false;
    }

    public function rules(): array
    {
        $chargeRate = $this->route('chargeRate');
        $uniqueCode = Rule::unique('charge_rates', 'charge_code');

        if ($chargeRate instanceof ChargeRate) {
            $uniqueCode->ignore($chargeRate->id);
        }

        return [
            'charge_code' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9:_-]+$/', $uniqueCode],
            'category' => ['required', Rule::in([
                ChargeRate::CATEGORY_ENCOUNTER,
                ChargeRate::CATEGORY_PRESCRIPTION,
                ChargeRate::CATEGORY_BED_DAILY,
            ])],
            'description' => ['required', 'string', 'max:255'],
            'unit_amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999.99', 'decimal:0,2'],
            'currency' => ['required', 'in:PHP'],
            'active' => ['nullable', 'boolean'],
        ];
    }
}
