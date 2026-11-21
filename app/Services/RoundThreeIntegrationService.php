<?php

namespace App\Services;

use App\Models\IntegrationExport;
use App\Models\IntegrationLog;
use App\Models\IntegrationPartner;
use App\Models\IntegrationProviderVersion;
use App\Models\Provider;
use App\Models\ProviderSchedule;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RoundThreeIntegrationService
{
    public function __construct(
        private readonly IntegrationReferenceMapService $references,
        private readonly AppointmentReassignmentService $reassignments,
        private readonly AuditLogService $audit,
    ) {
    }

    public function syncProviderAvailability(
        IntegrationPartner $partner,
        array $data,
        string $pathReference,
        ?string $requestId = null
    ): array {
        if ($partner->code !== 'hrms' || ! hash_equals($pathReference, (string) $data['provider_ref'])) {
            return $this->error(403, 'provider_reference_mismatch', 'The provider reference is not authorized for this path.');
        }

        $providerId = $this->references->resourceIdFor($partner, 'provider', (string) $data['provider_ref']);
        $provider = $providerId ? Provider::query()->find($providerId) : null;
        if (! $provider || ! $provider->active) {
            $this->log($partner, 'PROVIDER_AVAILABILITY_REJECTED', 'FAILED', [
                'provider_ref' => (string) $data['provider_ref'],
                'source_version' => (int) $data['version'],
                'reason' => 'unknown_provider_reference',
            ], $requestId, 404);

            return $this->error(404, 'provider_ref_not_found', 'The partner provider reference was not found.');
        }

        return DB::transaction(function () use ($partner, $provider, $data, $requestId): array {
            Provider::query()->whereKey($provider->id)->lockForUpdate()->firstOrFail();
            $version = IntegrationProviderVersion::query()
                ->where('integration_partner_id', $partner->id)
                ->where('provider_id', $provider->id)
                ->lockForUpdate()
                ->first();
            $incomingVersion = (int) $data['version'];
            if ($version && $incomingVersion <= $version->source_version) {
                $this->log($partner, 'PROVIDER_AVAILABILITY_REJECTED', 'FAILED', [
                    'provider_ref' => (string) $data['provider_ref'],
                    'source_version' => $incomingVersion,
                    'current_version' => $version->source_version,
                    'reason' => 'stale_source_version',
                ], $requestId, 409);

                return $this->error(409, 'stale_source_version', 'The availability update is stale.');
            }

            $incomingScheduleKeys = collect($data['schedule'])
                ->map(fn (array $schedule): string => $schedule['day'].'|'.$schedule['start'])
                ->all();
            ProviderSchedule::query()
                ->where('provider_id', $provider->id)
                ->where('integration_partner_id', $partner->id)
                ->lockForUpdate()
                ->get()
                ->each(function (ProviderSchedule $schedule) use ($incomingScheduleKeys): void {
                    if (! in_array($schedule->day_of_week.'|'.$schedule->start_time, $incomingScheduleKeys, true)) {
                        $schedule->delete();
                    }
                });

            foreach ($data['schedule'] as $scheduleData) {
                $schedule = ProviderSchedule::query()
                    ->where('provider_id', $provider->id)
                    ->where('day_of_week', (int) $scheduleData['day'])
                    ->where('start_time', $scheduleData['start'])
                    ->where(function ($query) use ($partner): void {
                        $query->whereNull('integration_partner_id')
                            ->orWhere('integration_partner_id', $partner->id);
                    })
                    ->orderByRaw('CASE WHEN integration_partner_id = ? THEN 0 ELSE 1 END', [$partner->id])
                    ->lockForUpdate()
                    ->first();
                if ($schedule) {
                    $schedule->update([
                        'end_time' => $scheduleData['end'],
                        'integration_partner_id' => $partner->id,
                    ]);
                } else {
                    ProviderSchedule::create([
                        'provider_id' => $provider->id,
                        'integration_partner_id' => $partner->id,
                        'day_of_week' => (int) $scheduleData['day'],
                        'start_time' => $scheduleData['start'],
                        'end_time' => $scheduleData['end'],
                        'slot_duration' => 30,
                    ]);
                }
            }

            if ($version) {
                $version->update(['source_version' => $incomingVersion]);
            } else {
                IntegrationProviderVersion::create([
                    'integration_partner_id' => $partner->id,
                    'provider_id' => $provider->id,
                    'source_version' => $incomingVersion,
                ]);
            }

            foreach ($data['unavailable'] as $period) {
                $this->reassignments->markUnavailable([
                    'provider_id' => $provider->id,
                    'starts_at' => $period['starts_at'],
                    'ends_at' => $period['ends_at'],
                    'reason' => $this->unavailabilityReason($period['reason_code']),
                    'details' => null,
                ], null);
            }

            $this->log($partner, 'PROVIDER_AVAILABILITY_APPLIED', 'SUCCESS', [
                'provider_ref' => (string) $data['provider_ref'],
                'source_version' => $incomingVersion,
                'schedule_count' => count($data['schedule']),
                'unavailable_count' => count($data['unavailable']),
            ], $requestId, 200);

            return [
                'status' => 200,
                'body' => ['success' => true, 'data' => ['status' => 'APPLIED', 'version' => $incomingVersion]],
            ];
        });
    }

    public function updateHadsExportStatus(
        IntegrationPartner $partner,
        array $data,
        ?string $requestId = null
    ): array {
        if ($partner->code !== 'hads') {
            return $this->error(403, 'partner_forbidden', 'This webhook is not authorized for this partner.');
        }

        $status = strtoupper((string) $data['status']);
        if (! in_array($status, ['QUEUED', 'PROCESSING', 'READY', 'FAILED', 'EXPIRED'], true)) {
            return $this->error(422, 'invalid_status', 'The export status is not supported.');
        }

        $export = IntegrationExport::query()
            ->where('integration_partner_id', $partner->id)
            ->where('export_ref', $data['export_id'])
            ->lockForUpdate()
            ->first();
        if (! $export) {
            return $this->error(404, 'export_not_found', 'The HADS export reference was not found.');
        }

        if (! $this->validExportTransition($export->status, $status)) {
            $this->log($partner, 'HADS_EXPORT_STATUS_REJECTED', 'FAILED', [
                'export_ref' => $export->export_ref,
                'from_status' => $export->status,
                'to_status' => $status,
                'reason' => 'invalid_status_transition',
            ], $requestId, 409);
            $this->audit->log('HADS_EXPORT_STATUS_REJECTED', 'integration_export', $export->id, 'FAILURE', [
                'from_status' => $export->status,
                'to_status' => $status,
            ]);

            return $this->error(409, 'invalid_status_transition', 'The export status transition is not allowed.');
        }

        $export->update(['status' => $status]);
        $this->log($partner, 'HADS_EXPORT_STATUS_APPLIED', 'SUCCESS', [
            'export_ref' => $export->export_ref,
            'status' => $status,
        ], $requestId, 200);
        $this->audit->log('HADS_EXPORT_STATUS_UPDATED', 'integration_export', $export->id, 'SUCCESS', [
            'status' => $status,
        ]);

        return ['status' => 200, 'body' => ['success' => true, 'data' => ['status' => $status]]];
    }

    public function updateSasAccountStatus(
        IntegrationPartner $partner,
        array $data,
        ?string $requestId = null
    ): array {
        if ($partner->code !== 'sas') {
            return $this->error(403, 'partner_forbidden', 'This webhook is not authorized for this partner.');
        }

        $status = strtoupper((string) $data['status']);
        if (! in_array($status, ['ACTIVE', 'SUSPENDED', 'DISABLED'], true)) {
            return $this->error(422, 'invalid_status', 'The account status is not supported.');
        }
        $userId = $this->references->resourceIdFor($partner, 'user', (string) $data['external_id']);
        $user = $userId ? User::query()->lockForUpdate()->find($userId) : null;
        if (! $user) {
            $this->log($partner, 'SAS_ACCOUNT_STATUS_REJECTED', 'FAILED', [
                'external_ref' => (string) $data['external_id'],
                'reason' => 'unknown_user_reference',
            ], $requestId, 404);

            return $this->error(404, 'external_id_not_found', 'The partner user reference was not found.');
        }
        if ($status !== 'ACTIVE' && $user->hasAnyRole(['admin', 'super-admin'])) {
            $this->log($partner, 'SAS_ACCOUNT_STATUS_REJECTED', 'FAILED', [
                'external_ref' => (string) $data['external_id'],
                'reason' => 'privileged_local_account',
            ], $requestId, 409);

            return $this->error(409, 'privileged_account_protected', 'SAS cannot suspend a local administrator account.');
        }

        if ($status === 'ACTIVE'
            && ! $user->is_active
            && ($user->account_status_source !== 'sas' || $user->sas_account_status !== 'SUSPENDED')) {
            $this->log($partner, 'SAS_ACCOUNT_STATUS_REJECTED', 'FAILED', [
                'external_ref' => (string) $data['external_id'],
                'reason' => 'locally_disabled_account',
            ], $requestId, 409);

            return $this->error(409, 'account_reactivation_conflict', 'Only an account suspended by SAS may be reactivated by SAS.');
        }

        $active = $status === 'ACTIVE';
        if ($user->sas_account_status === 'DISABLED' && $status !== 'DISABLED') {
            $this->log($partner, 'SAS_ACCOUNT_STATUS_REJECTED', 'FAILED', [
                'external_ref' => (string) $data['external_id'],
                'reason' => 'sas_disabled_account_is_terminal',
            ], $requestId, 409);

            return $this->error(409, 'disabled_account_terminal', 'A SAS-disabled account cannot be reactivated or resuspended.');
        }

        if ($active && ! $user->is_active) {
            $user->forceFill([
                'is_active' => true,
                'sas_account_status' => 'ACTIVE',
            ])->save();
        } elseif (! $active) {
            $user->forceFill([
                'is_active' => false,
                'account_status_source' => 'sas',
                'sas_account_status' => $status,
            ])->save();
        } else {
            $user->forceFill(['sas_account_status' => 'ACTIVE'])->save();
        }
        if (! $active) {
            $user->tokens()->delete();
        }

        $this->audit->log('SAS_ACCOUNT_STATUS_UPDATED', 'user', $user->id, 'SUCCESS', [
            'status' => $status,
            'partner' => $partner->code,
        ], null);
        $this->log($partner, 'SAS_ACCOUNT_STATUS_APPLIED', 'SUCCESS', [
            'external_ref' => (string) $data['external_id'],
            'status' => $status,
        ], $requestId, 200);

        return ['status' => 200, 'body' => ['success' => true, 'data' => ['status' => $status]]];
    }

    public function mapSasUserRole(
        IntegrationPartner $partner,
        array $data,
        string $pathReference,
        ?string $requestId = null
    ): array {
        if ($partner->code !== 'sas' || ! hash_equals($pathReference, (string) $data['external_id'])) {
            return $this->error(403, 'external_reference_mismatch', 'The user reference is not authorized for this path.');
        }

        $roleName = config("integrations.partners.sas.role_allowlist.{$data['role']}");
        if (! is_string($roleName) || in_array($roleName, ['admin', 'super-admin'], true)) {
            return $this->error(422, 'role_not_allowed', 'The requested role is not approved for partner mapping.');
        }

        $userId = $this->references->resourceIdFor($partner, 'user', (string) $data['external_id']);
        $user = $userId ? User::query()->lockForUpdate()->find($userId) : null;
        if (! $user) {
            $this->log($partner, 'SAS_ROLE_MAPPING_REJECTED', 'FAILED', [
                'external_ref' => (string) $data['external_id'],
                'reason' => 'unknown_user_reference',
            ], $requestId, 404);

            return $this->error(404, 'external_id_not_found', 'The partner user reference was not found.');
        }
        if ($user->hasAnyRole(['admin', 'super-admin'])) {
            $this->log($partner, 'SAS_ROLE_MAPPING_REJECTED', 'FAILED', [
                'external_ref' => (string) $data['external_id'],
                'reason' => 'privileged_local_account',
            ], $requestId, 409);

            return $this->error(409, 'privileged_account_protected', 'SAS cannot change a local administrator account.');
        }

        $role = Role::query()->where('name', $roleName)->first();
        if (! $role) {
            return $this->error(422, 'role_not_configured', 'The approved role is not configured locally.');
        }

        $previousRoles = $user->roles()->pluck('name')->all();
        $user->roles()->sync([$role->id]);
        $this->audit->log('SAS_ROLE_MAPPING_UPDATED', 'user', $user->id, 'SUCCESS', [
            'previous_roles' => $previousRoles,
            'role' => $roleName,
            'partner' => $partner->code,
        ], null);
        $this->log($partner, 'SAS_ROLE_MAPPING_APPLIED', 'SUCCESS', [
            'external_ref' => (string) $data['external_id'],
            'role' => $roleName,
        ], $requestId, 200);

        return ['status' => 200, 'body' => ['success' => true, 'data' => ['role' => $roleName]]];
    }

    private function validExportTransition(string $from, string $to): bool
    {
        if ($from === $to) {
            return true;
        }

        return in_array($to, match ($from) {
            'QUEUED' => ['PROCESSING', 'READY', 'FAILED', 'EXPIRED'],
            'PROCESSING' => ['READY', 'FAILED', 'EXPIRED'],
            'READY' => ['EXPIRED'],
            default => [],
        }, true);
    }

    private function unavailabilityReason(string $reasonCode): string
    {
        return match (strtoupper($reasonCode)) {
            'SICK_LEAVE' => 'sick_leave',
            'EMERGENCY' => 'emergency',
            default => 'other',
        };
    }

    private function error(int $status, string $code, string $message): array
    {
        return ['status' => $status, 'body' => ['success' => false, 'code' => $code, 'message' => $message]];
    }

    private function log(
        IntegrationPartner $partner,
        string $event,
        string $status,
        array $metadata,
        ?string $requestId,
        int $statusCode
    ): void {
        IntegrationLog::create([
            'integration' => $partner->code,
            'event' => $event,
            'status' => $status,
            'metadata' => $metadata,
            'request_id' => $requestId,
            'integration_partner_id' => $partner->id,
            'status_code' => $statusCode,
        ]);
    }
}
