<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\LoginOtpService;
use App\Services\PatientConsentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ApiAuthController extends Controller
{
    /**
     * Issue a Sanctum token for an authenticated user.
     */
    public function login(Request $request, LoginOtpService $otp, AuditLogService $audit): JsonResponse
    {
        $data = $request->validate([
            'identifier' => ['required_without:email', 'nullable', 'string', 'max:255'],
            'email' => ['required_without:identifier', 'nullable', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ]);
        $identifier = Str::lower(trim((string) ($data['identifier'] ?? $data['email'])));
        $user = User::query()
            ->where(fn ($query) => $query->whereRaw('LOWER(email) = ?', [$identifier])
                ->orWhereRaw('LOWER(username) = ?', [$identifier]))
            ->first();
        $key = 'login:'.Str::transliterate(($user ? 'user:'.$user->id : 'identifier:'.$identifier).'|'.$request->ip());
        $maximum = (int) config('security.login_max_attempts', 5);

        if (RateLimiter::tooManyAttempts($key, $maximum)) {
            $this->recordLockout($audit, $user);

            return response()->json([
                'success' => false,
                'message' => 'Too many failed sign-in attempts. Try again in '.max(1, (int) ceil(RateLimiter::availableIn($key) / 60)).' minute(s).',
            ], 429);
        }

        if (! $user || ! $user->is_active || ! Hash::check($data['password'], $user->password)) {
            $audit->log('LOGIN_FAILURE', 'authentication', $user?->id, 'FAILURE');
            $attempts = RateLimiter::hit($key, (int) config('security.login_lockout_minutes', 2) * 60);
            if ($attempts >= $maximum) {
                $this->recordLockout($audit, $user);

                return response()->json([
                    'success' => false,
                    'message' => 'Too many failed sign-in attempts. Try again in '.(int) config('security.login_lockout_minutes', 2).' minute(s).',
                ], 429);
            }

            throw ValidationException::withMessages(['email' => ['The provided credentials are incorrect.']]);
        }

        RateLimiter::clear($key);

        if (config('security.otp_enabled', true)) {
            $challenge = $otp->issueApi($user);
            $audit->log('OTP_SENT', 'authentication', $user->id, 'SUCCESS', ['channel' => 'api']);

            return response()->json([
                'success' => true,
                'message' => 'A one-time sign-in code was sent to your registered email.',
                'data' => [
                    'challenge_token' => $challenge,
                    'expires_in_seconds' => (int) config('security.otp_ttl_minutes', 5) * 60,
                ],
            ], 202);
        }

        Log::warning('Email one-time password verification is disabled for a successful API password login.', [
            'user_id' => $user->id,
        ]);
        $user->forceFill(['last_activity_at' => now()])->save();
        $token = $user->createToken('api-token')->plainTextToken;
        $audit->log('LOGIN_SUCCESS', 'authentication', $user->id);

        return response()->json([
            'success' => true,
            'message' => 'Login successful.',
            'data' => $this->tokenPayload($user, $token),
        ]);
    }

    public function verifyOtp(Request $request, LoginOtpService $otp, AuditLogService $audit): JsonResponse
    {
        $data = $request->validate([
            'challenge_token' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]+$/'],
            'code' => ['required', 'string', 'size:6', 'regex:/^[0-9]{6}$/'],
        ]);
        [$user, $result] = $otp->verifyApiChallenge($data['challenge_token'], $data['code']);

        if (! $user || ! $user->is_active || $result !== LoginOtpService::VERIFIED) {
            if ($user) {
                $audit->log('OTP_FAILED', 'authentication', $user->id, 'FAILURE', ['reason' => $result, 'channel' => 'api']);
            }

            return response()->json([
                'success' => false,
                'message' => $result === LoginOtpService::EXHAUSTED
                    ? 'Too many incorrect verification codes. Sign in again to request a new code.'
                    : 'The verification code is invalid or expired.',
            ], 422);
        }

        $user->forceFill(['last_activity_at' => now()])->save();
        $audit->log('OTP_VERIFIED', 'authentication', $user->id, 'SUCCESS', ['channel' => 'api']);
        $audit->log('LOGIN_SUCCESS', 'authentication', $user->id);
        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Login successful.',
            'data' => $this->tokenPayload($user, $token),
        ]);
    }

    public function resendOtp(Request $request, LoginOtpService $otp, AuditLogService $audit): JsonResponse
    {
        $data = $request->validate([
            'challenge_token' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]+$/'],
        ]);
        $challengeHash = hash('sha256', $data['challenge_token']);
        $user = User::query()->where('login_otp_challenge_hash', $challengeHash)->first();

        if (! $user || ! $user->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Your verification session is no longer active. Please sign in again.',
            ], 422);
        }

        if ((int) $user->login_otp_attempts >= (int) config('security.otp_max_attempts', 5)
            || (int) $user->login_otp_resends >= (int) config('security.otp_max_resends', 3)) {
            return response()->json([
                'success' => false,
                'message' => 'The verification limit has been reached. Please sign in again.',
            ], 429);
        }

        $sentAt = $user->login_otp_sent_at;
        $cooldown = (int) config('security.otp_resend_cooldown_seconds', 30);
        if ($sentAt && now()->lt($sentAt->copy()->addSeconds($cooldown))) {
            return response()->json([
                'success' => false,
                'message' => 'Please wait before requesting another code.',
            ], 429);
        }

        $otp->resendApi($data['challenge_token']);
        $audit->log('OTP_SENT', 'authentication', $user->id, 'SUCCESS', ['channel' => 'api', 'resend' => true]);

        return response()->json([
            'success' => true,
            'message' => 'A new one-time sign-in code was sent.',
            'data' => ['expires_in_seconds' => (int) config('security.otp_ttl_minutes', 5) * 60],
        ]);
    }

    /**
     * Revoke the current token.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully.',
        ]);
    }

    /**
     * Return the authenticated user.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('roles');

        return response()->json([
            'success' => true,
            'message' => 'User retrieved successfully.',
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->roles->pluck('name'),
            ],
        ]);
    }

    public function acceptPrivacy(Request $request, PatientConsentService $consents): JsonResponse
    {
        $data = $request->validate([
            'privacy_notice_accepted' => ['required', 'accepted'],
            'terms_accepted' => ['required', 'accepted'],
        ]);
        $user = $request->user();
        abort_unless($user?->hasRole('patient'), 403, 'Only patient accounts can accept these documents.');
        $patient = $user->patient()->firstOrFail();

        foreach (['DATA_PRIVACY', 'TERMS_OF_USE'] as $type) {
            if (! $consents->hasCurrentGrant($patient, $type)) {
                $consents->record($patient, $type, true, $user, 'self');
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Current Privacy Notice and Terms acceptance recorded.',
            'data' => [
                'privacy_notice_version' => config('consents.types.DATA_PRIVACY.version'),
                'terms_version' => config('consents.types.TERMS_OF_USE.version'),
            ],
        ]);
    }

    private function tokenPayload(User $user, string $token): array
    {
        return [
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'username' => $user->username,
                'roles' => $user->roles->pluck('name'),
            ],
        ];
    }

    private function recordLockout(AuditLogService $audit, ?User $user): void
    {
        $audit->log('LOGIN_LOCKOUT', 'authentication', $user?->id, 'FAILURE');
    }
}
