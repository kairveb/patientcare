<?php

namespace App\Http\Middleware;

use App\Models\ApiLog;
use App\Models\IntegrationLog;
use App\Models\IntegrationPartner;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class LogIntegrationApiRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $request->attributes->get('request_id') ?: (string) str()->uuid();
        $request->attributes->set('request_id', $requestId);
        $response = $next($request);
        $partner = $request->attributes->get('integration_partner');
        if (! $partner) {
            $code = strtolower((string) ($request->route('partner') ?: $request->header('X-Partner-Code')));
            $partner = $code !== '' ? IntegrationPartner::query()->where('code', $code)->first() : null;
        }

        try {
            ApiLog::create([
                'user_id' => null,
                'method' => $request->method(),
                'path' => $request->path(),
                'status_code' => $response->getStatusCode(),
                'ip_address' => $request->ip(),
                'request_id' => $requestId,
                'integration_partner_id' => $partner?->id,
            ]);
            IntegrationLog::create([
                'integration' => $partner?->code ?? 'partner-webhook',
                'event' => $request->path(),
                'status' => $response->getStatusCode() < 400 ? 'SUCCESS' : 'FAILED',
                'metadata' => ['request_id' => $requestId],
                'request_id' => $requestId,
                'integration_partner_id' => $partner?->id,
                'status_code' => $response->getStatusCode(),
            ]);
        } catch (\Throwable $exception) {
            Log::error('Failed to write integration API log.', [
                'request_id' => $requestId,
                'exception' => $exception::class,
            ]);
        }

        return $response;
    }
}
