<?php

namespace App\Console\Commands;

use App\Models\DemoRecord;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class PurgeDemo extends Command
{
    protected $signature = 'app:purge-demo {--force : Allow demo purging in production when DEMO_MODE=true}';

    protected $description = 'Remove marked demo records when no unmarked records depend on them';

    private const DEPENDENCY_TABLES = [
        'role_user',
        'permission_role',
        'provider_specialties',
        'department_user',
    ];

    private const DELETE_PRIORITY = [
        'App\\Models\\EncounterNote' => 10,
        'App\\Models\\Vital' => 11,
        'App\\Models\\Prescription' => 12,
        'App\\Models\\EncounterBill' => 13,
        'App\\Models\\AppointmentStatusHistory' => 14,
        'App\\Models\\TelehealthSession' => 15,
        'App\\Models\\Encounter' => 20,
        'App\\Models\\Appointment' => 30,
        'App\\Models\\AppointmentSlot' => 31,
        'App\\Models\\ProviderSchedule' => 32,
        'App\\Models\\Bed' => 40,
        'App\\Models\\Room' => 41,
        'App\\Models\\Ward' => 42,
        'App\\Models\\Patient' => 50,
        'App\\Models\\Provider' => 60,
        'App\\Models\\Specialty' => 65,
        'App\\Models\\Department' => 70,
        'App\\Models\\User' => 80,
    ];

    public function handle(): int
    {
        if (app()->environment('production')
            && (! $this->option('force')
                || filter_var(Env::get('DEMO_MODE'), FILTER_VALIDATE_BOOLEAN) !== true)) {
            $this->error('Production demo purging requires both --force and DEMO_MODE=true.');

            return self::FAILURE;
        }

        $markers = DemoRecord::query()->get();
        if ($markers->isEmpty()) {
            $this->info('No marked demo records were found.');

            return self::SUCCESS;
        }

        try {
            $records = $this->resolveRecords($markers);
            $this->assertNoSuperAdminAccounts($records);
            $blockers = $this->findUnmarkedDependents($records);
            if ($blockers !== []) {
                $this->error('Demo purge refused; unmarked records still reference demo data:');
                $this->table(['Table', 'Blocking rows'], array_map(
                    fn (string $table, int $count): array => [$table, $count],
                    array_keys($blockers),
                    array_values($blockers)
                ));
                $this->line('Those rows were not deleted. Remove or explicitly mark their dependencies before retrying.');

                return self::FAILURE;
            }
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if (! $this->input->isInteractive()
            || ! $this->confirm('This permanently deletes all marked demo records. Continue?', false)) {
            $this->warn('No demo records were deleted; explicit interactive confirmation is required.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($records): void {
            usort($records, fn (array $left, array $right): int => (self::DELETE_PRIORITY[$left['marker']->model_type] ?? 50)
                <=> (self::DELETE_PRIORITY[$right['marker']->model_type] ?? 50)
            );

            foreach ($records as $record) {
                $model = $record['model'];
                if ($model) {
                    if (in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
                        $model::withTrashed()
                            ->whereKey($record['marker']->model_id)
                            ->forceDelete();
                    } else {
                        $model::query()
                            ->whereKey($record['marker']->model_id)
                            ->delete();
                    }
                }
                $record['marker']->delete();
            }
        });

        $this->info('Marked demo records were purged. Audit logs were not deleted.');

        return self::SUCCESS;
    }

    private function resolveRecords($markers): array
    {
        $records = [];

        foreach ($markers as $marker) {
            $class = $marker->model_type;
            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                throw new RuntimeException("Cannot safely resolve marked model type {$class}; no records were deleted.");
            }
            if (! str_starts_with($class, 'App\\Models\\')) {
                throw new RuntimeException("Cannot safely resolve marked model type {$class}; no records were deleted.");
            }

            $model = new $class;
            if ($model->getMorphClass() !== $class) {
                throw new RuntimeException("Cannot safely resolve marked model type {$class}; no records were deleted.");
            }

            $records[] = [
                'marker' => $marker,
                'model' => $model,
            ];
        }

        return $records;
    }

    private function findUnmarkedDependents(array $records): array
    {
        $markedIdsByTable = [];
        foreach ($records as $record) {
            $model = $record['model'];
            $markedIdsByTable[$model->getTable()][] = (int) $record['marker']->model_id;
        }

        $blockers = [];
        foreach (Schema::getTables() as $tableInfo) {
            $table = $tableInfo['name'];
            if ($table === 'demo_records'
                || $table === 'audit_logs'
                || in_array($table, self::DEPENDENCY_TABLES, true)
                || ! Schema::hasColumn($table, 'id')) {
                continue;
            }

            $modelClass = 'App\\Models\\'.Str::studly(Str::singular($table));
            $modelType = class_exists($modelClass) && is_subclass_of($modelClass, Model::class)
                ? (new $modelClass)->getMorphClass()
                : null;
            $foreignKeys = Schema::getForeignKeys($table);

            foreach ($foreignKeys as $foreignKey) {
                $targetTable = $foreignKey['foreign_table'] ?? null;
                $targetColumn = $foreignKey['foreign_columns'][0] ?? null;
                $localColumn = $foreignKey['columns'][0] ?? null;
                if (! $targetTable || ! $targetColumn || ! $localColumn
                    || $targetColumn !== 'id'
                    || ! isset($markedIdsByTable[$targetTable])) {
                    continue;
                }

                $rows = DB::table($table)
                    ->whereIn($localColumn, $markedIdsByTable[$targetTable])
                    ->get(['id']);
                if ($rows->isEmpty()) {
                    continue;
                }

                $rowIds = $rows->pluck('id')->map(fn ($id): int => (int) $id)->all();
                $markedChildIds = $modelType
                    ? DemoRecord::query()
                        ->where('model_type', $modelType)
                        ->whereIn('model_id', $rowIds)
                        ->pluck('model_id')
                        ->map(fn ($id): int => (int) $id)
                        ->all()
                    : [];
                $unmarkedCount = count(array_diff($rowIds, $markedChildIds));
                if ($unmarkedCount > 0) {
                    $blockers[$table] = ($blockers[$table] ?? 0) + $unmarkedCount;
                }
            }
        }

        $demoDepartmentIds = $markedIdsByTable['departments'] ?? [];
        $demoUserIds = $markedIdsByTable['users'] ?? [];
        if (Schema::hasTable('department_user') && $demoDepartmentIds !== [] && $demoUserIds !== []) {
            $externalDepartmentAssignments = DB::table('department_user')
                ->whereIn('department_id', $demoDepartmentIds)
                ->whereNotIn('user_id', $demoUserIds)
                ->count();
            if ($externalDepartmentAssignments > 0) {
                $blockers['department_user'] = $externalDepartmentAssignments;
            }
        }

        ksort($blockers);

        return $blockers;
    }

    private function assertNoSuperAdminAccounts(array $records): void
    {
        foreach ($records as $record) {
            if ($record['model'] instanceof User
                && User::query()->find($record['marker']->model_id)?->hasRole('super-admin')) {
                throw new RuntimeException('A marked demo account has a super-admin role; purge refused.');
            }
        }
    }
}
