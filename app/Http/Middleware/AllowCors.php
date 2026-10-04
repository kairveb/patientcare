<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AllowCors
{
    private const ALLOWED_METHODS = 'GET, POST, PUT, PATCH, DELETE, OPTIONS';

    private const ALLOWED_HEADERS = 'Accept, Authorization, Content-Type, Origin, X-Requested-With, X-XSRF-TOKEN, X-CSRF-TOKEN, X-HTTP-Method-Override';

    public function handle(Request $request, Closure $next)
    {
        $origin = $request->headers->get('Origin');
        $allowedOrigin = $this->allowedOrigin($request, $origin);

        if ($request->isMethod('OPTIONS')) {
            if ($origin !== null && $allowedOrigin === null) {
                return response('', 403)->header('Vary', 'Origin');
            }

            $response = response('', 204)
                ->header('Access-Control-Allow-Methods', self::ALLOWED_METHODS)
                ->header('Access-Control-Allow-Headers', self::ALLOWED_HEADERS)
                ->header('Access-Control-Max-Age', '600')
                ->header('Vary', 'Origin');

            return $allowedOrigin === null
                ? $response
                : $this->addCorsHeaders($response, $allowedOrigin);
        }

        $response = $next($request);

        if ($allowedOrigin !== null) {
            return $this->addCorsHeaders($response, $allowedOrigin);
        }

        return $response;
    }

    private function allowedOrigin(Request $request, ?string $origin): ?string
    {
        if ($origin === null || $origin === $request->getSchemeAndHttpHost()) {
            return null;
        }

        $configuredOrigins = config('cors.allowed_origins', []);
        $allowedOrigins = is_array($configuredOrigins)
            ? $configuredOrigins
            : explode(',', (string) $configuredOrigins);

        foreach ($allowedOrigins as $configuredOrigin) {
            $configuredOrigin = rtrim(trim((string) $configuredOrigin), '/');
            if ($configuredOrigin !== '' && hash_equals($configuredOrigin, rtrim($origin, '/'))) {
                return $configuredOrigin;
            }
        }

        return null;
    }

    private function addCorsHeaders(Response $response, string $origin): Response
    {
        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Access-Control-Allow-Credentials', 'true');
        $response->headers->set('Access-Control-Allow-Methods', self::ALLOWED_METHODS);
        $response->headers->set('Access-Control-Allow-Headers', self::ALLOWED_HEADERS);
        $response->headers->set('Vary', 'Origin');

        return $response;
    }
}
