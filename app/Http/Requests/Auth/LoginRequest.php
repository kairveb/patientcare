<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Services\AuditLogService;
use App\Services\LoginOtpService;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'identifier' => trim((string) ($this->input('identifier') ?? $this->input('email', ''))),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'identifier' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ];
    }

    public function authenticate(): void
    {
        $user = $this->findAccount();
        if ($user && config('security.otp_enabled', true)) {
            $otp = app(LoginOtpService::class);
            $otp->expireIfNeeded($user);
            $remaining = $otp->lockoutRemaining($user);
            if ($remaining > 0) {
                throw ValidationException::withMessages([
                    'email' => ["Your verification code expired or failed too many times. Sign-in is locked for {$remaining} more second(s)."],
                ]);
            }
        }
        $this->ensureIsNotRateLimited($user);

        if (! $user || ! $user->is_active || ! Hash::check($this->string('password')->value(), $user->password)) {
            app(AuditLogService::class)->log('LOGIN_FAILURE', 'authentication', $user?->id, 'FAILURE');
            $attempts = RateLimiter::hit(
                $this->throttleKey($user),
                (int) config('security.login_lockout_minutes', 2) * 60
            );

            if ($attempts >= (int) config('security.login_max_attempts', 5)) {
                $this->recordLockout($user);
                $this->throwLockout($user);
            }

            throw ValidationException::withMessages(['email' => trans('auth.failed')]);
        }

        RateLimiter::clear($this->throttleKey($user));
        $this->session()->put('login_challenge', [
            'id' => $user->getKey(),
            'method' => $user->hasMfaEnabled() ? 'totp' : null,
            'started_at' => now()->timestamp,
        ]);
    }

    public function challengeRequired(): bool
    {
        return $this->session()->has('login_challenge');
    }

    public function challengeUser(): ?User
    {
        $challenge = $this->session()->get('login_challenge');

        return is_array($challenge) && isset($challenge['id'])
            ? User::query()->find($challenge['id'])
            : null;
    }

    public function ensureIsNotRateLimited(?User $user = null): void
    {
        $maximum = (int) config('security.login_max_attempts', 5);
        if (! RateLimiter::tooManyAttempts($this->throttleKey($user), $maximum)) {
            return;
        }

        event(new Lockout($this));
        $this->recordLockout($user);
        $this->throwLockout($user);
    }

    public function throttleKey(?User $user = null): string
    {
        $identifier = Str::lower($this->string('identifier')->trim()->value());

        $subject = $user ? 'user:'.$user->id : 'identifier:'.$identifier;

        return 'login:'.Str::transliterate($subject.'|'.$this->ip());
    }

    private function findAccount(): ?User
    {
        $identifier = Str::lower($this->string('identifier')->trim()->value());

        return User::query()
            ->where(fn ($query) => $query->whereRaw('LOWER(email) = ?', [$identifier])
                ->orWhereRaw('LOWER(username) = ?', [$identifier]))
            ->first();
    }

    private function recordLockout(?User $user): void
    {
        app(AuditLogService::class)->log('LOGIN_LOCKOUT', 'authentication', $user?->id, 'FAILURE');
    }

    private function throwLockout(?User $user): never
    {
        $seconds = RateLimiter::availableIn($this->throttleKey($user));
        $minutes = max(1, (int) ceil($seconds / 60));

        throw ValidationException::withMessages([
            'email' => ["Too many failed sign-in attempts. Try again in {$minutes} minute(s)."],
        ]);
    }
}
