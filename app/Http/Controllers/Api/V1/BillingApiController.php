<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Admission;
use App\Models\Encounter;
use App\Models\EncounterBill;
use App\Services\BillingAggregationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class BillingApiController extends Controller
{
    public function __construct(private readonly BillingAggregationService $billing)
    {
    }

    public function encounterSummary(Encounter $encounter): JsonResponse
    {
        Gate::authorize('view-billing');

        return response()->json(['success' => true, 'data' => $this->billing->previewForEncounter($encounter)]);
    }

    public function admissionSummary(Admission $admission): JsonResponse
    {
        Gate::authorize('view-billing');

        return response()->json(['success' => true, 'data' => $this->billing->previewForAdmission($admission)]);
    }

    public function admissionPaymentStatus(Admission $admission): JsonResponse
    {
        Gate::authorize('view-payment-status');
        $bill = $admission->bills()->latest('generated_at')->first(['status']);

        return response()->json([
            'success' => true,
            'data' => ['status' => $bill?->status],
        ]);
    }

    public function generateEncounter(Request $request, Encounter $encounter): JsonResponse
    {
        Gate::authorize('manage-billing');
        $bill = $this->billing->generateForEncounter($encounter, $request->user());

        return response()->json(['success' => true, 'data' => $bill->load('items')], 201);
    }

    public function generateAdmission(Request $request, Admission $admission): JsonResponse
    {
        Gate::authorize('manage-billing');
        $bill = $this->billing->generateForAdmission($admission, $request->user());

        return response()->json(['success' => true, 'data' => $bill->load('items')], 201);
    }

    public function markPaid(Request $request, EncounterBill $bill): JsonResponse
    {
        Gate::authorize('manage-billing');
        $data = $request->validate([
            'payment_reference' => ['required', 'string', 'max:120'],
            'amount_paid' => ['required', 'numeric', 'min:0.01', 'decimal:0,2'],
        ]);
        $bill = $this->billing->markPaid($bill, $data['payment_reference'], $data['amount_paid']);

        return response()->json(['success' => true, 'data' => $bill]);
    }

    public function dispense(Request $request, \App\Models\Prescription $prescription): JsonResponse
    {
        Gate::authorize('manage-billing');
        $prescription = $this->billing->dispense($prescription, $request->user());

        return response()->json(['success' => true, 'data' => $prescription]);
    }
}
