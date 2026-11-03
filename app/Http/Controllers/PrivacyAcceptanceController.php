<?php

namespace App\Http\Controllers;

use App\Services\PatientConsentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PrivacyAcceptanceController extends Controller
{
    public function show(Request $request, PatientConsentService $consents): View|RedirectResponse
    {
        $patient = $request->user()->patient()->firstOrFail();
        $required = ['DATA_PRIVACY', 'TERMS_OF_USE'];
        $missing = array_values(array_filter(
            $required,
            fn (string $type): bool => ! $consents->hasCurrentGrant($patient, $type)
        ));

        if ($missing === []) {
            return redirect()->intended(route('dashboard'));
        }

        return view('legal.reaccept', [
            'missing' => $missing,
            'types' => config('consents.types'),
            'version' => config('privacy.notice_version'),
            'termsVersion' => config('privacy.terms_version'),
            'effectiveDate' => config('privacy.effective_date'),
        ]);
    }

    public function store(Request $request, PatientConsentService $consents): RedirectResponse
    {
        $data = $request->validate([
            'privacy_notice_accepted' => ['accepted'],
            'terms_accepted' => ['accepted'],
        ]);
        $patient = $request->user()->patient()->firstOrFail();

        DB::transaction(function () use ($patient, $request, $consents): void {
            foreach (['DATA_PRIVACY', 'TERMS_OF_USE'] as $type) {
                if (! $consents->hasCurrentGrant($patient, $type)) {
                    $consents->record($patient, $type, true, $request->user(), 'self');
                }
            }
        });

        return redirect()->intended(route('dashboard'))->with('success', 'Your privacy notice and terms acceptance have been recorded.');
    }
}
