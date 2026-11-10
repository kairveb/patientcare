<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreChargeRateRequest;
use App\Models\ChargeRate;

class ChargeRateController extends Controller
{
    public function index()
    {
        abort_unless(auth()->user()->hasRole('super-admin'), 403);

        $rates = ChargeRate::query()->orderBy('category')->orderBy('charge_code')->paginate(25);

        return view('billing.rates', compact('rates'));
    }

    public function store(StoreChargeRateRequest $request)
    {
        $data = $request->validated();
        $data['active'] = $request->boolean('active');
        $data['updated_by'] = $request->user()->id;
        ChargeRate::create($data);

        return back()->with('success', 'Charge rate created.');
    }

    public function update(StoreChargeRateRequest $request, ChargeRate $chargeRate)
    {
        $data = $request->validated();
        $data['active'] = $request->boolean('active');
        $data['updated_by'] = $request->user()->id;
        $chargeRate->update($data);

        return back()->with('success', 'Charge rate updated.');
    }
}
