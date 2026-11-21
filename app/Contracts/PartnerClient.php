<?php

namespace App\Contracts;

use App\Models\IntegrationPartner;

interface PartnerClient
{
    /**
     * @return array{status:int, body:array}
     */
    public function send(
        IntegrationPartner $partner,
        string $path,
        array $payload,
        string $idempotencyKey,
        string $requestId
    ): array;
}
