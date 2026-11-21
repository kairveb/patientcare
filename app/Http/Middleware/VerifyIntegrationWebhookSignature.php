<?php

namespace App\Http\Middleware;

use App\Models\IntegrationPartner;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class VerifyIntegrationWebhookSignature
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $requestId = $request->attributes->get('request_id') ?: (string) str()->uuid();
        $request->attributes->set('request_id', $requestId);
        $code = strtolower((string) ($request->route('partner') ?: $request->segment(4)));
        $partner = IntegrationPartner::query()->where('code', $code)->first();
        $headerPartner = strtolower((string) $request->header('X-Partner-Code'));
        $apiKey = (string) $request->header('X-Integration-Key');
        $timestamp = (string) $request->header('X-Webhook-Timestamp');
        $signature = (string) $request->header('X-Webhook-Signature');
        $now = now()->timestamp;

        if ($request->user() || $request->headers->has('Authorization')) {
            return $this->error($requestId, 'webhook_signature_invalid', 'A valid partner webhook signature is required.', 401);
        }
        if (! $partner || ! $partner->active || $headerPartner !== $code
            || ! $partner->api_key_hash || ! Hash::check($apiKey, $partner->api_key_hash)
            || ! $partner->webhook_signing_secret
            || ! in_array($ability, $partner->allowed_abilities ?? [], true)
            || ! ctype_digit($timestamp)
            || abs($now - (int) $timestamp) > config('integrations.webhook_tolerance_seconds', 300)
            || ! preg_match('/^[a-f0-9]{64}$/i', $signature)) {
            return $this->error($requestId, 'webhook_signature_invalid', 'A valid, current partner webhook signature is required.', 401);
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $partner->webhook_signing_secret);
        if (! hash_equals($expected, strtolower($signature))) {
            return $this->error($requestId, 'webhook_signature_invalid', 'A valid partner webhook signature is required.', 401);
        }

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
