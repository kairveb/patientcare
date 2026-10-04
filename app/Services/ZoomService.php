<?php

namespace App\Services;

use App\Models\IntegrationLog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ZoomService
{
    protected bool $enabled;

    public function __construct()
    {
        $this->enabled = $this->resolveEnabledState();
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    protected function resolveEnabledState(): bool
    {
        if (! filter_var(config('services.zoom.enabled', false), FILTER_VALIDATE_BOOL)) {
            return false;
        }

        return filled(config('services.zoom.account_id'))
            && filled(config('services.zoom.client_id'))
            && filled(config('services.zoom.client_secret'));
    }

    /**
     * Get an OAuth access token for the Zoom API.
     */
    protected function accessToken(): ?string
    {
        if (!$this->enabled) {
            return null;
        }

        return Cache::remember('zoom_access_token', 3400, function () {
            try {
                $response = Http::asForm()->withBasicAuth(
                    config('services.zoom.client_id'),
                    config('services.zoom.client_secret')
                )->post('https://zoom.us/oauth/token', [
                    'grant_type' => 'account_credentials',
                    'account_id' => config('services.zoom.account_id'),
                ]);
            } catch (ConnectionException) {
                $this->log('TOKEN', 'FAILED', ['error_code' => 'network_error']);
                return null;
            }

            if ($response->successful()) {
                $token = $response->json('access_token');
                if (is_string($token) && $token !== '') {
                    return $token;
                }
            }

            $this->log('TOKEN', 'FAILED', [
                'status' => $response->status(),
                'error_code' => $this->errorCode($response->json('code')),
            ]);
            return null;
        });
    }

    /**
     * Create a Zoom meeting. Returns array with meeting_id, join_url, start_url.
     * When disabled, returns null (caller must handle development mode).
     */
    public function createMeeting(array $data): ?array
    {
        if (!$this->enabled) {
            $this->log('CREATE_MEETING', 'SKIPPED', ['reason' => 'ZOOM_ENABLED=false']);
            return null;
        }

        $token = $this->accessToken();
        if (!$token) {
            $this->log('CREATE_MEETING', 'FAILED', ['reason' => 'Unable to obtain access token']);
            return null;
        }

        try {
            $response = Http::withToken($token)->post('https://api.zoom.us/v2/users/me/meetings', [
                'topic' => $data['topic'] ?? 'Telehealth Consultation',
                'type' => 2,
                'start_time' => $data['start_time'] ?? now()->toIso8601String(),
                'duration' => $data['duration'] ?? 30,
                'timezone' => 'Asia/Manila',
                'settings' => [
                    'host_video' => true,
                    'participant_video' => true,
                    'join_before_host' => false,
                    'mute_upon_entry' => true,
                    'waiting_room' => true,
                ],
            ]);
        } catch (ConnectionException) {
            $this->log('CREATE_MEETING', 'FAILED', ['error_code' => 'network_error']);
            return null;
        }

        if ($response->successful()) {
            $meetingId = $response->json('id');
            $joinUrl = $response->json('join_url');
            $startUrl = $response->json('start_url');
            if (! filled($meetingId) || ! filled($joinUrl) || ! filled($startUrl)) {
                $this->log('CREATE_MEETING', 'FAILED', ['error_code' => 'invalid_response']);
                return null;
            }

            $this->log('CREATE_MEETING', 'SUCCESS');
            return [
                'meeting_id' => (string) $meetingId,
                'join_url' => $joinUrl,
                'start_url' => $startUrl,
            ];
        }

        $this->log('CREATE_MEETING', 'FAILED', [
            'status' => $response->status(),
            'error_code' => $this->errorCode($response->json('code')),
        ]);
        return null;
    }

    private function errorCode(mixed $code): string
    {
        $code = (string) $code;

        return ctype_digit($code) && strlen($code) <= 10 ? $code : 'unknown';
    }

    protected function log(string $event, string $status, array $metadata = []): void
    {
        try {
            IntegrationLog::create([
                'integration' => 'zoom',
                'event' => $event,
                'status' => $status,
                'metadata' => $metadata,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to write Zoom integration log', [
                'event' => $event,
                'exception' => $e::class,
            ]);
        }
    }
}
