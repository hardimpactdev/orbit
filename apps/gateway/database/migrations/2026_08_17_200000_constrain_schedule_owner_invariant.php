<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Closed schedule-owner invariant: a schedule is owned by exactly one of
 * Orbit, Node, or Instance. Legacy `app` scope is Instance-owned via the
 * existing instance authority. App is derived from that Instance and is no
 * longer stored as an authoritative owner FK.
 *
 * Ambiguous live rows (unknown scope, two live owner FKs, instance-owned
 * without a resolvable instance_id) fail closed before mutation.
 *
 * Deleted owners: orphaned schedules are deleted, schedule_locks for those
 * keys are dropped, and schedule_runs keep their historical schedule_key
 * plus a stored target_name label.
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 * @mago-expect lint:too-many-methods
 */
return new class extends Migration {
    public function up(): void
    {
        $this->assertSchedulesRebuildIsNotPartial();

        if (! Schema::hasColumn('schedules', 'app_id')) {
            $this->ensureHistoricalRunLabels();

            return;
        }

        $plan = $this->plan();

        $this->ensureHistoricalRunLabels();
        $this->dropLeftoverSchedulesNext();

        Schema::disableForeignKeyConstraints();

        try {
            DB::transaction(function () use ($plan): void {
                foreach ($plan['updates'] as $scheduleId => $assignment) {
                    if ($assignment['previous_schedule_key'] !== $assignment['schedule_key']) {
                        DB::table('schedule_runs')
                            ->where('schedule_key', $assignment['previous_schedule_key'])
                            ->update([
                                'schedule_key' => $assignment['schedule_key'],
                                'target_name' => $assignment['target_name'],
                            ]);
                        DB::table('schedule_locks')
                            ->where('schedule_key', $assignment['previous_schedule_key'])
                            ->update(['schedule_key' => $assignment['schedule_key']]);
                    } else {
                        DB::table('schedule_runs')
                            ->where('schedule_key', $assignment['schedule_key'])
                            ->whereNull('target_name')
                            ->update(['target_name' => $assignment['target_name']]);
                    }

                    DB::table('schedules')
                        ->where('id', $scheduleId)
                        ->update([
                            'scope' => $assignment['scope'],
                            'instance_id' => $assignment['instance_id'],
                            'node_id' => $assignment['node_id'],
                            'target_name' => $assignment['target_name'],
                            'schedule_key' => $assignment['schedule_key'],
                        ]);
                }

                foreach ($plan['orphans'] as $orphan) {
                    DB::table('schedule_runs')
                        ->where('schedule_key', $orphan['schedule_key'])
                        ->update(['target_name' => $orphan['target_name']]);
                    DB::table('schedule_locks')
                        ->where('schedule_key', $orphan['schedule_key'])
                        ->delete();
                    DB::table('schedules')->where('id', $orphan['id'])->delete();
                }

                $this->rebuildSchedulesWithoutAuthoritativeApp();
            });
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    /**
     * @return array{
     *     updates: array<int, array{scope: string, instance_id: int|null, node_id: int|null, target_name: string, previous_schedule_key: string, schedule_key: string}>,
     *     orphans: list<array{id: int, schedule_key: string, target_name: string}>
     * }
     */
    private function plan(): array
    {
        /** @var array<int, array{scope: string, instance_id: int|null, node_id: int|null, target_name: string, previous_schedule_key: string, schedule_key: string}> $updates */
        $updates = [];
        /** @var list<array{id: int, schedule_key: string, target_name: string}> $orphans */
        $orphans = [];
        /** @var list<string> $ambiguous */
        $ambiguous = [];

        foreach (DB::table('schedules')->orderBy('id')->get() as $schedule) {
            $classified = $this->classify($schedule);

            if ($classified === 'ambiguous') {
                $ambiguous[] = 'schedule_id='.$this->rowInteger($schedule, 'id');

                continue;
            }

            if ($classified === 'orphan') {
                $orphans[] = $this->orphanFrom($schedule);

                continue;
            }

            $updates[$this->rowInteger($schedule, 'id')] = $this->updateFrom($schedule);
        }

        if ($ambiguous !== []) {
            throw new RuntimeException(
                'Closed schedule ownership requires a single live owner before migration: '
                .implode('; ', $ambiguous)
                .'. Resolve contradictory owner foreign keys, then rerun migrations.',
            );
        }

        $collisions = $this->remappedScheduleKeyCollisions($updates);

        if ($collisions !== []) {
            throw new RuntimeException(
                'Closed schedule ownership cannot remap colliding schedule_key values: '
                .implode('; ', $collisions)
                .'. Resolve the duplicate keys, then rerun migrations.',
            );
        }

        return [
            'updates' => $updates,
            'orphans' => $orphans,
        ];
    }

    private function classify(object $schedule): string
    {
        $scope = $this->rowString($schedule, 'scope');
        $instance = $this->liveInstance($schedule);
        $node = $this->liveNode($schedule);

        if (in_array($scope, ['app', 'instance'], true)) {
            if ($node instanceof \stdClass) {
                return 'ambiguous';
            }

            if ($this->rowNullableInteger($schedule, 'instance_id') === null) {
                return 'ambiguous';
            }

            return $instance instanceof \stdClass ? 'update' : 'orphan';
        }

        if ($scope === 'node') {
            if ($instance instanceof \stdClass) {
                return 'ambiguous';
            }

            if ($this->rowNullableInteger($schedule, 'node_id') === null) {
                return 'ambiguous';
            }

            return $node instanceof \stdClass ? 'update' : 'orphan';
        }

        if ($scope === 'orbit') {
            return $instance instanceof \stdClass || $node instanceof \stdClass ? 'ambiguous' : 'update';
        }

        return 'ambiguous';
    }

    /**
     * @return array{scope: string, instance_id: int|null, node_id: int|null, target_name: string, previous_schedule_key: string, schedule_key: string}
     */
    private function updateFrom(object $schedule): array
    {
        $scope = $this->rowString($schedule, 'scope');
        $name = $this->rowString($schedule, 'name');
        $previousKey = $this->rowString($schedule, 'schedule_key');

        if (in_array($scope, ['app', 'instance'], true)) {
            $instance = $this->liveInstance($schedule);
            $app = $instance instanceof \stdClass
                ? DB::table('apps')->where('id', $this->rowInteger($instance, 'app_id'))->first()
                : null;

            if (! $instance instanceof \stdClass || ! $app instanceof \stdClass) {
                throw new RuntimeException('Closed schedule ownership expected a live instance owner.');
            }

            $targetName = $this->rowString($app, 'name').'.'.$this->rowString($instance, 'name');

            return [
                'scope' => 'instance',
                'instance_id' => $this->rowInteger($instance, 'id'),
                'node_id' => null,
                'target_name' => $targetName,
                'previous_schedule_key' => $previousKey,
                'schedule_key' => "instance:{$targetName}:{$name}",
            ];
        }

        if ($scope === 'node') {
            $node = $this->liveNode($schedule);

            if (! $node instanceof \stdClass) {
                throw new RuntimeException('Closed schedule ownership expected a live node owner.');
            }

            return [
                'scope' => 'node',
                'instance_id' => null,
                'node_id' => $this->rowInteger($node, 'id'),
                'target_name' => $this->rowString($node, 'name'),
                'previous_schedule_key' => $previousKey,
                'schedule_key' => $previousKey,
            ];
        }

        return [
            'scope' => 'orbit',
            'instance_id' => null,
            'node_id' => null,
            'target_name' => 'gateway',
            'previous_schedule_key' => $previousKey,
            'schedule_key' => $previousKey,
        ];
    }

    /**
     * @return array{id: int, schedule_key: string, target_name: string}
     */
    private function orphanFrom(object $schedule): array
    {
        $previousKey = $this->rowString($schedule, 'schedule_key');

        return [
            'id' => $this->rowInteger($schedule, 'id'),
            'schedule_key' => $previousKey,
            'target_name' => $this->historicalTargetName(
                $previousKey,
                $this->rowString($schedule, 'target_name'),
            ),
        ];
    }

    private function liveInstance(object $schedule): ?\stdClass
    {
        $instanceId = $this->rowNullableInteger($schedule, 'instance_id');
        $instance = $instanceId === null ? null : DB::table('instances')->where('id', $instanceId)->first();

        return $instance instanceof \stdClass ? $instance : null;
    }

    private function liveNode(object $schedule): ?\stdClass
    {
        $nodeId = $this->rowNullableInteger($schedule, 'node_id');
        $node = $nodeId === null ? null : DB::table('nodes')->where('id', $nodeId)->first();

        return $node instanceof \stdClass ? $node : null;
    }

    private function ensureHistoricalRunLabels(): void
    {
        if (! Schema::hasTable('schedule_runs') || Schema::hasColumn('schedule_runs', 'target_name')) {
            return;
        }

        Schema::table('schedule_runs', static function (Blueprint $table): void {
            $table->string('target_name')->nullable()->after('schedule_key');
        });
    }

    private function rebuildSchedulesWithoutAuthoritativeApp(): void
    {
        $columns = collect(DB::select('PRAGMA table_info(schedules)'))
            ->filter(fn (object $column): bool => $this->sqliteColumnName($column) !== 'app_id')
            ->values();

        $columnSql = $columns
            ->map(fn (object $column): string => $this->columnSql($column))
            ->implode(', ');

        $check =
            'CHECK (('
            ."scope = 'orbit' AND instance_id IS NULL AND node_id IS NULL) OR ("
            ."scope = 'node' AND node_id IS NOT NULL AND instance_id IS NULL) OR ("
            ."scope = 'instance' AND instance_id IS NOT NULL AND node_id IS NULL))";

        DB::statement("CREATE TABLE schedules_next ({$columnSql}, {$check})");

        $quoted = $columns
            ->map(fn (object $column): string => '"'.$this->sqliteColumnName($column).'"')
            ->implode(', ');
        DB::statement("INSERT INTO schedules_next ({$quoted}) SELECT {$quoted} FROM schedules");
        DB::statement('DROP TABLE schedules');
        DB::statement('ALTER TABLE schedules_next RENAME TO schedules');

        Schema::table('schedules', static function (Blueprint $table): void {
            $table->unique('schedule_key');
            $table->index(['scope', 'target_name', 'name']);
            $table->index(['instance_id', 'name']);
            $table->index(['node_id', 'name']);

            if (Schema::hasColumn('schedules', 'enabled')) {
                $table->index(['enabled', 'status']);
            }
        });
    }

    private function assertSchedulesRebuildIsNotPartial(): void
    {
        if (! Schema::hasTable('schedules')) {
            $leftover = Schema::hasTable('schedules_next')
                ? ' Leftover table schedules_next is present; the rebuild crashed between DROP and RENAME.'
                : '';

            throw new RuntimeException(
                'Closed schedule ownership rebuild is incomplete: schedules table is missing.'
                .$leftover
                .' Restore the schedules table, then rerun migrations.',
            );
        }

        if (Schema::hasColumn('schedules', 'app_id')) {
            return;
        }

        if (Schema::hasTable('schedules_next')) {
            throw new RuntimeException(
                'Closed schedule ownership rebuild is incomplete: leftover table schedules_next exists after app_id was removed. Restore or finish the rebuild before rerunning migrations.',
            );
        }

        $missing = $this->rebuiltSchedulesMissingIndexes();

        if ($missing !== []) {
            throw new RuntimeException(
                'Closed schedule ownership rebuild is incomplete: schedules exists without app_id but is missing indexes ['
                .implode(', ', $missing)
                .']. Recreate the unique and secondary indexes, then rerun migrations.',
            );
        }
    }

    private function dropLeftoverSchedulesNext(): void
    {
        if (Schema::hasTable('schedules_next')) {
            Schema::drop('schedules_next');
        }
    }

    /**
     * @param array<int, array{scope: string, instance_id: int|null, node_id: int|null, target_name: string, previous_schedule_key: string, schedule_key: string}> $updates
     * @return list<string>
     */
    private function remappedScheduleKeyCollisions(array $updates): array
    {
        /** @var array<string, int> $seen */
        $seen = [];
        /** @var list<string> $collisions */
        $collisions = [];

        foreach ($updates as $scheduleId => $assignment) {
            $key = $assignment['schedule_key'];
            $previous = $seen[$key] ?? null;

            if ($previous === null) {
                $seen[$key] = $scheduleId;

                continue;
            }

            $collisions[] = "schedule_id={$previous} and schedule_id={$scheduleId} both resolve to {$key}";
        }

        return $collisions;
    }

    /**
     * @return list<string>
     */
    private function rebuiltSchedulesMissingIndexes(): array
    {
        $required = [
            'unique(schedule_key)' => ['unique' => true, 'columns' => ['schedule_key']],
            '(scope, target_name, name)' => ['unique' => false, 'columns' => ['scope', 'target_name', 'name']],
            '(instance_id, name)' => ['unique' => false, 'columns' => ['instance_id', 'name']],
            '(node_id, name)' => ['unique' => false, 'columns' => ['node_id', 'name']],
        ];

        if (Schema::hasColumn('schedules', 'enabled')) {
            $required['(enabled, status)'] = ['unique' => false, 'columns' => ['enabled', 'status']];
        }

        $present = $this->scheduleIndexSignatures();
        $missing = [];

        foreach ($required as $label => $spec) {
            foreach ($present as $index) {
                if ($index['columns'] !== $spec['columns']) {
                    continue;
                }

                if ($spec['unique'] && ! $index['unique']) {
                    continue;
                }

                continue 2;
            }

            $missing[] = $label;
        }

        return $missing;
    }

    /**
     * @return list<array{unique: bool, columns: list<string>}>
     *
     * @mago-expect analysis:mixed-assignment
     */
    private function scheduleIndexSignatures(): array
    {
        $indexes = [];

        foreach (DB::select("PRAGMA index_list('schedules')") as $index) {
            if (! is_object($index)) {
                continue;
            }

            $name = $this->sqliteColumnValue($index, 'name');

            if (! is_string($name) || $name === '') {
                continue;
            }

            $columns = $this->sqliteIndexColumns($name);

            if ($columns === []) {
                continue;
            }

            $indexes[] = [
                'unique' => $this->sqliteColumnInteger($index, 'unique') === 1,
                'columns' => $columns,
            ];
        }

        return $indexes;
    }

    /**
     * @return list<string>
     *
     * @mago-expect analysis:mixed-assignment
     */
    private function sqliteIndexColumns(string $indexName): array
    {
        $quoted = str_replace(search: "'", replace: "''", subject: $indexName);
        /** @var list<array{seqno: int, name: string}> $rows */
        $rows = [];

        foreach (DB::select("PRAGMA index_info('{$quoted}')") as $info) {
            if (! is_object($info)) {
                continue;
            }

            $rows[] = [
                'seqno' => $this->sqliteColumnInteger($info, 'seqno'),
                'name' => $this->sqliteColumnName($info),
            ];
        }

        usort($rows, static fn (array $left, array $right): int => $left['seqno'] <=> $right['seqno']);

        $columns = [];

        foreach ($rows as $row) {
            $columns[] = $row['name'];
        }

        return $columns;
    }

    private function columnSql(object $column): string
    {
        $name = $this->sqliteColumnName($column);

        if ($name === 'instance_id') {
            return '"instance_id" INTEGER REFERENCES instances(id) ON DELETE CASCADE';
        }

        if ($name === 'node_id') {
            return '"node_id" INTEGER REFERENCES nodes(id) ON DELETE CASCADE';
        }

        $sql = '"'.$name.'" '.$this->sqliteColumnType($column);

        if ($this->sqliteColumnInteger($column, 'pk') === 1) {
            return $sql.' PRIMARY KEY AUTOINCREMENT';
        }

        if ($this->sqliteColumnInteger($column, 'notnull') === 1) {
            $sql .= ' NOT NULL';
        }

        $default = $this->sqliteColumnValue($column, 'dflt_value');

        if (is_string($default) || is_int($default)) {
            $sql .= ' DEFAULT '.$default;
        }

        return $sql;
    }

    /** @mago-expect analysis:mixed-assignment */
    private function sqliteColumnName(object $column): string
    {
        $name = $this->sqliteColumnValue($column, 'name');

        if (! is_string($name) || $name === '') {
            throw new RuntimeException('Schedule rebuild column is missing a name.');
        }

        return $name;
    }

    /** @mago-expect analysis:mixed-assignment */
    private function sqliteColumnType(object $column): string
    {
        $type = $this->sqliteColumnValue($column, 'type');

        if (! is_string($type) || $type === '') {
            throw new RuntimeException('Schedule rebuild column is missing a type.');
        }

        return $type;
    }

    /** @mago-expect analysis:mixed-assignment */
    private function sqliteColumnInteger(object $column, string $field): int
    {
        $value = $this->sqliteColumnValue($column, $field);

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw new RuntimeException("Schedule rebuild column has an invalid {$field} integer.");
    }

    /** @mago-expect analysis:mixed-assignment */
    private function sqliteColumnValue(object $column, string $field): mixed
    {
        return get_object_vars($column)[$field] ?? null;
    }

    private function historicalTargetName(string $scheduleKey, string $fallback): string
    {
        $parts = explode(':', $scheduleKey, 3);

        if (count($parts) === 3 && $parts[1] !== '') {
            return $parts[1];
        }

        return $fallback;
    }

    /** @mago-expect analysis:mixed-assignment */
    private function rowInteger(object $row, string $field): int
    {
        $value = $this->rowValue($row, $field);

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw new RuntimeException("Closed schedule ownership row has an invalid {$field} integer.");
    }

    private function rowNullableInteger(object $row, string $field): ?int
    {
        return $this->rowValue($row, $field) === null ? null : $this->rowInteger($row, $field);
    }

    /** @mago-expect analysis:mixed-assignment */
    private function rowString(object $row, string $field): string
    {
        $value = $this->rowValue($row, $field);

        if (! is_string($value)) {
            throw new RuntimeException("Closed schedule ownership row has an invalid {$field} string.");
        }

        return $value;
    }

    private function rowValue(object $row, string $field): mixed
    {
        return get_object_vars($row)[$field] ?? null;
    }
};
