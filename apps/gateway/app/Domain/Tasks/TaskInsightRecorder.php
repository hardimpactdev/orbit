<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Task;
use App\Support\ValidatedData;
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
        $ids = $groups->orderBy('tasks.id')->toBase()->pluck('tasks.id')->map(ValidatedData::integer(...))->all();
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
        $row = ValidatedData::object(json_decode($facts, true, flags: JSON_THROW_ON_ERROR));
        $int = static fn (string $key): int => ValidatedData::integer($row[$key] ?? null);
        $causes = [];
        foreach (ValidatedData::object($row['causes'] ?? []) as $cause => $count) {
            $causes[$cause] = ValidatedData::integer($count);
        }
        $findings = [];
        foreach (ValidatedData::object($row['review_findings'] ?? []) as $category => $count) {
            $findings[$category] = ValidatedData::integer($count);
        }
        $rejected = [];
        foreach (ValidatedData::object($row['rejected_deliveries'] ?? []) as $reason => $count) {
            $rejected[$reason] = ValidatedData::integer($count);
        }
        $parts = [];
        $list = $row['check_parts'] ?? [];
        foreach (is_array($list) && array_is_list($list) ? $list : throw new \InvalidArgumentException('Validated input must be a list.') as $part) {
            $part = ValidatedData::object($part);
            $seconds = $part['seconds'] ?? null;
            $parts[] = ['name' => ValidatedData::string($part['name'] ?? null), 'runs' => ValidatedData::integer($part['runs'] ?? null),
                'failures' => ValidatedData::integer($part['failures'] ?? null),
                'seconds' => is_int($seconds) || is_float($seconds) ? (float) $seconds : throw new \InvalidArgumentException('Validated input must be a number.')];
        }

        return [
            'id' => $int('id'), 'project_id' => $int('project_id'),
            'model' => ValidatedData::nullableString($row['model'] ?? null), 'project' => ValidatedData::string($row['project'] ?? null),
            'release' => ValidatedData::nullableString($row['release'] ?? null),
            'ended_at' => CarbonImmutable::parse(ValidatedData::string($row['ended_at'] ?? null)),
            'merged' => ($row['merged'] ?? null) === true,
            'tokens' => self::nullableInteger($row['tokens'] ?? null), 'cost' => is_float($row['cost'] ?? null) ? $row['cost'] : null,
            'input' => self::nullableInteger($row['input'] ?? null), 'cached' => self::nullableInteger($row['cached'] ?? null),
            'wasted' => self::nullableInteger($row['wasted'] ?? null), 'lines' => $int('lines'),
            'lead' => self::nullableInteger($row['lead'] ?? null), 'queue' => self::nullableInteger($row['queue'] ?? null),
            'work' => self::nullableInteger($row['work'] ?? null), 'merge_wait' => self::nullableInteger($row['merge_wait'] ?? null),
            'pr_merge_wait' => self::nullableInteger($row['pr_merge_wait'] ?? null), 'delivery' => self::nullableInteger($row['delivery'] ?? null),
            'deploy_wait' => self::nullableInteger($row['deploy_wait'] ?? null),
            'first_pass' => ($row['first_pass'] ?? null) === true,
            'verdict_subtasks' => $int('verdict_subtasks'), 'first_approved' => $int('first_approved'), 'verdicts' => $int('verdicts'),
            'handoff_subtasks' => $int('handoff_subtasks'), 'handoff_passed' => $int('handoff_passed'),
            'questions' => $int('questions'), 'causes' => $causes,
            'gated' => ($row['gated'] ?? null) === true, 'handoffs' => $int('handoffs'), 'subtasks' => $int('subtasks'),
            'reviewed_subtasks' => $int('reviewed_subtasks'), 'first_review_approved' => $int('first_review_approved'),
            'first_review_findings' => $int('first_review_findings'), 'review_findings' => $findings, 'handoff_questions' => $int('handoff_questions'),
            'rejected_deliveries' => $rejected, 'check_parts' => $parts, 'unresolved' => ($row['unresolved'] ?? null) === true,
        ];
    }

    private static function nullableInteger(mixed $value): ?int
    {
        return $value === null ? null : ValidatedData::integer($value);
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
