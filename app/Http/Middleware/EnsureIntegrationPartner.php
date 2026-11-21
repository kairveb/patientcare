<?php

namespace App\Http\Middleware;

use App\Models\IntegrationPartner;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class EnsureIntegrationPartner
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $requestId = $request->attributes->get('request_id') ?: (string) str()->uuid();
        $request->attributes->set('request_id', $requestId);
        $partnerCode = strtolower((string) $request->header('X-Partner-Code'));
        $key = (string) $request->header('X-Integration-Key');

        if ($request->user()) {
            return $this->error($requestId, 'partner_auth_required', 'Human sessions cannot authenticate as integration partners.', 401);
        }
        if ($request->headers->has('Authorization') || $partnerCode === '' || $key === '') {
            return $this->error($requestId, 'partner_auth_required', 'Valid partner credentials are required.', 401);
        }

        $partner = IntegrationPartner::query()->where('code', $partnerCode)->first();
        if (! $partner || ! $partner->active || ! $partner->api_key_hash || ! Hash::check($key, $partner->api_key_hash)) {
            return $this->error($requestId, 'partner_auth_invalid', 'Partner credentials are invalid.', 401);
        }
        if (! in_array($ability, $partner->allowed_abilities ?? [], true)) {
            return $this->error($requestId, 'partner_ability_forbidden', 'The partner is not allowed to perform this operation.', 403);
        }

        $partner->forceFill(['last_used_at' => now()])->save();
        $request->attributes->set('integration_partner', $partner);

        return $next($request);
    }

    private function error(string $requestId, string $code, string $message, int $status): Response
    {
        return response()->json([
            'success' => false,
            'code' => $code,
            'message' => $message,
            'request_id' => $requestId,
        ], $status);
    }
}
