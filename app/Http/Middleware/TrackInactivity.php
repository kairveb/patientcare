<?php

namespace App\Http\Middleware;

use App\Services\AuditLogService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class TrackInactivity
{
    public function __construct(private readonly AuditLogService $audit)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('inactivity.enabled', true)) {
            return $next($request);
        }

        $user = $request->user() ?? Auth::user();

        if (! $user) {
            return $next($request);
        }

        $lastSeen = $user->last_activity_at;
        $timeoutSeconds = (int) config('security.session_idle_minutes', 20) * 60;
        $threshold = now()->subSeconds($timeoutSeconds);

        $isTelehealthJoin = $request->routeIs('telehealth.join', 'telehealth.connect', 'telehealth.leave');
        if (! $isTelehealthJoin && $lastSeen !== null && $lastSeen->lt($threshold)) {
            $this->audit->log('SESSION_TIMEOUT', 'authentication', $user->id);
            if (Auth::guard('web')->check() && $request->hasSession()) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            if ($request->expectsJson() || $request->is('api/*')) {
                $token = $user->currentAccessToken();
                if ($token && method_exists($token, 'delete')) {
                    $token->delete();
                }

                return response()->json([
                    'message' => 'You have been logged out due to inactivity.',
                ], 401);
            }

            return redirect()->route('login')->with('status', 'You have been logged out due to inactivity.');
        }

        if ($isTelehealthJoin || ! $lastSeen || $lastSeen->gte($threshold)) {
            $user->forceFill(['last_activity_at' => now()])->save();
        }

        return $next($request);
    }
}
