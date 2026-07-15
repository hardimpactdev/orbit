<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * This one-shot migration resolves historical ownership before adding canonical constraints.
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:too-many-methods
 */
return new class extends Migration {
    private const string APP_OWNER = 'App\\Models\\App';

    private const string WORKSPACE_OWNER = 'App\\Models\\Workspace';

    public function up(): void
    {
        $processAssignments = $this->processAssignments();
        $eventAssignments = $this->eventAssignments($processAssignments);

        Schema::table('processes', static function (Blueprint $table): void {
            $table
                ->foreignId('app_instance_id')
                ->nullable()
                ->after('owner_id')
                ->constrained('app_instances')
                ->cascadeOnDelete();
            $table->index(['app_instance_id', 'sort_order']);
            $table->dropUnique(['owner_type', 'owner_id', 'name']);
            $table->unique(['owner_type', 'owner_id', 'app_instance_id', 'name']);
        });

        if (DB::getDriverName() === 'sqlite') {
            DB::statement(
                'create unique index processes_owner_without_instance_name_unique '
                .'on processes (owner_type, owner_id, name) where app_instance_id is null',
            );
        }

        Schema::table('process_events', static function (Blueprint $table): void {
            $table
                ->foreignId('app_instance_id')
                ->nullable()
                ->after('app_id')
                ->constrained('app_instances')
                ->nullOnDelete();
            $table->index(['app_instance_id', 'recorded_at']);
        });

        DB::transaction(static function () use ($processAssignments, $eventAssignments): void {
            foreach ($processAssignments as $processId => $appInstanceId) {
                DB::table('processes')
                    ->where('id', $processId)
                    ->update(['app_instance_id' => $appInstanceId]);
            }

            foreach ($eventAssignments as $eventId => $appInstanceId) {
                DB::table('process_events')
                    ->where('id', $eventId)
                    ->update(['app_instance_id' => $appInstanceId]);
            }
        });
    }

    /**
     * @return array<int, int>
     */
    private function processAssignments(): array
    {
        $assignments = [];
        /** @var list<string> $ambiguous */
        $ambiguous = [];

        DB::table('processes')
            ->whereIn('owner_type', [self::APP_OWNER, self::WORKSPACE_OWNER])
            ->orderBy('id')
            ->get()
            ->each(function (object $process) use (&$assignments, &$ambiguous): void {
                $processId = $this->rowInteger($process, 'id');
                $ownerType = $this->rowString($process, 'owner_type');
                $ownerId = $this->rowInteger($process, 'owner_id');
                $instanceId = $ownerType === self::WORKSPACE_OWNER
                    ? $this->workspaceInstanceId($ownerId)
                    : $this->provenAppInstanceId($ownerId);

                if ($instanceId === null) {
                    $ambiguous[] = sprintf(
                        'process_id=%d owner=%s#%d',
                        $processId,
                        $ownerType,
                        $ownerId,
                    );

                    return;
                }

                $assignments[$processId] = $instanceId;
            });

        if ($ambiguous !== []) {
            throw new RuntimeException(
                'Canonical process app-instance ownership requires manual assignment before migration: '
                .implode('; ', $ambiguous)
                .'. Assign every listed process definition to one concrete app instance, then rerun migrations.',
            );
        }

        return $assignments;
    }

    /**
     * @param  array<int, int>  $processAssignments
     * @return array<int, int>
     */
    private function eventAssignments(array $processAssignments): array
    {
        $assignments = [];
        /** @var list<string> $ambiguous */
        $ambiguous = [];

        DB::table('process_events')
            ->where(static function (Builder $query): void {
                $query
                    ->whereNotNull('process_id')
                    ->orWhereNotNull('workspace_id')
                    ->orWhereNotNull('app_id');
            })
            ->orderBy('id')
            ->get()
            /** @mago-expect lint:cyclomatic-complexity */
            ->each(function (object $event) use ($processAssignments, &$assignments, &$ambiguous): void {
                $eventId = $this->rowInteger($event, 'id');
                $processId = $this->rowNullableInteger($event, 'process_id');
                $workspaceId = $this->rowNullableInteger($event, 'workspace_id');
                $appId = $this->rowNullableInteger($event, 'app_id');
                $processRequiresAppInstance = $processId !== null && $this->processRequiresAppInstance($processId);
                $requiresAppInstance = $workspaceId !== null || $appId !== null || $processRequiresAppInstance;

                if (! $requiresAppInstance) {
                    return;
                }

                $processCandidate = $processRequiresAppInstance ? $processAssignments[$processId] ?? null : null;
                $workspaceCandidate = $workspaceId !== null ? $this->workspaceInstanceId($workspaceId) : null;
                $appCandidate =
                    $appId !== null && $processCandidate === null && $workspaceCandidate === null
                        ? $this->provenAppInstanceId($appId)
                        : null;
                $hasUnresolvedRequiredCandidate =
                    $processRequiresAppInstance && $processCandidate === null
                    || $workspaceId !== null && $workspaceCandidate === null;
                $candidates = array_values(array_filter(
                    [
                        $processCandidate,
                        $workspaceCandidate,
                        $appCandidate,
                    ],
                    static fn (?int $instanceId): bool => $instanceId !== null,
                ));
                $candidateIds = array_values(array_unique($candidates));
                $candidatesMatchApp = $appId === null
                || collect($candidateIds)->every(
                    static fn (int $instanceId): bool => DB::table('app_instances')
                        ->where('id', $instanceId)
                        ->where('app_id', $appId)
                        ->exists(),
                );

                if ($hasUnresolvedRequiredCandidate || count($candidateIds) !== 1 || ! $candidatesMatchApp) {
                    $ambiguous[] = "process_event_id={$eventId}";

                    return;
                }

                $assignments[$eventId] = $candidateIds[0];
            });

        if ($ambiguous !== []) {
            throw new RuntimeException(
                'Canonical process-event app-instance ownership requires manual assignment before migration: '
                .implode('; ', $ambiguous)
                .'. Assign every listed process event to one concrete app instance, then rerun migrations.',
            );
        }

        return $assignments;
    }

    private function processRequiresAppInstance(int $processId): bool
    {
        $ownerType = DB::table('processes')->where('id', $processId)->value('owner_type');

        return in_array($ownerType, [self::APP_OWNER, self::WORKSPACE_OWNER], true);
    }

    private function workspaceInstanceId(int $workspaceId): ?int
    {
        $workspace = DB::table('workspaces')->where('id', $workspaceId)->first();

        if (! is_object($workspace)) {
            return null;
        }

        $instanceId = $this->rowNullableInteger($workspace, 'app_instance_id');
        $appId = $this->rowInteger($workspace, 'app_id');

        if ($instanceId === null) {
            return null;
        }

        return DB::table('app_instances')
            ->where('id', $instanceId)
            ->where('app_id', $appId)
            ->exists()
            ? $instanceId
            : null;
    }

    private function provenAppInstanceId(int $appId): ?int
    {
        $instanceIds = DB::table('app_instances')
            ->where('app_id', $appId)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        if (count($instanceIds) === 1) {
            return $instanceIds[0];
        }

        $app = DB::table('apps')->where('id', $appId)->first();

        if (! is_object($app)) {
            return null;
        }

        $nodeId = $this->rowInteger($app, 'node_id');
        $path = $this->rowString($app, 'path');
        $matching = DB::table('app_instances')
            ->where('app_id', $appId)
            ->where('driver', 'orbit')
            ->orderBy('id')
            ->get()
            ->filter(function (object $instance) use ($nodeId, $path): bool {
                $config = $this->decodeJson($this->rowValue($instance, 'driver_config'));
                $data = is_array($config['data'] ?? null) ? $config['data'] : [];

                return (
                    ($config['type'] ?? null) === 'orbit_app_instance_driver_config'
                    && (int) ($data['node_id'] ?? 0) === $nodeId
                    && ($data['path'] ?? null) === $path
                );
            })
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        return count($matching) === 1 ? $matching[0] : null;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decodeJson(mixed $value): array
    {
        if (! is_string($value)) {
            return [];
        }

        $decoded = json_decode($value, associative: true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    private function rowInteger(object $row, string $field): int
    {
        $value = $this->rowValue($row, $field);

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw new RuntimeException("Canonical process ownership row has an invalid {$field} integer.");
    }

    private function rowNullableInteger(object $row, string $field): ?int
    {
        return $this->rowValue($row, $field) === null ? null : $this->rowInteger($row, $field);
    }

    private function rowString(object $row, string $field): string
    {
        $value = $this->rowValue($row, $field);

        if (! is_string($value)) {
            throw new RuntimeException("Canonical process ownership row has an invalid {$field} string.");
        }

        return $value;
    }

    private function rowValue(object $row, string $field): mixed
    {
        return get_object_vars($row)[$field] ?? null;
    }
};
