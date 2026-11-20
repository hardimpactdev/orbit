<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Data\Tasks\TaskInsightsBreakdownRowData;
use App\Data\Tasks\TaskInsightsBreakdownsData;
use App\Data\Tasks\TaskInsightsData;
use App\Data\Tasks\TaskInsightsDayData;
use App\Data\Tasks\TaskInsightsSummaryData;
use App\Data\Tasks\TaskInsightsWeekData;
use App\Models\AgentThread;
use App\Models\GatewayRelease;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskComment;
use App\Models\TaskQuestion;
use App\Support\Percentile;
use App\Support\ValidatedData;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Computes [task insights](/reference/tasks#task-insights) from the ended tasks, their threads, verdicts,
 * handoff checks, and questions. Every number is defined here and nowhere else: `rowsOf` builds the row of each
 * task group, which `TaskInsightRecorder` stores, and `aggregate` reduces rows to a report, which `TaskInsightTotals` stores.
 *
 * @phpstan-type Row array{
 *     id: int, project_id: int, model: ?string, project: string, release: ?string, ended_at: CarbonImmutable, merged: bool,
 *     tokens: int, cost: ?float, input: int, cached: int, wasted: int, lines: int,
 *     lead: ?int, queue: ?int, work: ?int, merge_wait: ?int, pr_merge_wait: ?int, delivery: ?int, deploy_wait: ?int, first_pass: bool,
 *     verdict_subtasks: int, first_approved: int, verdicts: int, handoff_subtasks: int, handoff_passed: int,
 *     questions: int, causes: array<string, int>
 * }
 */
final class TaskInsights
{
    private const int Weeks = 12;

    /**
     * The only task columns a report reads. A whole row carries briefs and summaries, and twelve weeks of whole rows
     * outgrew the Gateway web runtime's 128M memory limit, which failed the request as `gateway.unhandled`.
     */
    private const array GroupColumns = ['id', 'project_id', 'ready_at', 'started_at', 'settled_at', 'ended_at', 'merged_at', 'deployed_at', 'pr_opened_at', 'lines_added', 'lines_deleted'];

    /** The periods a report covers, by their name in the API; the only list of them. */
    public const array Periods = ['7d' => 7, '30d' => 30];

    /** The periods as one comma-separated rule list, which the OpenAPI generator also reads. */
    public static function periodList(): string
    {
        return implode(',', array_keys(self::Periods));
    }

    /**
     * The current code of a row's Project. A record keeps the Project's id, so a renamed Project aggregates under its new code.
     *
     * @param  array<int|string, mixed>  $codes  Project codes by id
     */
    public static function projectCode(array $codes, int $projectId): string
    {
        return ValidatedData::nullableString($codes[$projectId] ?? null) ?? '';
    }

    /**
     * The report computed from the source tables. The endpoint reads it from `TaskInsightTotals` instead,
     * which aggregates the same rows from their [records](/reference/tasks#task-insights).
     *
     * @param  7|30  $days
     */
    public function report(int $days, ?int $projectId, CarbonImmutable $now): TaskInsightsData
    {
        $groups = Task::topLevel()->where('ended_at', '>=', self::trendStart($now))
            ->when($projectId !== null, static fn ($query) => $query->where('project_id', $projectId));

        return $this->aggregate($days, $this->rowsOf($groups), $now);
    }

    /** The start of the oldest trend week: no older row counts in a report. */
    public static function trendStart(CarbonImmutable $now): CarbonImmutable
    {
        return $now->utc()->startOfDay()->addDay()->subWeeks(self::Weeks);
    }

    /**
     * The report over rows of one Project, or of all of them; rows that ended before the trend start do not count.
     *
     * @param  7|30  $days
     * @param  list<Row>  $rows
     */
    public function aggregate(int $days, array $rows, CarbonImmutable $now): TaskInsightsData
    {
        $tomorrow = $now->utc()->startOfDay()->addDay();
        $from = $tomorrow->subDays($days);
        $trendFrom = self::trendStart($now);
        usort($rows, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);
        $in = static fn (CarbonImmutable $start, CarbonImmutable $end): array => array_values(array_filter(
            $rows,
            static fn (array $row): bool => $row['ended_at'] >= $start && $row['ended_at'] < $end,
        ));
        $current = $in($from, $tomorrow);

        return new TaskInsightsData(
            period: $days.'d',
            from: $from->toIso8601String(),
            to: $tomorrow->toIso8601String(),
            current: $this->summary($current),
            previous: $this->summary($in($from->subDays($days), $from)),
            weekly: array_map(function (int $week) use ($trendFrom, $in): TaskInsightsWeekData {
                $start = $trendFrom->addWeeks($week);
                $week = $in($start, $start->addWeek());

                return new TaskInsightsWeekData($start->toDateString(), count($week), $this->mergedCount($week), $this->tokensPerMergedPr($week));
            }, range(0, self::Weeks - 1)),
            daily: array_map(function (int $day) use ($from, $in): TaskInsightsDayData {
                $start = $from->addDays($day);
                $ended = $in($start, $start->addDay());

                return new TaskInsightsDayData($start->toDateString(), $this->mergedCount($ended), count($ended) - $this->mergedCount($ended));
            }, range(0, $days - 1)),
            breakdowns: new TaskInsightsBreakdownsData(
                model: $this->breakdown($current, 'model'),
                project: $this->breakdown($current, 'project'),
                release: $this->breakdown($current, 'release'),
            ),
        );
    }

    /**
     * One row per ended task group that the query selects, ordered by id.
     *
     * @param  Builder<Task>  $query  top-level tasks
     * @return list<Row>
     */
    public function rowsOf(Builder $query): array
    {
        $releases = GatewayRelease::query()->where('outcome', 'verified')->whereNotNull('sha')
            ->orderBy('created_at')->get(['sha', 'created_at']);
        $codes = Project::query()->pluck('code', 'id')->all();
        $groups = $query
            ->whereIn('status', array_map(static fn (TaskGroupStatus $status): string => $status->value, TaskGroupStatus::endedStatuses()))
            ->whereNotNull('ended_at')
            ->orderBy('id')->toBase()->get(self::GroupColumns)
            ->map(static fn (object $group): array => [
                'id' => self::number($group->id), 'project_id' => self::number($group->project_id),
                'project' => self::projectCode($codes, self::number($group->project_id)),
                'lines' => self::number($group->lines_added) + self::number($group->lines_deleted),
                ...array_map(self::time(...), ['ready_at' => $group->ready_at, 'started_at' => $group->started_at, 'settled_at' => $group->settled_at,
                    'ended_at' => $group->ended_at, 'merged_at' => $group->merged_at, 'deployed_at' => $group->deployed_at, 'pr_opened_at' => $group->pr_opened_at]),
            ])->all();
        $ids = array_column($groups, 'id');
        /** Children, threads, first verdicts, and first handoff results, by task. */
        $children = [];
        foreach (Task::query()->whereIn('parent_id', $ids)->orderBy('id')->toBase()->get(['id', 'parent_id', 'fixup_problem']) as $child) {
            $children[self::number($child->parent_id)][] = ['id' => self::number($child->id), 'fixup' => $child->fixup_problem !== null];
        }
        $threads = [];
        $threadRows = AgentThread::query()->whereIn('task_group_id', $ids)->orderBy('id')->toBase()
            ->get(['id', 'task_group_id', 'task_id', 'role', 'model', 'tokens', 'input_tokens', 'cached_input_tokens', 'cost_usd']);
        foreach ($threadRows as $thread) {
            $threads[self::number($thread->task_group_id)][] = [
                'id' => self::number($thread->id), 'task' => self::number($thread->task_id),
                'role' => ValidatedData::string($thread->role), 'model' => ValidatedData::nullableString($thread->model),
                'tokens' => self::number($thread->tokens), 'input' => self::number($thread->input_tokens),
                'cached' => self::number($thread->cached_input_tokens),
                'cost' => is_numeric($thread->cost_usd) ? (float) $thread->cost_usd : null,
            ];
        }
        $verdicts = [];
        $verdictRows = TaskComment::query()->whereIn('task_group_id', $ids)
            ->whereIn('type', [TaskCommentType::Approved->value, TaskCommentType::ChangesRequested->value])
            ->orderBy('id')->get(['task_group_id', 'task_id', 'type']);
        foreach ($verdictRows as $verdict) {
            $subtask = $verdict->task_id;
            $verdicts[$verdict->task_group_id][$subtask] ??= ['count' => 0, 'approved' => $verdict->type === TaskCommentType::Approved];
            $verdicts[$verdict->task_group_id][$subtask]['count']++;
        }
        $handoffs = [];
        $checkRows = TaskCheck::query()->whereIn('task_id', array_merge([], ...array_map(static fn (array $list): array => array_column($list, 'id'), array_values($children))))
            ->where('kind', TaskCheckKind::Handoff->value)
            ->whereIn('status', [TaskCheckStatus::Passed->value, TaskCheckStatus::Failed->value])
            ->orderBy('id')->get(['task_id', 'status']);
        foreach ($checkRows as $check) {
            $handoffs[$check->task_id] ??= $check->status === TaskCheckStatus::Passed;
        }
        $causes = [];
        foreach (TaskQuestion::query()->whereIn('task_id', $ids)->get(['task_id', 'cause']) as $question) {
            $cause = $question->cause->value ?? 'unknown';
            $causes[$question->task_id][$cause] = ($causes[$question->task_id][$cause] ?? 0) + 1;
        }

        $rows = [];
        foreach ($groups as $group) {
            $groupChildren = $children[$group['id']] ?? [];
            $groupThreads = $threads[$group['id']] ?? [];
            $groupVerdicts = $verdicts[$group['id']] ?? [];
            $groupCauses = $causes[$group['id']] ?? [];
            $firstHandoffs = array_values(array_filter(
                array_map(static fn (array $child): ?bool => $handoffs[$child['id']] ?? null, $groupChildren),
                static fn (?bool $passed): bool => $passed !== null,
            ));
            // A subtask's newest implementer thread is its current one; tick and external runs both create a new thread to replace one.
            $newest = [];
            foreach ($groupThreads as $thread) {
                if ($thread['role'] === 'implementer') {
                    $newest[$thread['task']] = max($newest[$thread['task']] ?? 0, $thread['id']);
                }
            }
            $tokens = 0;
            $replaced = 0;
            $cost = 0.0;
            $input = 0;
            $cached = 0;
            foreach ($groupThreads as $thread) {
                $tokens += $thread['tokens'];
                $input += $thread['input'];
                $cached += $thread['cached'];
                if ($thread['role'] === 'implementer' && $newest[$thread['task']] !== $thread['id']) {
                    $replaced += $thread['tokens'];
                }
                $cost = $cost === null || ($thread['cost'] === null && $thread['tokens'] > 0) ? null : $cost + ($thread['cost'] ?? 0.0);
            }
            // A task's pull request merged, by Orbit or by a person, when it has a merge time.
            $mergedAt = $group['merged_at'];
            $merged = $mergedAt !== null;

            $rows[] = [
                'id' => $group['id'],
                'project_id' => $group['project_id'],
                // The model the group's first implementer thread ran; a group with no implementer thread counts as Unknown.
                'model' => array_values(array_filter($groupThreads, static fn (array $thread): bool => $thread['role'] === 'implementer'))[0]['model'] ?? null,
                'project' => $group['project'],
                'release' => $this->releaseAt($releases, $group['started_at']),
                'ended_at' => $group['ended_at'] ?? throw new \LogicException('The query selects ended tasks only.'),
                'merged' => $merged,
                'tokens' => $tokens,
                'cost' => $cost,
                'input' => $input,
                'cached' => $cached,
                'wasted' => $merged ? $replaced : $tokens,
                'lines' => $group['lines'],
                'lead' => self::span($group['ready_at'], $mergedAt),
                'queue' => $merged ? self::span($group['ready_at'], $group['started_at']) : null,
                'work' => $merged ? self::span($group['started_at'], $group['settled_at']) : null,
                'merge_wait' => self::span($group['settled_at'], $mergedAt),
                'pr_merge_wait' => self::span($group['pr_opened_at'], $mergedAt),
                'delivery' => $merged ? self::span($group['ready_at'], $group['deployed_at']) : null,
                'deploy_wait' => self::span($mergedAt, $group['deployed_at']),
                'first_pass' => $merged && array_filter($groupChildren, static fn (array $child): bool => $child['fixup']) === [],
                'verdict_subtasks' => count($groupVerdicts),
                'first_approved' => count(array_filter($groupVerdicts, static fn (array $verdict): bool => $verdict['approved'])),
                'verdicts' => array_sum(array_column($groupVerdicts, 'count')),
                'handoff_subtasks' => count($firstHandoffs),
                'handoff_passed' => count(array_filter($firstHandoffs)),
                'questions' => array_sum($groupCauses),
                'causes' => $groupCauses,
            ];
        }

        return $rows;
    }

    /** @param  list<Row>  $rows */
    private function summary(array $rows): TaskInsightsSummaryData
    {
        $merged = array_values(array_filter($rows, static fn (array $row): bool => $row['merged']));
        $tokens = array_sum(array_column($rows, 'tokens'));
        $costs = array_column($rows, 'cost');
        $cost = $rows === [] || in_array(null, $costs, true) ? null : array_sum($costs);
        $input = array_sum(array_column($rows, 'input')) + array_sum(array_column($rows, 'cached'));
        $verdictSubtasks = array_sum(array_column($rows, 'verdict_subtasks'));
        $handoffSubtasks = array_sum(array_column($rows, 'handoff_subtasks'));
        $lines = array_sum(array_column($merged, 'lines'));
        $causes = array_fill_keys(array_map(static fn (QuestionCause $cause): string => $cause->value, QuestionCause::cases()), 0);
        foreach ($rows as $row) {
            foreach ($row['causes'] as $cause => $count) {
                $causes[$cause] = ($causes[$cause] ?? 0) + $count;
            }
        }
        ksort($causes);

        return new TaskInsightsSummaryData(
            tasks: count($rows),
            merged: count($merged),
            excluded: count(array_filter($merged, static fn (array $row): bool => $row['lead'] === null)),
            tokens: $tokens,
            costUsd: $cost,
            tokensPerMergedPr: $this->tokensPerMergedPr($rows),
            costPerMergedPr: $cost !== null && $merged !== [] ? $cost / count($merged) : null,
            leadTimeMs: self::median($merged, 'lead'),
            queueMs: self::median($merged, 'queue'),
            workMs: self::median($merged, 'work'),
            mergeWaitMs: self::median($merged, 'merge_wait'),
            prMergeWaitMs: self::median($merged, 'pr_merge_wait'),
            deliveryTimeMs: self::median($merged, 'delivery'),
            deployWaitMs: self::median($merged, 'deploy_wait'),
            yield: self::share(count($merged), count($rows)),
            prFirstPass: self::share(count(array_filter($merged, static fn (array $row): bool => $row['first_pass'])), count($merged)),
            wastedShare: self::share(array_sum(array_column($rows, 'wasted')), $tokens),
            subtaskFirstPass: self::share(array_sum(array_column($rows, 'first_approved')), $verdictSubtasks),
            reviewRounds: self::share(array_sum(array_column($rows, 'verdicts')), $verdictSubtasks),
            handoffFirstTry: self::share(array_sum(array_column($rows, 'handoff_passed')), $handoffSubtasks),
            questionsPerTask: self::share(array_sum(array_column($rows, 'questions')), count($rows)),
            questionsByCause: $causes,
            cacheHit: self::share(array_sum(array_column($rows, 'cached')), $input),
            tokensPerHundredLines: $lines > 0 ? intdiv(array_sum(array_column($merged, 'tokens')) * 100, $lines) : null,
        );
    }

    /**
     * @param  list<Row>  $rows
     * @param  'model'|'project'|'release'  $key
     * @return list<TaskInsightsBreakdownRowData>
     */
    private function breakdown(array $rows, string $key): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $groups[$row[$key] ?? ''][] = $row;
        }
        ksort($groups);
        $result = [];
        foreach ($groups as $value => $members) {
            $merged = array_values(array_filter($members, static fn (array $row): bool => $row['merged']));
            $label = (string) $value === '' ? 'Unknown' : ($key === 'release' ? substr((string) $value, 0, 7) : (string) $value);
            $result[] = new TaskInsightsBreakdownRowData(
                (string) $value,
                $label,
                count($members),
                count($merged),
                $this->tokensPerMergedPr($members),
                self::median($merged, 'lead'),
                self::share(count($merged), count($members)),
            );
        }

        return $result;
    }

    /** @param  list<Row>  $rows */
    private function tokensPerMergedPr(array $rows): ?int
    {
        $merged = $this->mergedCount($rows);

        return $merged === 0 ? null : intdiv(array_sum(array_column($rows, 'tokens')), $merged);
    }

    /** @param  list<Row>  $rows */
    private function mergedCount(array $rows): int
    {
        return count(array_filter($rows, static fn (array $row): bool => $row['merged']));
    }

    /**
     * The last verified release that began before the task started. A release record's start time never moves.
     *
     * @param  Collection<int, GatewayRelease>  $releases  ordered by time
     */
    private function releaseAt(Collection $releases, ?\DateTimeInterface $startedAt): ?string
    {
        if ($startedAt === null) {
            return null;
        }

        return $releases->last(static fn (GatewayRelease $release): bool => $release->created_at !== null && $release->created_at <= $startedAt)?->sha;
    }

    /**
     * @param  list<Row>  $rows
     * @param  'lead'|'queue'|'work'|'merge_wait'|'pr_merge_wait'|'delivery'|'deploy_wait'  $key
     */
    private static function median(array $rows, string $key): ?int
    {
        return Percentile::of(array_values(array_filter(array_column($rows, $key), static fn (?int $value): bool => $value !== null)), .5);
    }

    /** A timestamp column read without model casts, in the zone the model casts use. */
    private static function time(mixed $value): ?CarbonImmutable
    {
        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
    }

    /** An integer column read without model casts; null counts as 0. */
    private static function number(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function share(int|float $part, int|float $whole): ?float
    {
        return $whole > 0 ? $part / $whole : null;
    }

    private static function span(?\DateTimeInterface $start, ?\DateTimeInterface $end): ?int
    {
        if ($start === null || $end === null || $end < $start) {
            return null;
        }

        return (int) (($end->getTimestamp() - $start->getTimestamp()) * 1000);
    }
}
