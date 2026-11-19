<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Keeps one [task insights](/reference/tasks#task-insights) record per ended task group: the row `TaskInsights` builds for it,
 * recomputed from the source tables whenever one of them changes. A group that is not ended has no record.
 *
 * @phpstan-import-type Row from TaskInsights
 */
final readonly class TaskInsightRecorder
{
    /** Groups recorded per query; a chunk of rows stays far below the web runtime's 128M limit. */
    private const int Chunk = 200;

    public function __construct(private TaskInsights $insights) {}

    public function record(int $groupId): void
    {
        // A running group, the usual source of thread token updates, has no record to change.
        $ended = Task::topLevel()->whereKey($groupId)->whereIn('status', array_map(static fn (TaskGroupStatus $status): string => $status->value, TaskGroupStatus::endedStatuses()))->exists();
        if ($ended || DB::table('task_insight_records')->where('task_id', $groupId)->exists()) {
            $this->recordChunk([$groupId]);
        }
    }

    /** @param  Builder<Task>  $groups  top-level tasks */
    public function recordAll(Builder $groups): int
    {
        $ids = $groups->orderBy('tasks.id')->toBase()->pluck('tasks.id')->map(static fn (mixed $id): int => (int) $id)->all();
        foreach (array_chunk($ids, self::Chunk) as $chunk) {
            $this->recordChunk($chunk);
        }

        return count($ids);
    }

    /**
     * The facts of one stored record as the row `TaskInsights` aggregates.
     *
     * @return Row
     */
    public static function row(string $facts): array
    {
        /** @var Row $row */
        $row = json_decode($facts, true, flags: JSON_THROW_ON_ERROR);
        $row['ended_at'] = CarbonImmutable::parse($row['ended_at']);

        return $row;
    }

    /** @param  list<int>  $ids */
    private function recordChunk(array $ids): void
    {
        $now = CarbonImmutable::now();
        $rows = $this->insights->rowsOf(Task::topLevel()->whereIn('id', $ids));
        DB::table('task_insight_records')->whereIn('task_id', array_diff($ids, array_column($rows, 'id')))->delete();
        if ($rows === []) {
            return;
        }
        DB::table('task_insight_records')->upsert(array_map(static fn (array $row): array => [
            'task_id' => $row['id'],
            'project_id' => $row['project_id'] === 0 ? null : $row['project_id'],
            'ended_at' => $row['ended_at']->utc()->format('Y-m-d H:i:s'),
            'facts' => json_encode([...$row, 'ended_at' => $row['ended_at']->toIso8601String()], JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
            'recorded_at' => $now->utc()->format('Y-m-d H:i:s'),
        ], $rows), ['task_id'], ['project_id', 'ended_at', 'facts', 'recorded_at']);
    }
}
