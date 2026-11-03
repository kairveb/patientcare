<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\LoginOtpService;
use App\Services\MfaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
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

        $account = $request->challengeUser();
        if (! $account) {
            throw ValidationException::withMessages([
                'email' => [trans('auth.failed')],
            ]);
        }
        $challenge = $request->session()->get('login_challenge');

        if (($challenge['method'] ?? null) === 'totp') {
            $request->session()->regenerate();

            return redirect()->route('mfa.challenge');
        }

        if (config('security.otp_enabled', true)) {
            $request->session()->put('login_challenge.method', 'email');
            app(LoginOtpService::class)->issue($account);
            app(AuditLogService::class)->log('OTP_SENT', 'authentication', $account->id);
            $request->session()->regenerate();

            return redirect()->route('mfa.challenge')
                ->with('status', 'A one-time sign-in code has been sent to your registered email.');
        }

        Log::warning('Email one-time password verification is disabled for a successful password login.', [
            'user_id' => $account->id,
        ]);
        $this->completeLogin($request, $account);

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Show the MFA challenge screen (after password is accepted).
     */
    public function mfaChallenge(Request $request): View|RedirectResponse
    {
        $challenge = $request->session()->get('login_challenge');
        $account = is_array($challenge) && isset($challenge['id'])
            ? User::query()->find($challenge['id'])
            : null;

        if (! $account || ! isset($challenge['started_at'])
            || now()->timestamp - (int) $challenge['started_at'] > ((int) config('security.otp_ttl_minutes', 5) * 60)) {
            if ($account && ($challenge['method'] ?? null) === 'email') {
                app(LoginOtpService::class)->clear($account);
            }
            $request->session()->forget('login_challenge');

            return redirect()->route('login')->withErrors(['email' => 'Your verification session expired. Please sign in again.']);
        }

        return view('auth.mfa-challenge', [
            'challengeMethod' => $challenge['method'] ?? 'email',
            'maskedEmail' => $this->maskedEmail($account->email),
        ]);
    }

    /**
     * Verify the MFA code and complete the login.
     */
    public function mfaVerify(Request $request, MfaService $mfa, LoginOtpService $otp): RedirectResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'size:6'],
        ]);

        $challenge = $request->session()->get('login_challenge');
        $account = is_array($challenge) && isset($challenge['id'])
            ? User::query()->find($challenge['id'])
            : null;
        $maxAge = (int) config('security.otp_ttl_minutes', 5) * 60;
        $totpExpired = ($challenge['method'] ?? null) === 'totp'
            && isset($challenge['started_at'])
            && now()->timestamp - (int) $challenge['started_at'] > $maxAge;

        if (! $account || ! $account->is_active || ! isset($challenge['started_at']) || $totpExpired) {
            $request->session()->forget('login_challenge');

            return redirect()->route('login')->withErrors(['email' => 'Your verification session expired. Please sign in again.']);
        }

        if (($challenge['method'] ?? null) === 'totp') {
            if (! $account->hasMfaEnabled()) {
                $request->session()->forget('login_challenge');

                return redirect()->route('login')->withErrors([
                    'email' => 'Your authenticator setup changed. Please sign in again.',
                ]);
            }
            $verified = $mfa->verifyCode((string) $account->mfa_secret, $request->string('code')->value());
            if (! $verified) {
                $attempts = (int) ($challenge['attempts'] ?? 0) + 1;
                $request->session()->put('login_challenge.attempts', $attempts);
                app(AuditLogService::class)->log('OTP_FAILED', 'authentication', $account->id, 'FAILURE');

                if ($attempts >= (int) config('security.otp_max_attempts', 5)) {
                    $request->session()->forget('login_challenge');

                    return redirect()->route('login')->withErrors(['email' => 'Too many incorrect verification codes. Please sign in again.']);
                }

                return back()->withErrors(['code' => 'The code you entered is invalid.']);
            }

            app(AuditLogService::class)->log('OTP_VERIFIED', 'authentication', $account->id);
        } else {
            $result = $otp->verify($account, $request->string('code')->value());
            if ($result !== LoginOtpService::VERIFIED) {
                app(AuditLogService::class)->log('OTP_FAILED', 'authentication', $account->id, 'FAILURE', [
                    'reason' => $result,
                ]);
                if (in_array($result, [LoginOtpService::EXPIRED, LoginOtpService::EXHAUSTED], true)) {
                    $request->session()->forget('login_challenge');

                    return redirect()->route('login')->withErrors([
                        'email' => $result === LoginOtpService::EXPIRED
                            ? 'Your one-time code expired. Please sign in again.'
                            : 'Too many incorrect verification codes. Please sign in again.',
                    ]);
                }

                return back()->withErrors(['code' => 'That code did not match. Please check your email and try again.']);
            }

            app(AuditLogService::class)->log('OTP_VERIFIED', 'authentication', $account->id);
        }

        $this->completeLogin($request, $account);

        return redirect()->intended(route('dashboard', absolute: false));
    }

    public function mfaResend(Request $request, LoginOtpService $otp): RedirectResponse
    {
        $challenge = $request->session()->get('login_challenge');
        $account = is_array($challenge) && ($challenge['method'] ?? null) === 'email'
            ? User::query()->find($challenge['id'] ?? 0)
            : null;

        if (! $account || ! $account->is_active || ! config('security.otp_enabled', true)) {
            return redirect()->route('login')->withErrors(['email' => 'Your verification session is no longer active. Please sign in again.']);
        }

        $sentAt = $account->login_otp_sent_at;
        $cooldown = (int) config('security.otp_resend_cooldown_seconds', 30);
        if ($sentAt && now()->lt($sentAt->copy()->addSeconds($cooldown))) {
            throw ValidationException::withMessages([
                'code' => ['Please wait before requesting another code.'],
            ]);
        }

        if ((int) $account->login_otp_attempts >= (int) config('security.otp_max_attempts', 5)
            || (int) $account->login_otp_resends >= (int) config('security.otp_max_resends', 3)) {
            $request->session()->forget('login_challenge');

            return redirect()->route('login')->withErrors([
                'email' => 'The verification limit has been reached. Please sign in again.',
            ]);
        }

        $otp->issue($account, true);
        $request->session()->put('login_challenge.started_at', now()->timestamp);
        app(AuditLogService::class)->log('OTP_SENT', 'authentication', $account->id, 'SUCCESS', ['resend' => true]);

        return back()->with('status', 'A new one-time sign-in code has been sent.');
    }

    public function heartbeat(): \Illuminate\Http\Response
    {
        return response()->noContent();
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

    private function completeLogin(Request $request, User $account): void
    {
        Auth::login($account);
        $account->forceFill(['last_activity_at' => now()])->save();
        $request->session()->forget('login_challenge');
        $request->session()->regenerate();
        $request->session()->put('mfa_verified', now()->timestamp);
        app(AuditLogService::class)->log('LOGIN_SUCCESS', 'authentication', $account->id);
    }

    private function maskedEmail(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return substr($name, 0, 1).str_repeat('*', max(2, strlen($name) - 1)).'@'.$domain;
    }
}
