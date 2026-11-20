<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Project;
use App\Models\Task;
use App\Support\ValidatedData;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The stored [task insights](/reference/tasks#task-insights) report of each Project, and of all Projects, for each period.
 * `refresh` recomputes them from the records only; the endpoint reads them and never the source tables.
 *
 * @phpstan-import-type Row from TaskInsights
 */
final readonly class TaskInsightTotals
{
    public function __construct(private TaskInsights $insights, private TaskInsightRecorder $recorder) {}

    /**
     * The stored report. Before the first refresh has recorded the ended groups and stored its reports, the records may be
     * incomplete, so the report comes from the source tables and nothing is stored. After it, a scope with no stored report yet,
     * such as a new Project, computes and stores it from the records.
     *
     * @param  key-of<TaskInsights::Periods>  $period
     * @return array<string, mixed>
     */
    public function read(string $period, ?int $projectId, CarbonImmutable $now): array
    {
        $payload = DB::table('task_insight_totals')->where('scope', self::scope($projectId))->where('period', $period)->value('payload');
        if (is_string($payload)) {
            $stored = self::decode($payload);
            // A report stored before its buckets had their present fields, such as `hourly` or the tile numbers, is computed again below.
            if (self::bucketShape($stored) === self::bucketShape($this->insights->aggregate($period, [], $now)->toArray())) {
                return $stored;
            }
        }
        // Only a finished refresh stores reports before this point, so any stored report means the records are complete.
        if (! DB::table('task_insight_totals')->exists()) {
            return $this->insights->report($period, $projectId, $now)->toArray();
        }

        return $this->store($projectId, [$period], $this->rows($projectId, $now), $now)[$period];
    }

    /**
     * Records the ended groups in the trend whose source rows changed since their record, which also backfills groups that
     * have none, then recomputes every stored scope, all Projects, and each Project.
     */
    public function refresh(CarbonImmutable $now): void
    {
        $this->recorder->recordAll($this->stale(TaskInsights::trendStart($now)));
        $rows = $this->rows(null, $now);
        $byProject = [];
        foreach ($rows as $row) {
            $byProject[$row['project_id']][] = $row;
        }
        $scopes = array_unique([
            ...Project::query()->pluck('id')->map(ValidatedData::integer(...))->all(),
            ...DB::table('task_insight_totals')->whereNotNull('project_id')->distinct()->pluck('project_id')->map(ValidatedData::integer(...))->all(),
        ]);
        $this->store(null, array_keys(TaskInsights::Periods), $rows, $now);
        foreach ($scopes as $projectId) {
            $this->store($projectId, array_keys(TaskInsights::Periods), $byProject[$projectId] ?? [], $now);
        }
    }

    /**
     * The ended groups in the trend with no record, or with a group, subtask, thread, verdict, handoff check, handoff or question
     * changed after it. A write that bypasses the model observer still reaches the record this way.
     *
     * @return Builder<Task>
     */
    private function stale(CarbonImmutable $since): Builder
    {
        // At or after: timestamps have one-second resolution, so a write in the second of its record still counts as changed.
        $changedAfter = static fn (string $table, string $column, string $owner = 'tasks.id') => static fn ($query) => $query->selectRaw('1')->from($table)
            ->whereColumn("{$table}.{$column}", $owner)->whereColumn("{$table}.updated_at", '>=', 'task_insight_records.recorded_at');
        // A handoff row is written once and never changes.
        $handoffAfter = static fn (string $owner) => static fn ($query) => $query->selectRaw('1')->from('task_handoffs')
            ->whereColumn('task_handoffs.task_id', $owner)->whereColumn('task_handoffs.created_at', '>=', 'task_insight_records.recorded_at');

        return Task::topLevel()->where('tasks.ended_at', '>=', $since)
            ->whereIn('tasks.status', array_map(static fn (TaskGroupStatus $status): string => $status->value, TaskGroupStatus::endedStatuses()))
            ->leftJoin('task_insight_records', 'task_insight_records.task_id', '=', 'tasks.id')
            ->where(static fn ($query) => $query->whereNull('task_insight_records.id')
                ->orWhereColumn('tasks.updated_at', '>=', 'task_insight_records.recorded_at')
                ->orWhereExists($changedAfter('agent_threads', 'task_group_id'))
                ->orWhereExists($changedAfter('task_comments', 'task_group_id'))
                ->orWhereExists($changedAfter('task_questions', 'task_id'))
                ->orWhereExists($handoffAfter('tasks.id'))
                ->orWhereExists(static fn ($query) => $query->selectRaw('1')->from('tasks as subtasks')
                    ->whereColumn('subtasks.parent_id', 'tasks.id')
                    ->where(static fn ($query) => $query->whereColumn('subtasks.updated_at', '>=', 'task_insight_records.recorded_at')
                        ->orWhereExists($changedAfter('task_checks', 'task_id', 'subtasks.id'))
                        ->orWhereExists($handoffAfter('subtasks.id'))))
                // A release verified or changed after the record may be the group's release now.
                ->orWhereExists(static fn ($query) => $query->selectRaw('1')->from('gateway_releases')
                    ->where('gateway_releases.outcome', 'verified')->whereNotNull('gateway_releases.sha')
                    ->whereColumn('gateway_releases.created_at', '<=', 'tasks.started_at')
                    ->whereColumn('gateway_releases.updated_at', '>=', 'task_insight_records.recorded_at'))
            );
    }

    /** @return list<Row> */
    private function rows(?int $projectId, CarbonImmutable $now): array
    {
        $rows = [];
        $codes = Project::query()->pluck('code', 'id')->all();
        DB::table('task_insight_records')->where('ended_at', '>=', TaskInsights::trendStart($now)->format('Y-m-d H:i:s'))
            ->when($projectId !== null, static fn ($query) => $query->where('project_id', $projectId))
            ->select(['id', 'facts'])->orderBy('id')
            ->chunkById(500, static function ($chunk) use (&$rows, $codes): void {
                foreach ($chunk as $record) {
                    $row = TaskInsightRecorder::row((string) $record->facts);
                    $rows[] = [...$row, 'project' => TaskInsights::projectCode($codes, $row['project_id'])];
                }
            });

        return $rows;
    }

    /**
     * @param  list<key-of<TaskInsights::Periods>>  $periods
     * @param  list<Row>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function store(?int $projectId, array $periods, array $rows, CarbonImmutable $now): array
    {
        $payloads = [];
        foreach ($periods as $period) {
            $payloads[$period] = $this->insights->aggregate($period, $rows, $now)->toArray();
            // An upsert, so two first reads of one scope both store it.
            DB::table('task_insight_totals')->upsert([[
                'scope' => self::scope($projectId), 'project_id' => $projectId, 'period' => $period, 'computed_at' => $now->utc()->format('Y-m-d H:i:s'),
                'payload' => json_encode($payloads[$period], JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
            ]], ['scope', 'period'], ['computed_at', 'payload']);
        }

        return $payloads;
    }

    /**
     * The keys of each daily and hourly bucket of a report, or null when it lacks either list.
     *
     * @param  array<string, mixed>  $report
     * @return ?array<string, list<list<int|string>>>
     */
    private static function bucketShape(array $report): ?array
    {
        $shape = [];
        foreach (['daily', 'hourly'] as $list) {
            if (! is_array($report[$list] ?? null)) {
                return null;
            }
            $shape[$list] = array_map(static fn (mixed $bucket): array => is_array($bucket) ? array_keys($bucket) : [], array_values($report[$list]));
        }

        return $shape;
    }

    /** @return array<string, mixed> */
    private static function decode(string $payload): array
    {
        /** @var array<string, mixed> */
        return json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
    }

    private static function scope(?int $projectId): string
    {
        return $projectId === null ? 'all' : 'project:'.$projectId;
    }
}
