<?php

namespace App\Http\Middleware;

use App\Services\PatientConsentService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCurrentPrivacyAcceptance
{
    public function __construct(private readonly PatientConsentService $consents)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! $user->hasRole('patient')) {
            return $next($request);
        }

        if ($request->routeIs(
            'privacy.acceptance',
            'privacy.acceptance.store',
            'logout',
            'api.v1.auth.logout',
            'api.v1.auth.me',
            'api.v1.privacy.acceptance'
        )) {
            return $next($request);
        }

        $patient = $user->patient()->first();
        if (! $patient) {
            return $next($request);
        }

        foreach (['DATA_PRIVACY', 'TERMS_OF_USE'] as $type) {
            if (! $this->consents->hasCurrentGrant($patient, $type)) {
                if ($request->is('api/*')) {
                    return response()->json([
                        'success' => false,
                        'code' => 'privacy_acceptance_required',
                        'message' => 'Accept the current Privacy Notice and Terms before continuing.',
                        'data' => [
                            'privacy_notice_url' => route('privacy.notice'),
                            'terms_url' => route('privacy.terms'),
                            'acceptance_url' => route('api.v1.privacy.acceptance'),
                        ],
                    ], 428);
                }

                return redirect()->route('privacy.acceptance')
                    ->with('warning', 'Please review and accept the current Privacy Notice and Terms before continuing.');
            }
        }

        return $next($request);
    }
}
