<?php

namespace App\Http\Controllers;

use App\Models\ChargeRate;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ChargeRateController extends Controller
{
    public function index()
    {
        abort_unless(auth()->user()->hasRole('super-admin'), 403);

        $rates = ChargeRate::query()->orderBy('category')->orderBy('charge_code')->paginate(25);

        return view('billing.rates', compact('rates'));
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->hasRole('super-admin'), 403);
        $data = $request->validate([
            'charge_code' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9:_-]+$/', 'unique:charge_rates,charge_code'],
            'category' => ['required', Rule::in([ChargeRate::CATEGORY_ENCOUNTER, ChargeRate::CATEGORY_PRESCRIPTION, ChargeRate::CATEGORY_BED_DAILY])],
            'description' => ['required', 'string', 'max:255'],
            'unit_amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999.99', 'decimal:0,2'],
            'currency' => ['required', 'in:PHP'],
            'active' => ['nullable', 'boolean'],
        ]);
        $data['active'] = $request->boolean('active');
        $data['updated_by'] = $request->user()->id;
        ChargeRate::create($data);

        return back()->with('success', 'Charge rate created.');
    }

    public function update(Request $request, ChargeRate $chargeRate)
    {
        abort_unless($request->user()->hasRole('super-admin'), 403);
        $data = $request->validate([
            'charge_code' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9:_-]+$/', Rule::unique('charge_rates', 'charge_code')->ignore($chargeRate->id)],
            'category' => ['required', Rule::in([ChargeRate::CATEGORY_ENCOUNTER, ChargeRate::CATEGORY_PRESCRIPTION, ChargeRate::CATEGORY_BED_DAILY])],
            'description' => ['required', 'string', 'max:255'],
            'unit_amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999.99', 'decimal:0,2'],
            'currency' => ['required', 'in:PHP'],
            'active' => ['nullable', 'boolean'],
        ]);
        $data['active'] = $request->boolean('active');
        $data['updated_by'] = $request->user()->id;
        $chargeRate->update($data);

        return back()->with('success', 'Charge rate updated.');
    }
}
