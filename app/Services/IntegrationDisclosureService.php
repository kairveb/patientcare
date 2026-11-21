<?php

namespace App\Services;

use App\Models\IntegrationDisclosure;
use App\Models\IntegrationPartner;
use App\Models\Patient;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class IntegrationDisclosureService
{
    public function __construct(private readonly PatientConsentService $consents)
    {
    }

    public function record(
        IntegrationPartner $partner,
        Patient $patient,
        string $purpose,
        array $fields,
        ?string $requestId = null
    ): IntegrationDisclosure {
        $consentType = strtoupper($partner->code).'_SHARING';
        foreach (['DATA_PRIVACY', $consentType] as $type) {
            if (! $this->consents->hasCurrentGrant($patient, $type)) {
                throw ValidationException::withMessages([
                    'consent' => ["Current {$type} consent is required before sharing data with this partner."],
                ]);
            }
        }

        return IntegrationDisclosure::firstOrCreate(
            [
                'integration_partner_id' => $partner->id,
                'request_id' => $requestId ?: (string) Str::uuid(),
            ],
            [
                'patient_id' => $patient->id,
                'purpose' => $purpose,
                'fields_shared' => array_values(array_unique($fields)),
                'disclosed_at' => now(),
            ]
        );
    }

    public function recordAggregate(
        IntegrationPartner $partner,
        string $purpose,
        array $fields,
        ?string $requestId = null
    ): IntegrationDisclosure {
        if ($partner->code !== 'hads') {
            throw new \InvalidArgumentException('Aggregate disclosure records are reserved for HADS.');
        }

        $allowedFields = [
            'dataset',
            'period_from',
            'period_to',
            'format',
            'aggregate_count',
            'aggregate_category',
            'aggregate_total',
            'snapshot_at',
        ];
        $fields = array_values(array_unique(array_intersect($fields, $allowedFields)));
        if ($fields === []) {
            throw new \InvalidArgumentException('Aggregate disclosure fields must use the approved allow-list.');
        }

        return IntegrationDisclosure::firstOrCreate(
            [
                'integration_partner_id' => $partner->id,
                'request_id' => $requestId ?: (string) Str::uuid(),
            ],
            [
                'patient_id' => null,
                'purpose' => $purpose,
                'fields_shared' => $fields,
                'disclosed_at' => now(),
            ]
        );
    }

    public function recordNoPatient(
        IntegrationPartner $partner,
        string $purpose,
        array $fields,
        ?string $requestId = null
    ): IntegrationDisclosure {
        if ($partner->code !== 'vrds' || $purpose !== 'patient transport dispatch') {
            throw new \InvalidArgumentException('Patient-free disclosures are reserved for VRDS transport dispatch.');
        }

        $allowedFields = ['request_ref', 'priority', 'pickup_zone', 'destination_zone', 'needs', 'passenger_count', 'status'];
        $fields = array_values(array_unique(array_intersect($fields, $allowedFields)));
        if ($fields === []) {
            throw new \InvalidArgumentException('Transport disclosure fields must use the approved allow-list.');
        }

        return IntegrationDisclosure::firstOrCreate(
            [
                'integration_partner_id' => $partner->id,
                'request_id' => $requestId ?: (string) Str::uuid(),
            ],
            [
                'patient_id' => null,
                'purpose' => $purpose,
                'fields_shared' => $fields,
                'disclosed_at' => now(),
            ]
        );
    }
}
