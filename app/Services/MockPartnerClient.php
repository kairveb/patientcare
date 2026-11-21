<?php

namespace App\Services;

use App\Contracts\PartnerClient;
use App\Models\IntegrationPartner;
use Illuminate\Support\Str;

class MockPartnerClient implements PartnerClient
{
    public function send(
        IntegrationPartner $partner,
        string $path,
        array $payload,
        string $idempotencyKey,
        string $requestId
    ): array
    {
        if ($partner->code === 'vrds' && $path === '/dispatch-requests') {
            return [
                'status' => 202,
                'body' => [
                    'success' => true,
                    'external_id' => strtoupper($partner->code).'-'.substr($idempotencyKey, 0, 16),
                    'status' => 'ACCEPTED',
                    'mock_callbacks' => [
                        [
                            'event_id' => 'mock-'.$idempotencyKey.'-accepted',
                            'external_id' => strtoupper($partner->code).'-'.substr($idempotencyKey, 0, 16),
                            'status' => 'ACCEPTED',
                        ],
                        [
                            'event_id' => 'mock-'.$idempotencyKey.'-dispatched',
                            'external_id' => strtoupper($partner->code).'-'.substr($idempotencyKey, 0, 16),
                            'status' => 'DISPATCHED',
                            'vehicle_ref' => 'VRDS-MOCK-VEHICLE-01',
                            'eta_at' => now()->addMinutes(15)->toIso8601String(),
                        ],
                        [
                            'event_id' => 'mock-'.$idempotencyKey.'-arrived',
                            'external_id' => strtoupper($partner->code).'-'.substr($idempotencyKey, 0, 16),
                            'status' => 'ARRIVED',
                            'vehicle_ref' => 'VRDS-MOCK-VEHICLE-01',
                        ],
                        [
                            'event_id' => 'mock-'.$idempotencyKey.'-completed',
                            'external_id' => strtoupper($partner->code).'-'.substr($idempotencyKey, 0, 16),
                            'status' => 'COMPLETED',
                            'vehicle_ref' => 'VRDS-MOCK-VEHICLE-01',
                        ],
                    ],
                    'request_id' => $requestId,
                ],
            ];
        }

        return [
            'status' => 202,
            'body' => [
                'success' => true,
                'external_id' => strtoupper($partner->code).'-'.substr($idempotencyKey, 0, 16),
                'status' => 'RECEIVED',
                'request_id' => $requestId,
            ],
        ];
    }

    public function signedCallback(IntegrationPartner $partner, array $payload): array
    {
        $timestamp = (string) now()->timestamp;
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        return [
            'body' => $body,
            'headers' => [
                'X-Partner-Code' => $partner->code,
                'X-Integration-Key' => config("integrations.partners.{$partner->code}.api_key"),
                'X-Webhook-Timestamp' => $timestamp,
                'X-Webhook-Signature' => hash_hmac('sha256', $timestamp.'.'.$body, $partner->webhook_signing_secret),
                'X-Event-Id' => (string) ($payload['event_id'] ?? \Illuminate\Support\Str::uuid()),
            ],
        ];
    }
}
