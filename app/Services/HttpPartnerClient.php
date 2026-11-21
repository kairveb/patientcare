<?php

namespace App\Services;

use App\Contracts\PartnerClient;
use App\Exceptions\PartnerRequestException;
use App\Models\IntegrationPartner;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class HttpPartnerClient implements PartnerClient
{
    public function send(
        IntegrationPartner $partner,
        string $path,
        array $payload,
        string $idempotencyKey,
        string $requestId
    ): array
    {
        if (! $partner->base_url || ! $partner->outbound_token) {
            throw new RuntimeException('Live partner URL and outbound token are not configured.');
        }

        $response = Http::acceptJson()
            ->asJson()
            ->withToken($partner->outbound_token)
            ->withHeaders([
                'Idempotency-Key' => $idempotencyKey,
                'X-Request-ID' => $requestId,
            ])
            ->connectTimeout(5)
            ->timeout(15)
            ->post(rtrim($partner->base_url, '/').'/'.ltrim($path, '/'), $payload);

        if (! $response->successful()) {
            throw new PartnerRequestException('Partner returned a non-success status.', $response->status());
        }

        return ['status' => $response->status(), 'body' => $response->json() ?? []];
    }
}
