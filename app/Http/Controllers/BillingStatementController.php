<?php

namespace App\Http\Controllers;

use App\Models\Admission;
use App\Models\Encounter;
use App\Services\BillingAggregationService;
use Illuminate\Http\Request;

class BillingStatementController extends Controller
{
    public function generateEncounter(Request $request, Encounter $encounter, BillingAggregationService $billing)
    {
        abort_unless($request->user()->can('manage-billing'), 403);
        $billing->generateForEncounter($encounter, $request->user());

        return back()->with('success', 'Billing statement generated. Cashier payment synchronization is still required.');
    }

    public function generateAdmission(Request $request, Admission $admission, BillingAggregationService $billing)
    {
        abort_unless($request->user()->can('manage-billing'), 403);
        $billing->generateForAdmission($admission, $request->user());

        return back()->with('success', 'Billing statement generated. Cashier payment synchronization is still required.');
    }

    public function dispense(Request $request, \App\Models\Prescription $prescription, BillingAggregationService $billing)
    {
        abort_unless($request->user()->can('manage-billing'), 403);
        $billing->dispense($prescription, $request->user());

        return back()->with('success', 'Prescription marked as dispensed.');
    }
}
