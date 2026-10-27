<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Data\Tasks\TaskInsightsBreakdownRowData;
use App\Data\Tasks\TaskInsightsBreakdownsData;
use App\Data\Tasks\TaskInsightsData;
use App\Data\Tasks\TaskInsightsDayData;
use App\Data\Tasks\TaskInsightsReleaseData;
use App\Data\Tasks\TaskInsightsSummaryData;
use App\Data\Tasks\TaskInsightsWeekData;
use App\Models\AgentThread;
use App\Models\GatewayRelease;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskComment;
use App\Models\TaskQuestion;
use App\Support\Percentile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Computes [task insights](/reference/tasks#task-insights) from the ended tasks, their threads, verdicts,
 * handoff checks, and questions. Every number is defined here and nowhere else.
 *
 * @phpstan-type Row array{
 *     model: string, project: string, release: ?string, ended_at: CarbonImmutable, merged: bool,
 *     tokens: int, cost: ?float, input: int, cached: int, wasted: int, lines: int,
 *     lead: ?int, queue: ?int, work: ?int, merge_wait: ?int, first_pass: bool,
 *     verdict_subtasks: int, first_approved: int, verdicts: int, handoff_subtasks: int, handoff_passed: int,
 *     questions: int, causes: array<string, int>
 * }
 */
final class TaskInsights
{
    private const int Weeks = 12;

    /** @param  7|30  $days */
    public function report(int $days, ?int $projectId, CarbonImmutable $now): TaskInsightsData
    {
        $tomorrow = $now->utc()->startOfDay()->addDay();
        $from = $tomorrow->subDays($days);
        $trendFrom = $tomorrow->subWeeks(self::Weeks);
        $releases = GatewayRelease::query()->where('outcome', 'verified')->whereNotNull('sha')
            ->orderBy('created_at')->get(['sha', 'created_at']);
        $rows = $this->rows($trendFrom, $projectId, $releases);
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
            releases: array_values(array_filter(array_map(
                static fn (GatewayRelease $release): ?TaskInsightsReleaseData => $release->sha !== null && $release->created_at?->gte($trendFrom) === true
                    ? new TaskInsightsReleaseData($release->sha, $release->created_at->toIso8601String())
                    : null,
                $releases->all(),
            ))),
            breakdowns: new TaskInsightsBreakdownsData(
                model: $this->breakdown($current, 'model'),
                project: $this->breakdown($current, 'project'),
                release: $this->breakdown($current, 'release'),
            ),
        );
    }

    /**
     * One row per task that ended since the start of the trend.
     *
     * @param  Collection<int, GatewayRelease>  $releases
     * @return list<Row>
     */
    private function rows(CarbonImmutable $since, ?int $projectId, Collection $releases): array
    {
        $groups = Task::topLevel()->with('project')
            ->whereIn('status', array_map(static fn (TaskGroupStatus $status): string => $status->value, TaskGroupStatus::endedStatuses()))
            ->where('ended_at', '>=', $since)
            ->when($projectId !== null, static fn ($query) => $query->where('project_id', $projectId))
            ->orderBy('id')->get();
        $ids = $groups->modelKeys();
        /** Children, threads, first verdicts, and first handoff results, by task. */
        $children = [];
        foreach (Task::query()->whereIn('parent_id', $ids)->orderBy('id')->get() as $child) {
            $children[(int) $child->parent_id][] = $child;
        }
        $threads = [];
        foreach (AgentThread::query()->whereIn('task_group_id', $ids)->get() as $thread) {
            $threads[$thread->task_group_id][] = $thread;
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
        $checkRows = TaskCheck::query()->whereIn('task_id', array_merge([], ...array_map(static fn (array $list): array => array_map(static fn (Task $child): int => $child->id, $list), array_values($children))))
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
            $groupChildren = $children[$group->id] ?? [];
            $groupThreads = $threads[$group->id] ?? [];
            $groupVerdicts = $verdicts[$group->id] ?? [];
            $groupCauses = $causes[$group->id] ?? [];
            $firstHandoffs = array_values(array_filter(
                array_map(static fn (Task $child): ?bool => $handoffs[$child->id] ?? null, $groupChildren),
                static fn (?bool $passed): bool => $passed !== null,
            ));
            // A subtask's newest implementer thread is its current one; tick and external runs both create a new thread to replace one.
            $newest = [];
            foreach ($groupThreads as $thread) {
                if ($thread->role === 'implementer') {
                    $newest[$thread->task_id ?? 0] = max($newest[$thread->task_id ?? 0] ?? 0, $thread->id);
                }
            }
            $tokens = 0;
            $replaced = 0;
            $cost = 0.0;
            $input = 0;
            $cached = 0;
            foreach ($groupThreads as $thread) {
                $tokens += $thread->tokens ?? 0;
                $input += $thread->input_tokens ?? 0;
                $cached += $thread->cached_input_tokens ?? 0;
                if ($thread->role === 'implementer' && $newest[$thread->task_id ?? 0] !== $thread->id) {
                    $replaced += $thread->tokens ?? 0;
                }
                $cost = $cost === null || ($thread->cost_usd === null && ($thread->tokens ?? 0) > 0) ? null : $cost + ($thread->cost_usd ?? 0.0);
            }
            $merged = $group->pullRequestMerged();
            $mergedAt = $merged ? $group->merged_at : null;

            $rows[] = [
                'model' => $group->implementer_model,
                'project' => $group->project->code,
                'release' => $this->releaseAt($releases, $group->started_at),
                'ended_at' => $group->ended_at ?? throw new \LogicException('The query selects ended tasks only.'),
                'merged' => $merged,
                'tokens' => $tokens,
                'cost' => $cost,
                'input' => $input,
                'cached' => $cached,
                'wasted' => $merged ? $replaced : $tokens,
                'lines' => ($group->lines_added ?? 0) + ($group->lines_deleted ?? 0),
                'lead' => self::span($group->ready_at, $mergedAt),
                'queue' => $merged ? self::span($group->ready_at, $group->started_at) : null,
                'work' => $merged ? self::span($group->started_at, $group->settled_at) : null,
                'merge_wait' => self::span($group->settled_at, $mergedAt),
                'first_pass' => $merged && array_filter($groupChildren, static fn (Task $child): bool => $child->fixup_problem !== null) === [],
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
     * @param  'lead'|'queue'|'work'|'merge_wait'  $key
     */
    private static function median(array $rows, string $key): ?int
    {
        return Percentile::of(array_values(array_filter(array_column($rows, $key), static fn (?int $value): bool => $value !== null)), .5);
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
