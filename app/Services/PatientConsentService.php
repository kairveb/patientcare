<?php

namespace App\Services;

use App\Models\Patient;
use App\Models\PatientConsent;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class PatientConsentService
{
    public function hasCurrentGrant(Patient $patient, string $type): bool
    {
        $latest = $this->latest($patient, $type);

        return $latest !== null
            && $latest->granted
            && $latest->version === config("consents.types.{$type}.version");
    }

    public function latest(Patient $patient, string $type): ?PatientConsent
    {
        return PatientConsent::query()
            ->whereIn('patient_id', $this->consentPatientIds($patient))
            ->where('type', $type)
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->first();
    }

    public function history(Patient $patient): Collection
    {
        return PatientConsent::query()
            ->whereIn('patient_id', $this->consentPatientIds($patient))
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->get();
    }

    private function consentPatientIds(Patient $patient): array
    {
        $mergedPatientIds = Patient::withTrashed()
            ->where('merged_into_patient_id', $patient->id)
            ->pluck('id')
            ->all();

        return array_values(array_unique([$patient->id, ...$mergedPatientIds]));
    }

    public function record(
        Patient $patient,
        string $type,
        bool $granted,
        ?User $recordedBy,
        string $channel
    ): PatientConsent {
        abort_unless(isset(config('consents.types')[$type]), 422, 'Unknown consent type.');
        abort_unless(in_array($channel, ['self', 'staff'], true), 422, 'Unknown consent channel.');

        return DB::transaction(function () use ($patient, $type, $granted, $recordedBy, $channel): PatientConsent {
            $version = config("consents.types.{$type}.version");
            $consent = PatientConsent::create([
                'patient_id' => $patient->id,
                'type' => $type,
                'version' => $version,
                'granted' => $granted,
                'recorded_at' => now(),
                'recorded_by' => $recordedBy?->id,
                'channel' => $channel,
            ]);

            app(AuditLogService::class)->log(
                'RECORD_PATIENT_CONSENT',
                'patient_consent',
                $consent->id,
                'SUCCESS',
                [
                    'patient_id' => $patient->id,
                    'type' => $type,
                    'version' => $version,
                    'granted' => $granted,
                    'channel' => $channel,
                ],
                $recordedBy?->id
            );

            return $consent;
        });
    }
}
