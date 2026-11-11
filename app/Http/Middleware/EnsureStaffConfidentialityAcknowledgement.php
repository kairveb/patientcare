<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStaffConfidentialityAcknowledgement
{
    private const APPLICABLE_ROLES = ['super-admin', 'admin', 'registration', 'nurse', 'doctor'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user
            || ! $user->hasAnyRole(self::APPLICABLE_ROLES)
            || $this->isAcknowledgementRoute($request)) {
            return $next($request);
        }

        $hasSignedIn = AuditLog::query()
            ->where('user_id', $user->id)
            ->where('action', 'LOGIN_SUCCESS')
            ->exists();
        if (! $hasSignedIn) {
            return $next($request);
        }

        $version = (string) config('privacy.confidentiality_notice_version', '1.0');
        $acknowledged = AuditLog::query()
            ->where('user_id', $user->id)
            ->where('action', 'CONFIDENTIALITY_NOTICE_ACKNOWLEDGED')
            ->where('metadata->version', $version)
            ->exists();
        if ($acknowledged) {
            return $next($request);
        }

        if ($request->is('api/*')) {
            return response()->json([
                'success' => false,
                'code' => 'confidentiality_acknowledgement_required',
                'message' => 'Review and acknowledge the staff and clinician confidentiality notice before continuing.',
                'data' => [
                    'notice' => 'Access patient information only for assigned care and operational duties. Keep records confidential, do not disclose them outside authorized workflows, and promptly report suspected exposure. This informational notice does not establish legal compliance or replace institutional policy.',
                    'version' => $version,
                    'acknowledgement_url' => route('api.v1.auth.confidentiality-acknowledgment'),
                ],
            ], 428);
        }

        return redirect()->route('confidentiality.notice');
    }

    private function isAcknowledgementRoute(Request $request): bool
    {
        return $request->routeIs(
            'confidentiality.notice',
            'confidentiality.acknowledge',
            'api.v1.auth.confidentiality-acknowledgment',
            'logout',
            'api.v1.auth.logout'
        );
    }
}
