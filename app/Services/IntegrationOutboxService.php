<?php

namespace App\Services;

use App\Jobs\DeliverIntegrationOutbox;
use App\Models\IntegrationOutbox;
use App\Models\IntegrationPartner;
use Illuminate\Support\Str;

class IntegrationOutboxService
{
    public function enqueue(string $partnerCode, string $entityType, int $entityId, array $reference): ?IntegrationOutbox
    {
        $partner = IntegrationPartner::query()->where('code', $partnerCode)->where('active', true)->first();
        if (! $partner) {
            throw new \RuntimeException("Integration partner [{$partnerCode}] is not configured and active.");
        }

        $key = hash('sha256', implode(':', [
            $partner->id,
            $entityType,
            $entityId,
            (string) ($reference['version'] ?? '1'),
        ]));
        $outbox = IntegrationOutbox::firstOrCreate(
            ['idempotency_key' => $key],
            [
                'integration_partner_id' => $partner->id,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'payload_reference' => $reference,
                'status' => 'PENDING',
            ]
        );

        if ($outbox->wasRecentlyCreated) {
            DeliverIntegrationOutbox::dispatch($outbox->id)->afterCommit();
        }

        return $outbox;
    }
}
