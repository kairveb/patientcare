<?php

namespace App\Services;

use App\Models\IntegrationExport;
use App\Models\IntegrationPartner;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class IntegrationExportService
{
    private const DATASETS = [
        'appointments',
        'encounters',
        'triage_levels',
        'er_visits',
        'bed_occupancy',
        'billing_totals',
    ];

    private const FORMATS = ['csv', 'pdf', 'txt', 'xls'];

    public function __construct(
        private readonly IntegrationOutboxService $outbox,
        private readonly IntegrationDisclosureService $disclosures,
        private readonly AuditLogService $audit,
    ) {
    }

    public function request(string $dataset, string $from, string $to, string $format, ?User $requester = null): IntegrationExport
    {
        if (! in_array($dataset, self::DATASETS, true)) {
            throw ValidationException::withMessages(['dataset' => ['Choose an approved aggregate dataset.']]);
        }
        if (! in_array(strtolower($format), self::FORMATS, true)) {
            throw ValidationException::withMessages(['format' => ['Choose CSV, PDF, TXT, or XLS.']]);
        }

        try {
            $start = CarbonImmutable::parse($from)->startOfDay();
            $end = CarbonImmutable::parse($to)->startOfDay();
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages(['from' => ['Choose valid start and end dates.']]);
        }
        if ($start->toDateString() !== $from || $end->toDateString() !== $to
            || $end->lt($start) || $start->diffInDays($end) > 366) {
            throw ValidationException::withMessages(['to' => ['Choose a valid period of no more than 367 days.']]);
        }

        $partner = IntegrationPartner::query()->where('code', 'hads')->first();
        if (! $partner) {
            $partner = IntegrationPartner::firstOrCreate(
                ['code' => 'hads'],
                [
                    'name' => config('integrations.partners.hads.name', 'Healthcare Analytics and Dashboard System (HADS)'),
                    'active' => false,
                    'mode' => 'mock',
                    'allowed_abilities' => config('integrations.partners.hads.abilities', []),
                ]
            );
        }

        $snapshot = $this->snapshot($dataset, $from, $to);
        $export = IntegrationExport::create([
            'integration_partner_id' => $partner->id,
            'export_ref' => (string) Str::uuid(),
            'dataset' => $dataset,
            'period_from' => $from,
            'period_to' => $to,
            'format' => strtolower($format),
            'status' => 'QUEUED',
            'row_count' => count($snapshot['rows']),
            'requested_by' => $requester?->id,
            'expires_at' => now()->addDays(max(1, (int) config('integrations.export_retention_days', 30))),
            'payload_snapshot' => $snapshot,
        ]);

        $disclosedFields = ['dataset', 'period_from', 'period_to', 'format', 'aggregate_count', 'aggregate_category'];
        if ($dataset === 'billing_totals') {
            $disclosedFields[] = 'aggregate_total';
        }
        if ($dataset === 'bed_occupancy') {
            $disclosedFields[] = 'snapshot_at';
        }
        $this->disclosures->recordAggregate(
            $partner,
            'hads_aggregate_operational_analytics',
            $disclosedFields,
            $export->export_ref
        );
        $this->audit->log('HADS_EXPORT_REQUESTED', 'integration_export', $export->id, 'SUCCESS', [
            'dataset' => $dataset,
            'period_from' => $from,
            'period_to' => $to,
            'format' => strtolower($format),
            'row_count' => $export->row_count,
        ], $requester?->id);

        try {
            if (! $partner->active) {
                throw new \RuntimeException('The HADS partner is not active.');
            }
            $this->outbox->enqueue('hads', 'analytics_export', (int) $export->id, [
                'export_ref' => $export->export_ref,
            ]);
        } catch (Throwable $exception) {
            $export->update(['status' => 'FAILED']);
            Log::warning('HADS export was recorded locally but could not be queued for delivery.', [
                'export_ref' => $export->export_ref,
                'exception' => $exception::class,
            ]);
            $this->audit->log('HADS_EXPORT_DELIVERY_UNAVAILABLE', 'integration_export', $export->id, 'FAILURE', [
                'reason' => 'partner_unavailable',
            ], $requester?->id);
        }

        return $export->fresh();
    }

    public function expireRetainedExports(): int
    {
        $expired = 0;
        IntegrationExport::query()
            ->where('status', '!=', 'EXPIRED')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($exports) use (&$expired): void {
                foreach ($exports as $export) {
                    $export->update(['status' => 'EXPIRED', 'payload_snapshot' => null]);
                    $this->audit->log('HADS_EXPORT_EXPIRED', 'integration_export', $export->id, 'SUCCESS', [
                        'dataset' => $export->dataset,
                    ], null);
                    $expired++;
                }
            });

        return $expired;
    }

    private function snapshot(string $dataset, string $from, string $to): array
    {
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->endOfDay();
        $rows = match ($dataset) {
            'appointments' => $this->groupedRows(
                DB::table('appointments')
                    ->selectRaw('DATE(starts_at) AS period, status, COUNT(*) AS aggregate_count')
                    ->whereBetween('starts_at', [$start, $end])
                    ->groupByRaw('DATE(starts_at), status')
                    ->orderBy('period')
                    ->get(),
                ['period', 'status', 'aggregate_count']
            ),
            'encounters' => $this->groupedRows(
                DB::table('encounters')
                    ->selectRaw('DATE(started_at) AS period, type, status, COUNT(*) AS aggregate_count')
                    ->whereBetween('started_at', [$start, $end])
                    ->groupByRaw('DATE(started_at), type, status')
                    ->orderBy('period')
                    ->get(),
                ['period', 'type', 'status', 'aggregate_count']
            ),
            'triage_levels' => $this->groupedRows(
                DB::table('triage_assessments')
                    ->selectRaw("COALESCE(final_level, 'Unknown') AS triage_level, CASE WHEN final_level IN ('Emergency', 'Urgent', 'LEVEL_1', 'LEVEL_2') THEN 'Severe' ELSE 'Not Severe' END AS severity, COUNT(*) AS aggregate_count")
                    ->whereBetween('triaged_at', [$start, $end])
                    ->groupByRaw("final_level, CASE WHEN final_level IN ('Emergency', 'Urgent', 'LEVEL_1', 'LEVEL_2') THEN 'Severe' ELSE 'Not Severe' END")
                    ->orderBy('triage_level')
                    ->get(),
                ['triage_level', 'severity', 'aggregate_count']
            ),
            'er_visits' => $this->groupedRows(
                DB::table('er_visits')
                    ->selectRaw('DATE(arrived_at) AS period, status, COUNT(*) AS aggregate_count')
                    ->whereBetween('arrived_at', [$start, $end])
                    ->groupByRaw('DATE(arrived_at), status')
                    ->orderBy('period')
                    ->get(),
                ['period', 'status', 'aggregate_count']
            ),
            'bed_occupancy' => $this->groupedRows(
                DB::table('beds')
                    ->selectRaw('status, COUNT(*) AS aggregate_count')
                    ->groupBy('status')
                    ->orderBy('status')
                    ->get(),
                ['status', 'aggregate_count'],
                ['snapshot_at' => now()->toIso8601String()]
            ),
            'billing_totals' => $this->groupedRows(
                DB::table('encounter_bills')
                    ->selectRaw('currency, status, SUM(total) AS aggregate_total, COUNT(*) AS bill_count')
                    ->whereBetween('generated_at', [$start, $end])
                    ->groupBy('currency', 'status')
                    ->orderBy('currency')
                    ->get(),
                ['currency', 'status', 'aggregate_total', 'bill_count']
            ),
            default => throw new \LogicException('Unapproved HADS dataset reached the snapshot builder.'),
        };
        $headers = match ($dataset) {
            'appointments' => ['Period', 'Status', 'Aggregate count'],
            'encounters' => ['Period', 'Encounter type', 'Status', 'Aggregate count'],
            'triage_levels' => ['Triage level', 'Severity', 'Aggregate count'],
            'er_visits' => ['Period', 'Visit status', 'Aggregate count'],
            'bed_occupancy' => ['Bed status', 'Aggregate count', 'Snapshot at'],
            'billing_totals' => ['Currency', 'Bill status', 'Aggregate total', 'Bill count'],
        };

        return [
            'headers' => $headers,
            'rows' => $rows,
        ];
    }

    private function groupedRows(iterable $records, array $columns, array $append = []): array
    {
        $rows = [];
        foreach ($records as $record) {
            $row = [];
            foreach ($columns as $column) {
                $row[$column] = $record->{$column};
            }
            $rows[] = [...array_values($row), ...array_values($append)];
        }

        return $rows;
    }
}
