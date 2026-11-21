<?php

namespace App\Services;

use App\Models\IntegrationPartner;
use App\Models\IntegrationReferenceMap;
use Illuminate\Support\Str;

class IntegrationReferenceMapService
{
    public function for(IntegrationPartner $partner, string $resourceType, int $resourceId): string
    {
        $mapping = IntegrationReferenceMap::firstOrCreate(
            [
                'integration_partner_id' => $partner->id,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
            ],
            ['emr_reference' => (string) Str::uuid()]
        );

        return $mapping->emr_reference;
    }

    public function resolve(IntegrationPartner $partner, string $resourceType, string $reference): ?IntegrationReferenceMap
    {
        return IntegrationReferenceMap::query()
            ->where('integration_partner_id', $partner->id)
            ->where('resource_type', $resourceType)
            ->where('emr_reference', $reference)
            ->first();
    }

    public function resourceIdFor(IntegrationPartner $partner, string $resourceType, string $reference): ?int
    {
        $mapping = $this->resolve($partner, $resourceType, $reference);

        return $mapping ? (int) $mapping->resource_id : null;
    }
}
