<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): View
    {
        return view('auth.login');
    }

/**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        // Auth::attempt logs the user in, so clear that temporary login before
        // the guest-only MFA challenge and rotate the session identifier.
        if ($request->mfaRequired()) {
            Auth::guard('web')->logout();
            $request->session()->regenerate();

            return redirect()->route('mfa.challenge');
        }

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Show the MFA challenge screen (after password is accepted).
     */
    public function mfaChallenge(): \Illuminate\View\View
    {
        return view('auth.mfa-challenge');
    }

    /**
     * Verify the MFA code and complete the login.
     */
    public function mfaVerify(Request $request): RedirectResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'size:6'],
        ]);

        $user = $request->session()->get('mfa');

        if (!$user || !$user['verified']) {
            return redirect()->route('login')->withErrors(['email' => 'Your session expired. Please sign in again.']);
        }

        $mfaService = app(\App\Services\MfaService::class);

        if (!$mfaService->verifyCode($user['secret'], $request->input('code'))) {
            return back()->withErrors(['code' => 'The code you entered is invalid.']);
        }

        // Fully authenticate the user.
        $account = User::query()->find($user['id']);
        if (! $account || ! $account->is_active) {
            $request->session()->invalidate();

            return redirect()->route('login')->withErrors([
                'email' => 'Your account is unavailable. Please contact an administrator.',
            ]);
        }

        Auth::login($account);

        $authenticatedUser = Auth::user();
        if ($authenticatedUser) {
            $authenticatedUser->forceFill(['last_activity_at' => now()])->save();
        }

        $request->session()->forget('mfa');
        $request->session()->regenerate();
        $request->session()->put('mfa_verified', now()->timestamp);

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $response = redirect('/');
        $response->headers->remove('Cache-Control');
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

        return $response;
    }
}
