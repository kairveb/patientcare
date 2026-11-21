<?php

namespace App\Services;

use App\Models\CareRequest;
use App\Models\DischargeSummary;
use App\Models\Encounter;
use App\Models\ErVisit;
use App\Models\IntegrationPartner;
use App\Models\Prescription;
use Illuminate\Support\Facades\Log;

class RoundTwoIntegrationService
{
    public function enqueueApprovedCareRequest(CareRequest $request): void
    {
        $partner = match ($request->type) {
            CareRequest::TYPE_LAB => 'lis',
            CareRequest::TYPE_IMAGING => 'ris',
            default => null,
        };
        if ($partner === null) {
            return;
        }

        $this->enqueue($partner, 'care_request_'.$partner, $request->id, [
            'purpose' => $partner.'_care_request',
            'version' => (string) $request->updated_at?->timestamp,
        ]);
    }

    public function enqueuePrescription(Prescription $prescription): void
    {
        $this->enqueue('pms', 'prescription', $prescription->id, [
            'purpose' => 'pms_prescription',
            'version' => (string) $prescription->prescribed_at?->timestamp,
        ]);
    }

    public function enqueueCompletedEncounter(Encounter $encounter): void
    {
        $this->enqueue('emrs', 'emrs_encounter', $encounter->id, [
            'purpose' => 'emrs_encounter_exchange',
            'version' => (string) $encounter->ended_at?->timestamp,
        ]);
    }

    public function enqueueDischargedSummary(DischargeSummary $summary): void
    {
        $this->enqueue('emrs', 'emrs_discharge_summary', $summary->id, [
            'purpose' => 'emrs_discharge_exchange',
            'version' => (string) ($summary->approved_at?->timestamp ?? $summary->updated_at?->timestamp),
        ]);
    }

    public function enqueueErDischarge(ErVisit $visit): void
    {
        $summary = $visit->dischargeSummary;
        if ($summary && $summary->status === DischargeSummary::STATUS_APPROVED) {
            $this->enqueueDischargedSummary($summary);
        }
    }

    private function enqueue(string $partnerCode, string $entityType, int $entityId, array $reference): void
    {
        $active = IntegrationPartner::query()->where('code', $partnerCode)->where('active', true)->exists();
        if (! $active) {
            Log::warning('Partner workflow was not queued because the integration partner is unavailable.', [
                'partner' => $partnerCode,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
            ]);

            return;
        }

        app(IntegrationOutboxService::class)->enqueue($partnerCode, $entityType, $entityId, $reference);
    }
}
