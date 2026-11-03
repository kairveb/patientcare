<?php

namespace App\Services;

use App\Models\TelehealthParticipant;
use App\Models\TelehealthJoinToken;
use App\Models\TelehealthSession;
use App\Models\User;
use App\Contracts\TelehealthVideoProvider;
use App\Services\TelehealthVideo\JitsiDemoProvider;
use App\Services\TelehealthVideo\ZoomProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class TelehealthService
{
    public function __construct(
        protected ZoomService $zoom,
        protected ?TelehealthVideoProviderResolver $providers = null,
        protected ?AuditLogService $audit = null
    )
    {
    }

    /**
     * Create a telehealth session for an appointment.
     * Uses an explicitly selected demo provider or a configured Zoom integration.
     */
    public function createSession($appointment): TelehealthSession
    {
        $provider = $this->videoProvider();
        $session = $this->ensureSession($appointment);
        return $provider->provision($session, $appointment)->fresh();
    }

    protected function ensureSession($appointment): TelehealthSession
    {
        return TelehealthSession::firstOrCreate(
            ['appointment_id' => $appointment->id],
            [
                'start_time' => $appointment->starts_at ?? now(),
                'duration' => $this->resolveDuration($appointment),
                'status' => TelehealthSession::STATUS_NOT_CONFIGURED,
            ]
        );
    }

    protected function resolveDuration($appointment): int
    {
        return $appointment->starts_at && $appointment->ends_at
            ? (int) $appointment->starts_at->diffInMinutes($appointment->ends_at)
            : 30;
    }

    /**
     * Add a participant (patient or provider) to a telehealth session.
     */
    public function addParticipant(TelehealthSession $session, ?int $userId, string $role): TelehealthParticipant
    {
        return TelehealthParticipant::firstOrCreate(
            ['telehealth_session_id' => $session->id, 'user_id' => $userId, 'role' => $role]
        );
    }

    public function join(
        TelehealthSession $session,
        ?int $userId,
        string $role,
        bool $privacyAcknowledged = false
    ): TelehealthSession
    {
        TelehealthParticipant::create([
            'telehealth_session_id' => $session->id,
            'user_id' => $userId,
            'role' => $role,
            'joined_at' => now(),
            'privacy_acknowledged_at' => $privacyAcknowledged ? now() : null,
        ]);
        $session->update(['status' => TelehealthSession::STATUS_ACTIVE]);
        return $session;
    }

    public function issueJoinToken(TelehealthSession $session, User $user): string
    {
        $expiresAt = now()->addMinutes((int) config('telehealth.join_token_ttl_minutes', 5));
        $payload = implode('|', [
            $session->id,
            $user->id,
            $expiresAt->timestamp,
            bin2hex(random_bytes(16)),
        ]);
        $signature = hash_hmac('sha256', $payload, (string) config('app.key'));
        $token = rtrim(strtr(base64_encode($payload.'|'.$signature), '+/', '-_'), '=');

        TelehealthJoinToken::create([
            'telehealth_session_id' => $session->id,
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => $expiresAt,
        ]);

        return $token;
    }

    public function consumeJoinToken(TelehealthSession $session, User $user, string $token): void
    {
        $decoded = base64_decode(strtr($token, '-_', '+/'), true);
        $parts = is_string($decoded) ? explode('|', $decoded) : [];
        if (count($parts) !== 5) {
            throw ValidationException::withMessages(['token' => 'The telehealth join token is invalid.']);
        }
        [$sessionId, $userId, $expiresAt, $nonce, $signature] = $parts;
        $payload = implode('|', [$sessionId, $userId, $expiresAt, $nonce]);
        if (! hash_equals(hash_hmac('sha256', $payload, (string) config('app.key')), $signature)
            || (int) $sessionId !== (int) $session->id
            || (int) $userId !== (int) $user->id
            || (int) $expiresAt < now()->timestamp) {
            throw ValidationException::withMessages(['token' => 'The telehealth join token is expired or invalid.']);
        }

        DB::transaction(function () use ($session, $user, $token): void {
            $record = TelehealthJoinToken::query()
                ->where('telehealth_session_id', $session->id)
                ->where('user_id', $user->id)
                ->where('token_hash', hash('sha256', $token))
                ->lockForUpdate()
                ->first();
            if (! $record || $record->used_at || $record->expires_at->isPast()) {
                throw ValidationException::withMessages(['token' => 'The telehealth join token has expired or has already been used.']);
            }
            $record->update(['used_at' => now()]);
        });
    }

    public function providerJoinDetails(TelehealthSession $session, User $user): array
    {
        $appointment = $session->appointment()->firstOrFail();
        $provider = $this->videoProvider();
        $session = $provider->provision($session, $appointment);

        return $provider->joinDetails($session, $user);
    }

    public function providerName(): string
    {
        return $this->videoProvider()->name();
    }

    public function joinWindowReason(TelehealthSession $session): ?string
    {
        $appointment = $session->appointment;
        if (! $appointment) {
            return 'appointment_missing';
        }
        if (! in_array($appointment->status, [
            \App\Models\Appointment::STATUS_CONFIRMED,
            \App\Models\Appointment::STATUS_CHECKED_IN,
            \App\Models\Appointment::STATUS_IN_CONSULTATION,
        ], true)) {
            return 'appointment_not_confirmed';
        }
        $opensAt = $appointment->starts_at->copy()->subMinutes((int) config('telehealth.join_early_minutes', 15));
        $closesAt = $appointment->ends_at->copy()->addMinutes((int) config('telehealth.join_late_minutes', 30));
        if (now()->lt($opensAt)) {
            return 'too_early';
        }
        if (now()->gt($closesAt)) {
            return 'too_late';
        }
        if (in_array($session->status, [TelehealthSession::STATUS_ESCALATED, TelehealthSession::STATUS_CANCELLED, TelehealthSession::STATUS_COMPLETED], true)) {
            return 'session_not_joinable';
        }

        return null;
    }

    public function start(TelehealthSession $session): TelehealthSession
    {
        $session->update([
            'status' => TelehealthSession::STATUS_ACTIVE,
            'start_time' => $session->start_time ?? now(),
        ]);

        return $session->fresh();
    }

    public function cancel(TelehealthSession $session): TelehealthSession
    {
        $session->update(['status' => TelehealthSession::STATUS_CANCELLED]);
        return $session->fresh();
    }

    public function complete(TelehealthSession $session): TelehealthSession
    {
        $session->update(['status' => TelehealthSession::STATUS_COMPLETED]);
        return $session->fresh();
    }

    public function end(TelehealthSession $session): TelehealthSession
    {
        return $this->complete($session);
    }

    public function isConfigured(): bool
    {
        return $this->zoom->enabled();
    }

    private function videoProvider(): TelehealthVideoProvider
    {
        if ($this->providers) {
            return $this->providers->resolve();
        }

        $configuredProvider = config('telehealth.provider');
        if ($configuredProvider === 'jitsi_demo') {
            return new JitsiDemoProvider();
        }
        if ($configuredProvider === 'zoom') {
            if (! $this->zoom->enabled()) {
                throw new RuntimeException('Zoom telehealth was selected but Zoom is not configured.');
            }

            return new ZoomProvider($this->zoom);
        }

        if ($this->zoom->enabled()) {
            return new ZoomProvider($this->zoom);
        }

        throw new RuntimeException(
            'No telehealth provider is configured. Set TELEHEALTH_PROVIDER=jitsi_demo only for an explicit demo connection, or configure Zoom.'
        );
    }
}
