<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\LoginOtpNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Auth\Events\Verified;
use Illuminate\Validation\ValidationException;

class LoginOtpService
{
    public const VERIFIED = 'verified';

    public const INVALID = 'invalid';

    public const EXPIRED = 'expired';

    public const EXHAUSTED = 'exhausted';

    public function issue(User $user, bool $resend = false, ?string $challengeHash = null): void
    {
        $sendKey = 'login-otp:user:'.$user->id.'|ip:'.request()->ip();
        if (! $resend) {
            if (RateLimiter::tooManyAttempts($sendKey, (int) config('security.otp_max_sends', 5))) {
                throw ValidationException::withMessages([
                    'email' => ['Too many verification codes have been sent. Please wait before signing in again.'],
                ]);
            }
            RateLimiter::hit($sendKey, 15 * 60);
        }

        $code = (string) random_int(0, 999999);
        $code = str_pad($code, 6, '0', STR_PAD_LEFT);
        $ttl = (int) config('security.otp_ttl_minutes', 2);

        DB::transaction(function () use ($user, $code, $ttl, $resend, $challengeHash): void {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $changes = [
                'login_otp_hash' => Hash::make($code),
                'login_otp_challenge_hash' => $challengeHash,
                'login_otp_expires_at' => now()->addMinutes($ttl),
                'login_otp_sent_at' => now(),
            ];
            if (! $resend) {
                $changes['login_otp_attempts'] = 0;
                $changes['login_otp_resends'] = 0;
            } else {
                if (($challengeHash !== null && ! hash_equals(
                    (string) $lockedUser->login_otp_challenge_hash,
                    $challengeHash
                ))
                    || (int) $lockedUser->login_otp_attempts >= (int) config('security.otp_max_attempts', 5)
                    || (int) $lockedUser->login_otp_resends >= (int) config('security.otp_max_resends', 3)
                    || ($lockedUser->login_otp_sent_at
                        && now()->lt($lockedUser->login_otp_sent_at->copy()->addSeconds((int) config('security.otp_resend_cooldown_seconds', 30))))) {
                    throw ValidationException::withMessages([
                        'code' => ['Please wait before requesting another sign-in code.'],
                    ]);
                }
                $changes['login_otp_resends'] = (int) $lockedUser->login_otp_resends + 1;
            }
            $lockedUser->forceFill($changes)->save();
        });

        $user->notify(new LoginOtpNotification($code, $ttl));
    }

    public function expireIfNeeded(User $user): int
    {
        return DB::transaction(function () use ($user): int {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            if (! $lockedUser->login_otp_hash
                || ! $lockedUser->login_otp_expires_at
                || ! $lockedUser->login_otp_expires_at->isPast()) {
                return 0;
            }

            $this->clear($lockedUser, false);

            return $this->lockForOtpFailure($lockedUser, 'expired');
        });
    }

    public function lockoutRemaining(User $user): int
    {
        $key = $this->lockoutKey($user);

        return RateLimiter::tooManyAttempts($key, 1)
            ? RateLimiter::availableIn($key)
            : 0;
    }

    public function markEmailAsVerified(User $user): void
    {
        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }
    }

    public function issueApi(User $user): string
    {
        $challenge = bin2hex(random_bytes(32));
        $this->issue($user, false, hash('sha256', $challenge));

        return $challenge;
    }

    public function resendApi(string $challenge): User
    {
        $challengeHash = hash('sha256', $challenge);
        $user = User::query()->where('login_otp_challenge_hash', $challengeHash)->firstOrFail();
        $this->issue($user, true, $challengeHash);

        return $user->fresh();
    }

    public function verify(User $user, string $code): string
    {
        return DB::transaction(function () use ($user, $code): string {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);

            return $this->verifyLocked($lockedUser, $code);
        });
    }

    /**
     * @return array{0: User|null, 1: string}
     */
    public function verifyApiChallenge(string $challenge, string $code): array
    {
        return DB::transaction(function () use ($challenge, $code): array {
            $user = User::query()
                ->where('login_otp_challenge_hash', hash('sha256', $challenge))
                ->lockForUpdate()
                ->first();

            if (! $user) {
                return [null, self::EXPIRED];
            }

            return [$user, $this->verifyLocked($user, $code)];
        });
    }

    private function verifyLocked(User $user, string $code): string
    {
        if (! $user->login_otp_hash || ! $user->login_otp_expires_at) {
            return self::EXPIRED;
        }

        if ($user->login_otp_expires_at->isPast()) {
            $this->clear($user, false);
            $this->lockForOtpFailure($user, 'expired');

            return self::EXPIRED;
        }

        if ((int) $user->login_otp_attempts >= (int) config('security.otp_max_attempts', 5)) {
            $this->clear($user, false);
            $this->lockForOtpFailure($user, 'attempts_exhausted');

            return self::EXHAUSTED;
        }

        if (! Hash::check($code, $user->login_otp_hash)) {
            $attempts = (int) $user->login_otp_attempts + 1;
            $user->forceFill(['login_otp_attempts' => $attempts])->save();

            if ($attempts >= (int) config('security.otp_max_attempts', 5)) {
                $this->clear($user, false);
                $this->lockForOtpFailure($user, 'attempts_exhausted');

                return self::EXHAUSTED;
            }

            return self::INVALID;
        }

        $this->clear($user);

        return self::VERIFIED;
    }

    private function lockForOtpFailure(User $user, string $reason): int
    {
        $seconds = (int) config('security.otp_lockout_minutes', 5) * 60;
        $key = $this->lockoutKey($user);
        RateLimiter::clear($key);
        RateLimiter::hit($key, $seconds);
        app(AuditLogService::class)->log('OTP_LOCKOUT', 'authentication', $user->id, 'FAILURE', [
            'reason' => $reason,
            'lockout_seconds' => $seconds,
        ]);

        return $seconds;
    }

    private function lockoutKey(User $user): string
    {
        return 'login-otp-lock:user:'.$user->id;
    }

    public function clear(User $user, bool $resetAttempts = true): void
    {
        $data = [
            'login_otp_hash' => null,
            'login_otp_challenge_hash' => null,
            'login_otp_expires_at' => null,
            'login_otp_sent_at' => null,
        ];
        if ($resetAttempts) {
            $data['login_otp_attempts'] = 0;
            $data['login_otp_resends'] = 0;
        }
        $user->forceFill($data)->save();
    }
}
