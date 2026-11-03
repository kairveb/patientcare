<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class ReportPasswordConfirmationController extends Controller
{
    public function store(Request $request, AuditLogService $audit): RedirectResponse
    {
        $data = $request->validate(['password' => ['required', 'string']]);
        $user = $request->user();
        $key = 'report-password-confirm:'.$user->id.':'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $audit->log('REPORT_PASSWORD_CONFIRM_THROTTLED', 'report_export', null, 'DENIED');
            abort(429, 'Too many password confirmation attempts. Try again shortly.');
        }

        if (! Auth::guard('web')->validate([
            'email' => $user->email,
            'password' => $data['password'],
        ])) {
            RateLimiter::hit($key, 60);
            $audit->log('REPORT_PASSWORD_CONFIRM_FAILED', 'report_export', null, 'DENIED');

            throw ValidationException::withMessages(['password' => __('auth.password')]);
        }

        RateLimiter::clear($key);
        $request->session()->put('auth.password_confirmed_at', time());
        $audit->log('REPORT_PASSWORD_CONFIRM_SUCCESS', 'report_export');

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
