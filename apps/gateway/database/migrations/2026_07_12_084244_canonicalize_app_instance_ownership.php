<?php

declare(strict_types=1);

use App\Data\Apps\AppInstanceRuntimeRequirementsData;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * This one-shot canonicalization intentionally coordinates every historical ownership surface.
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 * @mago-expect lint:too-many-methods
 */
return new class extends Migration {
    public function up(): void
    {
        $this->assertUnambiguousOwnership();

        DB::transaction(function (): void {
            $this->backfillWorkspaces();
            $this->backfillWorkspaceSteps();
            $this->backfillRuntimeMounts();
        });

        Schema::dropIfExists('app_runtime_mounts');

        $this->makeInstanceOwnershipRequired('workspaces');
        $this->makeInstanceOwnershipRequired('workspace_steps');
        $this->rebuildCanonicalDatabaseTargets();
    }

    private function assertUnambiguousOwnership(): void
    {
        $ambiguous = DB::table('apps')
            ->select(['apps.id', 'apps.name'])
            ->selectRaw('(select count(*) from app_instances where app_instances.app_id = apps.id) as instance_count')
            ->selectRaw(
                '(select count(*) from workspaces where workspaces.app_id = apps.id and workspaces.app_instance_id is null) as workspace_count',
            )
            ->selectRaw(
                '(select count(*) from app_runtime_mounts where app_runtime_mounts.app_id = apps.id) as runtime_mount_count',
            )
            ->selectRaw(
                '(select count(*) from database_connection_targets where database_connection_targets.app_id = apps.id) as database_target_count',
            )
            ->get()
            ->filter(function (object $app): bool {
                $hasAmbiguousRows =
                    $this->rowInteger($app, 'workspace_count') > 0
                    || $this->rowInteger($app, 'runtime_mount_count') > 0
                    || $this->rowInteger($app, 'database_target_count') > 0;

                if ($this->rowInteger($app, 'instance_count') <= 1 || ! $hasAmbiguousRows) {
                    return false;
                }

                return count($this->matchingHistoricalOrbitInstanceIds($this->rowInteger($app, 'id'))) !== 1;
            });

        if ($ambiguous->isNotEmpty()) {
            $details = $ambiguous
                ->map(fn (object $app): string => sprintf(
                    '%s#%d (instances=%d, workspaces=%d, runtime_mounts=%d, database_targets=%d)',
                    $this->rowString($app, 'name'),
                    $this->rowInteger($app, 'id'),
                    $this->rowInteger($app, 'instance_count'),
                    $this->rowInteger($app, 'workspace_count'),
                    $this->rowInteger($app, 'runtime_mount_count'),
                    $this->rowInteger($app, 'database_target_count'),
                ))
                ->implode('; ');

            throw new RuntimeException(
                "Canonical app-instance ownership requires manual assignment before migration: {$details}. "
                .'Assign every listed workspace, runtime mount, and database target to a concrete app instance, then rerun migrations.',
            );
        }

        $this->assertDatabaseTargetConflictsAreAbsent();
    }

    private function assertDatabaseTargetConflictsAreAbsent(): void
    {
        $conflicts = collect();

        DB::table('database_connection_targets')
            ->whereNotNull('app_id')
            ->orderBy('id')
            ->get()
            ->each(function (object $appTarget) use ($conflicts): void {
                $appId = $this->rowInteger($appTarget, 'app_id');
                $instanceId = $this->existingHistoricalOwnerInstanceId($appId);

                if ($instanceId === null) {
                    return;
                }

                $instanceTarget = DB::table('app_instance_database_connection_targets')
                    ->where('app_instance_id', $instanceId)
                    ->where('env_prefix', $this->rowString($appTarget, 'env_prefix'))
                    ->first();

                if (
                    ! is_object($instanceTarget)
                    || $this->rowInteger($instanceTarget, 'database_connection_id') === $this->rowInteger(
                        $appTarget,
                        'database_connection_id',
                    )
                ) {
                    return;
                }

                $conflicts->push((object) [
                    'app_id' => $appId,
                    'env_prefix' => $this->rowString($appTarget, 'env_prefix'),
                    'app_connection_id' => $this->rowInteger($appTarget, 'database_connection_id'),
                    'instance_connection_id' => $this->rowInteger(
                        $instanceTarget,
                        'database_connection_id',
                    ),
                ]);
            });

        if ($conflicts->isEmpty()) {
            return;
        }

        $details = $conflicts
            ->map(fn (object $conflict): string => sprintf(
                'app_id=%d prefix=%s app_connection_id=%d instance_connection_id=%d',
                $this->rowInteger($conflict, 'app_id'),
                $this->rowString($conflict, 'env_prefix'),
                $this->rowInteger($conflict, 'app_connection_id'),
                $this->rowInteger($conflict, 'instance_connection_id'),
            ))
            ->implode('; ');

        throw new RuntimeException(
            "Canonical app-instance database target migration found conflicting assignments: {$details}. "
            .'Resolve each env prefix to one database connection, then rerun migrations.',
        );
    }

    private function backfillWorkspaces(): void
    {
        DB::table('workspaces')
            ->whereNull('app_instance_id')
            ->orderBy('id')
            ->get()
            ->each(function (object $workspace): void {
                $workspaceId = $this->rowInteger($workspace, 'id');
                $appId = $this->rowInteger($workspace, 'app_id');

                DB::table('workspaces')
                    ->where('id', $workspaceId)
                    ->update(['app_instance_id' => $this->historicalOwnerInstanceId($appId)]);
            });
    }

    private function backfillWorkspaceSteps(): void
    {
        DB::table('workspace_steps')
            ->whereNull('app_instance_id')
            ->orderBy('app_id')
            ->orderBy('phase')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (object $step): string => sprintf(
                '%d:%s',
                $this->rowInteger($step, 'app_id'),
                $this->rowString($step, 'phase'),
            ))
            ->each(function (Collection $steps): void {
                $stepRows = array_values(
                    $steps
                        ->map(static function (mixed $step): object {
                            if (! is_object($step)) {
                                throw new RuntimeException(
                                    'Canonical workspace step migration received an invalid row.',
                                );
                            }

                            return $step;
                        })
                        ->all(),
                );
                $first = $stepRows[0] ?? throw new RuntimeException(
                    'Canonical workspace step migration received an empty group.',
                );

                $appId = $this->rowInteger($first, 'app_id');
                $phase = $this->rowString($first, 'phase');

                foreach ($this->instanceIds($appId) as $instanceId) {
                    $hasInstanceSteps = DB::table('workspace_steps')
                        ->where('app_instance_id', $instanceId)
                        ->where('phase', $phase)
                        ->exists();

                    if ($hasInstanceSteps) {
                        continue;
                    }

                    foreach ($stepRows as $step) {
                        DB::table('workspace_steps')->insert([
                            'app_id' => $appId,
                            'app_instance_id' => $instanceId,
                            'phase' => $phase,
                            'sort_order' => $this->rowInteger($step, 'sort_order'),
                            'command' => $this->rowString($step, 'command'),
                            'timeout_seconds' => $this->rowInteger($step, 'timeout_seconds'),
                            'created_at' => $this->rowValue($step, 'created_at'),
                            'updated_at' => $this->rowValue($step, 'updated_at'),
                        ]);
                    }
                }

                DB::table('workspace_steps')
                    ->whereIn('id', $steps->pluck('id')->all())
                    ->delete();
            });
    }

    private function backfillRuntimeMounts(): void
    {
        DB::table('app_runtime_mounts')
            ->orderBy('id')
            ->get()
            ->each(function (object $mount): void {
                $appId = $this->rowInteger($mount, 'app_id');
                $instanceId = $this->historicalOwnerInstanceId($appId);
                $target = $this->rowString($mount, 'target');
                $existing = DB::table('app_instance_runtime_mounts')
                    ->where('app_instance_id', $instanceId)
                    ->where('target', $target)
                    ->first();

                if (is_object($existing)) {
                    $sameIntent =
                        $this->rowString($existing, 'source') === $this->rowString($mount, 'source')
                        && $this->rowBoolean($existing, 'read_only') === $this->rowBoolean($mount, 'read_only');

                    if (! $sameIntent) {
                        throw new RuntimeException(
                            "Canonical runtime mount migration found conflicting target '{$target}' "
                            ."for app_id={$appId}, app_instance_id={$instanceId}. Resolve it and rerun migrations.",
                        );
                    }

                    return;
                }

                DB::table('app_instance_runtime_mounts')->insert([
                    'app_instance_id' => $instanceId,
                    'source' => $this->rowString($mount, 'source'),
                    'target' => $target,
                    'read_only' => $this->rowBoolean($mount, 'read_only'),
                    'created_at' => $this->rowValue($mount, 'created_at'),
                    'updated_at' => $this->rowValue($mount, 'updated_at'),
                ]);
            });
    }

    private function historicalOwnerInstanceId(int $appId): int
    {
        $instanceIds = $this->instanceIds($appId);

        if (count($instanceIds) === 1) {
            return $instanceIds[0];
        }

        $matchingInstanceIds = $this->matchingHistoricalOrbitInstanceIds($appId);

        if (count($matchingInstanceIds) === 1) {
            return $matchingInstanceIds[0];
        }

        throw new RuntimeException(
            "Canonical app-instance ownership could not resolve the historical owner for app_id={$appId}.",
        );
    }

    private function existingHistoricalOwnerInstanceId(int $appId): ?int
    {
        $instanceIds = array_values(
            DB::table('app_instances')
                ->where('app_id', $appId)
                ->orderBy('id')
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all(),
        );

        if (count($instanceIds) <= 1) {
            return $instanceIds[0] ?? null;
        }

        $matchingInstanceIds = $this->matchingHistoricalOrbitInstanceIds($appId);

        return count($matchingInstanceIds) === 1 ? $matchingInstanceIds[0] : null;
    }

    /** @return list<int> */
    private function matchingHistoricalOrbitInstanceIds(int $appId): array
    {
        $app = DB::table('apps')->where('id', $appId)->first();

        if (! is_object($app)) {
            throw new RuntimeException("Cannot resolve historical ownership for missing app_id={$appId}.");
        }

        $nodeId = $this->rowInteger($app, 'node_id');
        $path = $this->rowString($app, 'path');

        return array_values(
            DB::table('app_instances')
                ->where('app_id', $appId)
                ->where('driver', 'orbit')
                ->orderBy('id')
                ->get()
                ->filter(function (object $instance) use ($nodeId, $path): bool {
                    $config = $this->decodeDriverConfig($this->rowValue($instance, 'driver_config'));
                    $data = is_array($config['data'] ?? null) ? $config['data'] : [];

                    return (
                        ($config['type'] ?? null) === 'orbit_app_instance_driver_config'
                        && ($data['node_id'] ?? null) === $nodeId
                        && ($data['path'] ?? null) === $path
                    );
                })
                ->map(fn (object $instance): int => $this->rowInteger($instance, 'id'))
                ->all(),
        );
    }

    /** @return array<string, mixed> */
    private function decodeDriverConfig(mixed $value): array
    {
        if (! is_string($value)) {
            return [];
        }

        /** @var mixed $decoded */
        $decoded = json_decode($value, associative: true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            return [];
        }

        foreach (array_keys($decoded) as $key) {
            if (! is_string($key)) {
                return [];
            }
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @return list<int>
     */
    private function instanceIds(int $appId): array
    {
        $ids = array_values(
            DB::table('app_instances')
                ->where('app_id', $appId)
                ->orderBy('id')
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all(),
        );

        if ($ids !== []) {
            return $ids;
        }

        return [$this->createCanonicalInstance($appId)];
    }

    private function createCanonicalInstance(int $appId): int
    {
        $app = DB::table('apps')->where('id', $appId)->first();

        if (! is_object($app)) {
            throw new RuntimeException("Cannot create a canonical app instance for missing app_id={$appId}.");
        }

        $environment = $this->rowNullableString($app, 'environment');
        $name =
            $environment !== null && trim($environment) !== ''
                ? trim($environment)
                : 'default';
        $nodeId = $this->rowInteger($app, 'node_id');
        $node = DB::table('nodes')->where('id', $nodeId)->first();
        $nodeName = is_object($node) ? $this->rowString($node, 'name') : null;

        return (int) DB::table('app_instances')->insertGetId([
            'app_id' => $appId,
            'name' => $name,
            'driver' => 'orbit',
            'driver_config' => json_encode([
                'type' => 'orbit_app_instance_driver_config',
                'data' => [
                    'node_id' => $nodeId,
                    'node' => $nodeName,
                    'path' => $this->rowString($app, 'path'),
                    'document_root' => $this->rowNullableString($app, 'document_root'),
                    'domain' => $this->rowNullableString($app, 'domain'),
                ],
            ], JSON_THROW_ON_ERROR),
            'runtime_requirements' => json_encode(
                new AppInstanceRuntimeRequirementsData()->toArray(),
                JSON_THROW_ON_ERROR,
            ),
            'latest_deployment_status' => $this->rowNullableString($app, 'latest_deployment_status'),
            'latest_deployment_run_id' => $this->rowNullableInteger($app, 'latest_deployment_run_id'),
            'created_at' => $this->rowValue($app, 'created_at'),
            'updated_at' => $this->rowValue($app, 'updated_at'),
        ]);
    }

    private function makeInstanceOwnershipRequired(string $tableName): void
    {
        Schema::table($tableName, static function (Blueprint $table): void {
            $table->dropForeign(['app_instance_id']);
        });

        Schema::table($tableName, static function (Blueprint $table): void {
            $table->unsignedBigInteger('app_instance_id')->nullable(false)->change();
        });

        Schema::table($tableName, static function (Blueprint $table): void {
            $table
                ->foreign('app_instance_id')
                ->references('id')
                ->on('app_instances')
                ->cascadeOnDelete();
        });
    }

    private function rebuildCanonicalDatabaseTargets(): void
    {
        Schema::table('database_connection_targets', static function (Blueprint $table): void {
            $table->dropUnique(['app_id', 'env_prefix']);
            $table->dropUnique(['workspace_id', 'env_prefix']);
        });

        Schema::rename('database_connection_targets', 'database_connection_targets_legacy');

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::statement(<<<'SQL'
                    CREATE TABLE database_connection_targets (
                        id integer primary key autoincrement not null,
                        database_connection_id integer not null,
                        app_instance_id integer,
                        workspace_id integer,
                        env_prefix varchar not null,
                        created_at datetime,
                        updated_at datetime,
                        constraint database_connection_targets_database_connection_id_foreign
                            foreign key (database_connection_id) references database_connections (id) on delete cascade,
                        constraint database_connection_targets_app_instance_id_foreign
                            foreign key (app_instance_id) references app_instances (id) on delete cascade,
                        constraint database_connection_targets_workspace_id_foreign
                            foreign key (workspace_id) references workspaces (id) on delete cascade,
                        constraint database_connection_targets_owner_check
                            check ((app_instance_id is null) <> (workspace_id is null))
                    )
                SQL);
        } else {
            Schema::create('database_connection_targets', static function (Blueprint $table): void {
                $table->id();
                $table->foreignId('database_connection_id')->constrained('database_connections')->cascadeOnDelete();
                $table->foreignId('app_instance_id')->nullable()->constrained('app_instances')->cascadeOnDelete();
                $table->foreignId('workspace_id')->nullable()->constrained('workspaces')->cascadeOnDelete();
                $table->string('env_prefix');
                $table->timestamps();
            });

            DB::statement(
                'alter table database_connection_targets add constraint database_connection_targets_owner_check '
                .'check ((app_instance_id is null) <> (workspace_id is null))',
            );
        }

        Schema::table('database_connection_targets', static function (Blueprint $table): void {
            $table->unique(['app_instance_id', 'env_prefix']);
            $table->unique(['workspace_id', 'env_prefix']);
        });

        DB::table('database_connection_targets_legacy')
            ->whereNotNull('workspace_id')
            ->orderBy('id')
            ->get()
            ->each(function (object $target): void {
                DB::table('database_connection_targets')->insert([
                    'database_connection_id' => $this->rowInteger($target, 'database_connection_id'),
                    'app_instance_id' => null,
                    'workspace_id' => $this->rowInteger($target, 'workspace_id'),
                    'env_prefix' => $this->rowString($target, 'env_prefix'),
                    'created_at' => $this->rowValue($target, 'created_at'),
                    'updated_at' => $this->rowValue($target, 'updated_at'),
                ]);
            });

        DB::table('database_connection_targets_legacy')
            ->whereNotNull('app_id')
            ->orderBy('id')
            ->get()
            ->each(function (object $target): void {
                DB::table('database_connection_targets')->updateOrInsert(
                    [
                        'app_instance_id' => $this->historicalOwnerInstanceId(
                            $this->rowInteger($target, 'app_id'),
                        ),
                        'env_prefix' => $this->rowString($target, 'env_prefix'),
                    ],
                    [
                        'database_connection_id' => $this->rowInteger($target, 'database_connection_id'),
                        'workspace_id' => null,
                        'created_at' => $this->rowValue($target, 'created_at'),
                        'updated_at' => $this->rowValue($target, 'updated_at'),
                    ],
                );
            });

        DB::table('app_instance_database_connection_targets')
            ->orderBy('id')
            ->get()
            ->each(function (object $target): void {
                DB::table('database_connection_targets')->updateOrInsert(
                    [
                        'app_instance_id' => $this->rowInteger($target, 'app_instance_id'),
                        'env_prefix' => $this->rowString($target, 'env_prefix'),
                    ],
                    [
                        'database_connection_id' => $this->rowInteger($target, 'database_connection_id'),
                        'workspace_id' => null,
                        'created_at' => $this->rowValue($target, 'created_at'),
                        'updated_at' => $this->rowValue($target, 'updated_at'),
                    ],
                );
            });

        Schema::drop('database_connection_targets_legacy');
        Schema::drop('app_instance_database_connection_targets');
    }

    private function rowInteger(object $row, string $field): int
    {
        $values = get_object_vars($row);

        if (is_int($values[$field] ?? null)) {
            return $values[$field];
        }

        if (is_string($values[$field] ?? null) && ctype_digit($values[$field])) {
            return (int) $values[$field];
        }

        throw new RuntimeException("Canonical ownership row has an invalid {$field} integer.");
    }

    private function rowNullableInteger(object $row, string $field): ?int
    {
        $values = get_object_vars($row);

        if (($values[$field] ?? null) === null) {
            return null;
        }

        return $this->rowInteger($row, $field);
    }

    private function rowString(object $row, string $field): string
    {
        $values = get_object_vars($row);

        if (! is_string($values[$field] ?? null)) {
            throw new RuntimeException("Canonical ownership row has an invalid {$field} string.");
        }

        return $values[$field];
    }

    private function rowNullableString(object $row, string $field): ?string
    {
        $values = get_object_vars($row);

        if (($values[$field] ?? null) === null) {
            return null;
        }

        return $this->rowString($row, $field);
    }

    private function rowBoolean(object $row, string $field): bool
    {
        $values = get_object_vars($row);

        return match ($values[$field] ?? null) {
            true, 1 => true,
            false, 0 => false,
            default => throw new RuntimeException("Canonical ownership row has an invalid {$field} boolean."),
        };
    }

    private function rowValue(object $row, string $field): mixed
    {
        return get_object_vars($row)[$field] ?? null;
    }
};
