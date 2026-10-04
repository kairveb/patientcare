<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTelehealthReferralRequest;
use App\Models\TelehealthSession;
use App\Services\TelehealthReferralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class TelehealthReferralController extends Controller
{
    public function __construct(protected TelehealthReferralService $referrals) {}

    public function store(
        StoreTelehealthReferralRequest $request,
        TelehealthSession $session
    ): RedirectResponse|JsonResponse {
        $referral = $this->referrals->create(
            $session,
            $request->user(),
            $request->validated('reason')
        );

        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Face-to-face OPD referral request created.',
                'data' => $referral,
            ], 201);
        }

        return redirect()->route('telehealth.show', $session)
            ->with('success', 'Face-to-face OPD referral request created.');
    }
}
