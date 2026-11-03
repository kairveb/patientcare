<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRecentReportPasswordConfirmation
{
    public function handle(Request $request, Closure $next): Response
    {
        $confirmedAt = (int) $request->session()->get('auth.password_confirmed_at', 0);
        $validFor = (int) config('reports.password_confirm_minutes', 5) * 60;

        if ($confirmedAt === 0 || time() - $confirmedAt > $validFor) {
            return redirect()->guest(route('password.confirm'));
        }

        return $next($request);
    }
}
