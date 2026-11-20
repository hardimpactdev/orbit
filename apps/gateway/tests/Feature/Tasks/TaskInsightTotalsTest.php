<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Tasks\QuestionAsker;
use App\Domain\Tasks\QuestionCause;
use App\Domain\Tasks\QuestionStatus;
use App\Domain\Tasks\TaskBroadcasts;
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskCommentType;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskInsightRecorder;
use App\Domain\Tasks\TaskInsights;
use App\Domain\Tasks\TaskInsightsExposition;
use App\Domain\Tasks\TaskInsightTotals;
use App\Models\AgentThread;
use App\Models\GatewayRelease;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskComment;
use App\Models\TaskQuestion;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(LazilyRefreshDatabase::class);

function insightProject(string $slug): Project
{
    return Project::query()->create(['name' => $slug, 'slug' => $slug, 'repository_url' => "https://github.com/example/{$slug}.git", 'default_branch' => 'main', 'root' => 'public']);
}

/**
 * One ended group with a subtask, threads, verdicts, a handoff check and a question, ended `$daysAgo` days before now.
 *
 * @param  array{merged?: bool, fixup?: bool, cost?: ?float, approved?: bool, passed?: bool, cause?: ?QuestionCause, deployed?: bool, opened?: bool, unknown?: bool}  $facts
 */
function insightGroup(Project $project, float $daysAgo, array $facts = []): Task
{
    $ended = CarbonImmutable::now()->subMinutes((int) round($daysAgo * 1440));
    $merged = $facts['merged'] ?? true;
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Group', 'brief' => 'Brief',
        'status' => $merged ? TaskGroupStatus::Completed : TaskGroupStatus::Failed, 'ended_at' => $ended]);
    $group->forceFill([
        'ready_at' => $ended->subHours(5), 'started_at' => $ended->subHours(4), 'settled_at' => $ended->subHours(2),
        'pr_opened_at' => ($facts['opened'] ?? true) ? $ended->subMinutes(90) : null,
        'merged_at' => $merged ? $ended : null, 'deployed_at' => $merged && ($facts['deployed'] ?? true) ? $ended->addMinutes(37) : null,
        'lines_added' => 120, 'lines_deleted' => 13,
    ])->save();
    $subtask = $group->tasks()->create(['title' => 'Subtask', 'brief' => 'Brief', 'position' => 1]);
    if ($facts['fixup'] ?? false) {
        $group->tasks()->create(['title' => 'Fixup', 'brief' => 'Brief', 'position' => 2])->forceFill(['fixup_problem' => 'missed'])->save();
    }
    foreach ([['implementer', 'claude-opus-5-5', 900, $facts['cost'] ?? 0.25], ['implementer', 'claude-sonnet-5-5', 1300, 0.5], ['reviewer', 'claude-haiku-5-5', 401, 0.1]] as [$role, $model, $tokens, $cost]) {
        AgentThread::query()->create(['task_group_id' => $group->id, 'task_id' => $subtask->id, 'driver' => 't3', 'runtime_key' => 'x', 'external_id' => (string) Str::uuid(),
            'role' => $role, 'model' => $model, ...(($facts['unknown'] ?? false) ? ['cost_usd' => null]
                : ['tokens' => $tokens, 'input_tokens' => $tokens - 100, 'cached_input_tokens' => 70, 'cost_usd' => $cost])]);
    }
    foreach (($facts['approved'] ?? true) ? [TaskCommentType::Approved] : [TaskCommentType::ChangesRequested, TaskCommentType::Approved] as $type) {
        TaskComment::query()->create(['task_group_id' => $group->id, 'task_id' => $subtask->id, 'completion_attempt' => 1, 'type' => $type, 'body' => 'Verdict.', 'author' => 'reviewer', 'posted_at' => now()]);
    }
    TaskCheck::query()->create(['task_id' => $subtask->id, 'kind' => TaskCheckKind::Handoff, 'status' => ($facts['passed'] ?? true) ? TaskCheckStatus::Passed : TaskCheckStatus::Failed,
        'pid' => 1, 'process_started' => 'x', 'head_before' => str_repeat('a', 40), 'tree_before' => str_repeat('2', 40), 'head_after' => str_repeat('b', 40),
        'tree_after' => str_repeat('4', 40), 'commit_sha' => str_repeat('b', 40), 'exit_code' => 0, 'output' => '', 'started_at' => now(), 'finished_at' => now()]);
    if (($facts['cause'] ?? null) !== null) {
        insightQuestion($group, $subtask, $facts['cause']);
    }

    return $group;
}

function insightQuestion(Task $group, Task $subtask, QuestionCause $cause): TaskQuestion
{
    $comment = TaskComment::query()->create(['task_group_id' => $group->id, 'task_id' => $subtask->id, 'completion_attempt' => 1,
        'type' => TaskCommentType::ReadyForReview, 'body' => 'Blocked.', 'author' => 'implementer', 'posted_at' => now()]);

    return TaskQuestion::query()->create(['task_id' => $group->id, 'subtask_id' => $subtask->id, 'attempt' => 1, 'asked_by' => QuestionAsker::Implementer,
        'question' => 'Which?', 'status' => QuestionStatus::Answered, 'cause' => $cause, 'consult' => true, 'asked_at' => now(), 'opened_comment_id' => $comment->id]);
}

/** Two Projects with groups in the current and previous periods of every report, and across the twelve trend weeks. */
function insightWorld(): array
{
    test()->travelTo(CarbonImmutable::parse('2026-11-19 12:00:00'));
    app(TaskExtensionState::class)->enable();
    $app = insightProject('app');
    $web = insightProject('web');
    GatewayRelease::query()->create(['sha' => str_repeat('c', 40), 'trigger' => 'auto', 'outcome' => 'verified', 'phases' => [], 'duration_ms' => 0, 'created_at' => now()->subDays(20)]);
    GatewayRelease::query()->create(['sha' => str_repeat('d', 40), 'trigger' => 'auto', 'outcome' => 'verified', 'phases' => [], 'duration_ms' => 0, 'created_at' => now()->subDays(3)]);
    $facts = [
        [], ['merged' => false], ['fixup' => true, 'cause' => QuestionCause::Scope], ['approved' => false, 'passed' => false], ['cost' => null],
        ['deployed' => false, 'cause' => QuestionCause::BriefUnclear], ['opened' => false], ['merged' => false, 'cost' => 0.0],
    ];
    foreach ([0.1, 0.4, 1.2, 2.5, 3.3, 5.9, 6.5, 8.0, 11.0, 13.6, 18.0, 25.0, 29.5, 34.0, 45.0, 59.5, 70.0, 82.0] as $index => $daysAgo) {
        insightGroup($index % 3 === 0 ? $web : $app, $daysAgo, $facts[$index % count($facts)]);
    }
    // Before the trend, and still running: neither counts.
    insightGroup($app, 90);
    Task::topLevel()->create(['project_id' => $app->id, 'title' => 'Running', 'brief' => 'Brief', 'status' => TaskGroupStatus::Running]);

    return [$app, $web];
}

function insightPeer(): void
{
    test()->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);
    $gateway = Node::withoutEvents(fn () => Node::query()->create(['name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.2', 'public_ssh_host' => '10.44.0.2', 'user' => 'orbit']));
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
}

it('answers from the stored totals exactly what the report computes from the source tables, for every period and scope', function (?string $scope): void {
    [$app] = insightWorld();
    $projectId = $scope === null ? null : $app->id;
    DB::table('task_insight_records')->delete();
    Artisan::call('task:insights-backfill');
    Artisan::call('task:insights-refresh');
    insightPeer();

    foreach (array_keys(TaskInsights::Periods) as $period) {
        $report = app(TaskInsights::class)->report($period, $projectId, CarbonImmutable::now())->toArray();
        $stored = app(TaskInsightTotals::class)->read($period, $projectId, CarbonImmutable::now());
        expect($report['current']['tasks'])->toBeGreaterThan(0)
            ->and($stored)->toBe($report);
        $query = '/api/v1/task-insights?period='.$period.($projectId === null ? '' : '&project_id='.$projectId);
        expect($this->getJson($query)->assertOk()->json('data'))->toBe(json_decode((string) json_encode($report), true));
    }
})->with(['all Projects' => null, 'one Project' => 'app']);

it('keeps the record of a group current as it ends, merges, deploys, gets verdicts, handoff checks, questions and tokens', function (): void {
    test()->travelTo(CarbonImmutable::parse('2026-11-19 12:00:00'));
    $project = insightProject('app');
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Group', 'brief' => 'Brief', 'status' => TaskGroupStatus::Running]);
    $subtask = $group->tasks()->create(['title' => 'Subtask', 'brief' => 'Brief', 'position' => 1]);
    $thread = AgentThread::query()->create(['task_group_id' => $group->id, 'task_id' => $subtask->id, 'driver' => 't3', 'runtime_key' => 'x',
        'external_id' => (string) Str::uuid(), 'role' => 'implementer', 'model' => 'claude-opus-5-5', 'tokens' => 10]);
    $facts = static function () use ($group): ?array {
        $facts = DB::table('task_insight_records')->where('task_id', $group->id)->value('facts');

        return is_string($facts) ? TaskInsightRecorder::row($facts) : null;
    };
    expect($facts())->toBeNull();

    $group->update(['status' => TaskGroupStatus::Completed, 'ended_at' => now()]);
    expect($facts())->toMatchArray(['merged' => false, 'tokens' => 10, 'model' => 'claude-opus-5-5', 'project_id' => $project->id]);

    $group->forceFill(['ready_at' => now()->subHour(), 'merged_at' => now()])->save();
    expect($facts())->toMatchArray(['merged' => true, 'lead' => 3_600_000, 'delivery' => null]);

    $group->forceFill(['deployed_at' => now()->addMinutes(30)])->save();
    expect($facts())->toMatchArray(['deploy_wait' => 1_800_000, 'delivery' => 5_400_000]);

    TaskComment::query()->create(['task_group_id' => $group->id, 'task_id' => $subtask->id, 'completion_attempt' => 1, 'type' => TaskCommentType::ChangesRequested, 'body' => 'No.', 'author' => 'reviewer', 'posted_at' => now()]);
    expect($facts())->toMatchArray(['verdict_subtasks' => 1, 'first_approved' => 0, 'verdicts' => 1]);

    TaskCheck::query()->create(['task_id' => $subtask->id, 'kind' => TaskCheckKind::Handoff, 'status' => TaskCheckStatus::Passed, 'pid' => 1, 'process_started' => 'x',
        'head_before' => str_repeat('a', 40), 'tree_before' => str_repeat('2', 40), 'head_after' => str_repeat('b', 40), 'tree_after' => str_repeat('4', 40),
        'commit_sha' => str_repeat('b', 40), 'exit_code' => 0, 'output' => '', 'started_at' => now(), 'finished_at' => now()]);
    expect($facts())->toMatchArray(['handoff_subtasks' => 1, 'handoff_passed' => 1]);

    insightQuestion($group, $subtask, QuestionCause::Scope);
    expect($facts())->toMatchArray(['questions' => 1, 'causes' => ['scope' => 1]]);

    $thread->update(['tokens' => 250]);
    expect($facts())->toMatchArray(['tokens' => 250]);

    $release = GatewayRelease::query()->create(['sha' => str_repeat('e', 40), 'trigger' => 'auto', 'outcome' => 'running', 'phases' => [], 'duration_ms' => 0]);
    $group->forceFill(['started_at' => now()->addMinute()])->saveQuietly();
    expect($facts())->toMatchArray(['release' => null]);
    $release->update(['outcome' => 'verified']);
    expect($facts())->toMatchArray(['release' => str_repeat('e', 40)]);

    $group->update(['status' => TaskGroupStatus::Running]);
    expect($facts())->toBeNull();
});

it('never fails the write that fires a record, and the next refresh records the group', function (): void {
    test()->travelTo(CarbonImmutable::parse('2026-11-19 12:00:00'));
    app(TaskExtensionState::class)->enable();
    $project = insightProject('app');
    Schema::rename('task_insight_records', 'task_insight_records_away');

    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Group', 'brief' => 'Brief', 'status' => TaskGroupStatus::Completed, 'ended_at' => now(), 'merged_at' => now()]);

    expect($group->exists)->toBeTrue();
    Schema::rename('task_insight_records_away', 'task_insight_records');
    expect(DB::table('task_insight_records')->count())->toBe(0);
    test()->travel(1)->minutes();
    expect(Artisan::call('task:insights-refresh'))->toBe(0)
        ->and(DB::table('task_insight_records')->where('task_id', $group->id)->exists())->toBeTrue()
        ->and(app(TaskInsightTotals::class)->read('7d', $project->id, CarbonImmutable::now())['current']['merged'])->toBe(1);
});

it('re-records on the refresh a group whose source rows a query-builder write changed after its record', function (): void {
    [$app] = insightWorld();
    $group = Task::topLevel()->where('project_id', $app->id)->orderBy('id')->firstOrFail();
    test()->travel(1)->minutes();
    DB::table('agent_threads')->where('task_group_id', $group->id)->update(['tokens' => 1_000_000, 'updated_at' => now()]);

    Artisan::call('task:insights-refresh');

    $facts = TaskInsightRecorder::row((string) DB::table('task_insight_records')->where('task_id', $group->id)->value('facts'));
    expect($facts['tokens'])->toBe(3_000_000);
});

it('backfills every ended group once, and a second run rewrites the same records', function (): void {
    insightWorld();
    DB::table('task_insight_records')->delete();

    Artisan::call('task:insights-backfill');
    $first = DB::table('task_insight_records')->orderBy('task_id')->pluck('facts', 'task_id')->all();
    Artisan::call('task:insights-backfill');

    // Nineteen ended groups, including the one older than the trend; the running group has no record.
    expect($first)->toHaveCount(19)
        ->and(DB::table('task_insight_records')->orderBy('task_id')->pluck('facts', 'task_id')->all())->toBe($first);
});

it('refreshes the stored totals of all Projects and each Project every five minutes, from the records only', function (): void {
    [$app, $web] = insightWorld();
    // A record written in the second of its source rows counts as changed, so the clock moves past the fixture's second first.
    test()->travel(1)->seconds();
    Artisan::call('task:insights-refresh');
    expect(DB::table('task_insight_totals')->count())->toBe(3 * count(TaskInsights::Periods));

    $queries = [];
    DB::listen(static function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    Artisan::call('task:insights-refresh');

    // Nothing changed, so the refresh reads the stale check and the records, never a group's source rows.
    expect(array_filter($queries, static fn (string $sql): bool => ! str_contains($sql, 'left join "task_insight_records"')
        && preg_match('/from "(tasks|agent_threads|task_comments|task_checks|task_questions)"/', $sql) === 1))->toBe([]);
    Artisan::call('schedule:list');
    expect(Artisan::output())->toMatch('/\*\/5\s+\*\s+\*\s+\*\s+\*\s+php artisan task:insights-refresh/');
});

it('answers the endpoint from the stored totals without a task query, and computes a missing scope once from the records', function (): void {
    [$app] = insightWorld();
    Artisan::call('task:insights-refresh');
    DB::table('task_insight_totals')->where('scope', 'project:'.$app->id)->delete();
    insightPeer();
    // The fixture's own task change notices go out now, as at the end of the request that made them, not inside the read below.
    app(TaskBroadcasts::class)->flush();
    $queries = [];
    DB::listen(static function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    // Takes the log as an argument: an arrow function would capture the empty log it was created with.
    $source = static fn (array $queries): array => array_values(array_filter($queries, static fn (string $sql): bool => preg_match('/from "(tasks|agent_threads|task_comments|task_checks|task_questions|gateway_releases)"/', $sql) === 1));

    $first = $this->getJson('/api/v1/task-insights?period=7d&project_id='.$app->id)->assertOk()->json('data');
    expect($source($queries))->toBe([])->and(DB::table('task_insight_totals')->where('scope', 'project:'.$app->id)->count())->toBe(1)
        ->and($first)->toBe(json_decode((string) json_encode(app(TaskInsights::class)->report('7d', $app->id, CarbonImmutable::now())->toArray()), true));

    $queries = [];
    expect($this->getJson('/api/v1/task-insights?period=7d&project_id='.$app->id)->assertOk()->json('data'))->toBe($first)
        ->and($source($queries))->toBe([])
        ->and(array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'task_insight_records')))->toBe([]);
});

it('answers from the source tables and stores nothing before the first refresh has completed the records', function (): void {
    [$app] = insightWorld();
    DB::table('task_insight_records')->delete();
    insightPeer();

    foreach ([null, $app->id] as $projectId) {
        $report = app(TaskInsights::class)->report('7d', $projectId, CarbonImmutable::now())->toArray();
        expect($report['current']['tasks'])->toBeGreaterThan(0)
            ->and($this->getJson('/api/v1/task-insights?period=7d'.($projectId === null ? '' : '&project_id='.$projectId))->assertOk()->json('data'))
            ->toBe(json_decode((string) json_encode($report), true));
    }
    expect(DB::table('task_insight_totals')->count())->toBe(0);

    Artisan::call('task:insights-refresh');
    expect(app(TaskInsightTotals::class)->read('7d', $app->id, CarbonImmutable::now()))->toBe(app(TaskInsights::class)->report('7d', $app->id, CarbonImmutable::now())->toArray());
});

it('stores one report per scope and period when another read stores the same missing scope first', function (?string $scope): void {
    [$app] = insightWorld();
    Artisan::call('task:insights-refresh');
    $projectId = $scope === null ? null : $app->id;
    $key = $projectId === null ? 'all' : 'project:'.$projectId;
    $stored = DB::table('task_insight_totals')->where('scope', $key)->where('period', '7d')->first();
    DB::table('task_insight_totals')->where('scope', $key)->delete();
    $raced = false;
    // The other read stores the scope right after this read found it missing.
    DB::listen(static function ($query) use (&$raced, $stored): void {
        if (! $raced && str_contains($query->sql, 'select "payload" from "task_insight_totals"')) {
            $raced = true;
            DB::table('task_insight_totals')->insert((array) $stored);
        }
    });

    $report = app(TaskInsightTotals::class)->read('7d', $projectId, CarbonImmutable::now());

    expect($raced)->toBeTrue()
        ->and($report)->toBe(app(TaskInsights::class)->report('7d', $projectId, CarbonImmutable::now())->toArray())
        ->and(DB::table('task_insight_totals')->where('scope', $key)->where('period', '7d')->count())->toBe(1);
})->with(['all Projects' => null, 'one Project' => 'app']);

it('aggregates every record of a renamed Project under its current code', function (): void {
    [$app] = insightWorld();
    Artisan::call('task:insights-refresh');
    $app->forceFill(['code' => 'NEW'])->saveQuietly();
    test()->travel(1)->minutes();
    insightGroup($app, 0.01);
    Artisan::call('task:insights-refresh');

    foreach ([null, $app->id] as $projectId) {
        $report = app(TaskInsights::class)->report('30d', $projectId, CarbonImmutable::now())->toArray();
        expect(app(TaskInsightTotals::class)->read('30d', $projectId, CarbonImmutable::now())['breakdowns']['project'])->toBe($report['breakdowns']['project']);
    }
    expect(array_column($report['breakdowns']['project'], 'label'))->toBe(['NEW']);
});

it('recovers on the refresh a write whose record rewrite failed, even in the second of its record', function (string $case): void {
    [$app] = insightWorld();
    $group = Task::topLevel()->where('project_id', $app->id)->orderByDesc('ended_at')->firstOrFail();
    $subtask = $group->tasks()->orderBy('id')->firstOrFail();
    if ($case === 'handoff') {
        TaskCheck::query()->where('task_id', $subtask->id)->delete();
    }
    test()->travel(1)->seconds();
    Artisan::call('task:insights-refresh');
    // The record is written in the same second as the write below.
    app(TaskInsightRecorder::class)->record($group->id);
    $facts = static fn (): array => TaskInsightRecorder::row((string) DB::table('task_insight_records')->where('task_id', $group->id)->value('facts'));
    $before = $facts();

    // The write succeeds; its observer does not run, as when the record rewrite fails.
    Model::withoutEvents(function () use ($case, $group, $subtask): void {
        match ($case) {
            'handoff' => TaskCheck::query()->create(['task_id' => $subtask->id, 'kind' => TaskCheckKind::Handoff, 'status' => TaskCheckStatus::Passed, 'pid' => 1,
                'process_started' => 'x', 'head_before' => str_repeat('a', 40), 'tree_before' => str_repeat('2', 40), 'head_after' => str_repeat('b', 40),
                'tree_after' => str_repeat('4', 40), 'commit_sha' => str_repeat('b', 40), 'exit_code' => 0, 'output' => '', 'started_at' => now(), 'finished_at' => now()]),
            'release' => new GatewayRelease()->forceFill(['sha' => str_repeat('f', 40), 'trigger' => 'auto', 'outcome' => 'verified', 'phases' => [], 'duration_ms' => 0,
                'created_at' => CarbonImmutable::parse((string) $group->started_at)->subMinute()])->save(),
            'tokens' => AgentThread::query()->where('task_group_id', $group->id)->firstOrFail()->update(['tokens' => 5_000]),
        };
    });
    Artisan::call('task:insights-refresh');

    $key = ['handoff' => 'handoff_passed', 'release' => 'release', 'tokens' => 'tokens'][$case];
    $source = app(TaskInsights::class)->rowsOf(Task::topLevel()->whereKey($group->id))[0];
    expect($source[$key])->not->toBe($before[$key])
        ->and($facts()[$key])->toBe($source[$key]);
})->with(['handoff', 'release', 'tokens']);

it('stores the 24h report on each refresh with the hour window of the refresh time, and serves it until the next refresh', function (): void {
    test()->travelTo(CarbonImmutable::parse('2026-11-19 12:07:00', 'UTC'));
    app(TaskExtensionState::class)->enable();
    $app = insightProject('app');
    // The refresh re-records each group after this write, which bypasses the observers.
    $ended = static fn (string $at, bool $merged): int => DB::table('tasks')->where('id', insightGroup($app, 0, ['merged' => $merged])->id)
        ->update(['ended_at' => $at, 'merged_at' => $merged ? $at : null, 'updated_at' => now()]);
    $ended('2026-11-19 12:01:00', true);  // the current hour
    $ended('2026-11-18 13:00:00', false); // the first hour
    $ended('2026-11-18 12:59:59', true);  // the previous period
    test()->travel(1)->seconds();
    Artisan::call('task:insights-refresh');
    insightPeer();
    $stored = static fn (string $period): array => json_decode((string) DB::table('task_insight_totals')->where('scope', 'all')->where('period', $period)->value('payload'), true);

    $day = $stored('24h');
    expect([$day['period'], $day['from'], $day['to']])->toBe(['24h', '2026-11-18T13:00:00+00:00', '2026-11-19T13:00:00+00:00'])
        ->and([$day['current']['tasks'], $day['current']['merged'], $day['previous']['tasks']])->toBe([2, 1, 1])
        ->and(array_sum(array_column($day['breakdowns']['project'], 'tasks')))->toBe(2)
        ->and($day['daily'])->toBe([])
        ->and($day['hourly'])->toHaveCount(24)
        ->and($day['hourly'][0])->toMatchArray(['hour' => '2026-11-18T13:00:00+00:00', 'merged' => 0, 'not_merged' => 1])
        ->and($day['hourly'][23])->toMatchArray(['hour' => '2026-11-19T12:00:00+00:00', 'merged' => 1, 'not_merged' => 0])
        ->and([$stored('7d')['hourly'], count($stored('7d')['daily']), $stored('30d')['hourly'], count($stored('30d')['daily'])])->toBe([[], 7, [], 30])
        ->and(app(TaskInsightsExposition::class)->render())->toContain('orbit_task_insights_tasks{project="all",period="24h"} 2');

    // Between refreshes the endpoint serves the stored window, even past the hour.
    test()->travelTo(CarbonImmutable::parse('2026-11-19 13:02:00', 'UTC'));
    expect($this->getJson('/api/v1/task-insights?period=24h')->assertOk()->json('data'))->toBe(json_decode((string) json_encode($day), true));

    Artisan::call('task:insights-refresh');
    $next = $this->getJson('/api/v1/task-insights?period=24h')->assertOk()->json('data');
    expect([$next['from'], $next['to'], $next['current']['tasks']])->toBe(['2026-11-18T14:00:00+00:00', '2026-11-19T14:00:00+00:00', 1])
        ->and($next['hourly'][22])->toMatchArray(['hour' => '2026-11-19T12:00:00+00:00', 'merged' => 1, 'not_merged' => 0]);
});

it('computes a stored report again when it lacks hourly buckets or its buckets lack the tile numbers', function (string $period, string $stale): void {
    insightWorld();
    Artisan::call('task:insights-refresh');
    insightPeer();
    $stored = static fn (): array => json_decode((string) DB::table('task_insight_totals')->where('scope', 'all')->where('period', $period)->value('payload'), true);
    $old = $stored();
    $fresh = json_decode((string) json_encode(app(TaskInsights::class)->report($period, null, CarbonImmutable::now())->toArray()), true);
    // The report as an earlier Gateway stored it, with numbers the records no longer give.
    $old['current']['tasks'] = 999;
    if ($stale === 'no hourly') {
        unset($old['hourly']);
    } else {
        foreach (['daily', 'hourly'] as $list) {
            $old[$list] = array_map(static fn (array $bucket): array => Arr::only($bucket, ['date', 'hour', 'merged', 'not_merged']), $old[$list]);
        }
    }
    DB::table('task_insight_totals')->where('scope', 'all')->where('period', $period)->update(['payload' => json_encode($old)]);

    expect($this->getJson('/api/v1/task-insights?period='.$period)->assertOk()->json('data'))->toBe($fresh)
        ->and($stored())->toBe($fresh);
})->with(['24h', '7d', '30d'])->with(['no hourly', 'buckets without tile numbers']);

/** A merged group, unless `$facts` says otherwise, that ended at `$ended` UTC, `$leadHours` after it was ready. */
function insightBucketGroup(Project $project, string $ended, int $leadHours, array $facts = []): Task
{
    $group = insightGroup($project, 0, $facts);
    $end = CarbonImmutable::parse($ended, 'UTC');
    $merged = $facts['merged'] ?? true;
    $time = static fn (?CarbonImmutable $at): ?string => $at?->format('Y-m-d H:i:s');
    // The refresh re-records the group after this write, which bypasses the observers.
    DB::table('tasks')->where('id', $group->id)->update(['ended_at' => $time($end), 'merged_at' => $time($merged ? $end : null),
        'ready_at' => $time($end->subHours($leadHours)), 'started_at' => $time($end->subHours($leadHours)), 'settled_at' => $time($end->subMinutes(30)),
        'pr_opened_at' => $time($end->subMinutes(20)), 'deployed_at' => $time($merged ? $end->addMinutes(37) : null), 'updated_at' => now()]);

    return $group;
}

it('gives each hourly and daily bucket the tile numbers of its own tasks alone, with exact medians and unknown tokens null', function (): void {
    test()->travelTo(CarbonImmutable::parse('2026-11-19 12:07:00', 'UTC'));
    app(TaskExtensionState::class)->enable();
    $app = insightProject('app');
    $nine = [
        insightBucketGroup($app, '2026-11-19 09:05:00', 2),
        insightBucketGroup($app, '2026-11-19 09:20:00', 6, ['fixup' => true, 'approved' => false]),
        insightBucketGroup($app, '2026-11-19 09:40:00', 3),
        insightBucketGroup($app, '2026-11-19 09:50:00', 4, ['merged' => false]),
    ];
    insightBucketGroup($app, '2026-11-19 11:30:00', 1, ['unknown' => true]);
    insightBucketGroup($app, '2026-11-18 08:00:00', 5);
    test()->travel(1)->seconds();
    Artisan::call('task:insights-refresh');
    insightPeer();
    $tiles = ['tokens', 'tokens_per_merged_pr', 'wasted_share', 'lead_time_ms', 'delivery_time_ms', 'pr_first_pass', 'pr_merge_wait_ms', 'review_rounds'];
    $summary = static fn (array $groups): array => Arr::only(app(TaskInsights::class)
        ->aggregate('24h', app(TaskInsights::class)->rowsOf(Task::topLevel()->whereKey(array_map(static fn (Task $group): int => $group->id, $groups))), CarbonImmutable::now())
        ->current->toArray(), $tiles);
    $hourly = $this->getJson('/api/v1/task-insights?period=24h')->assertOk()->json('data.hourly');
    $daily = $this->getJson('/api/v1/task-insights?period=7d')->assertOk()->json('data.daily');

    // Hour 09:00 is bucket 20 of the window from 2026-11-18 13:00; its medians are those of its own tasks: 3 of 2, 3 and 6 hours.
    expect($hourly[20])->toMatchArray(['hour' => '2026-11-19T09:00:00+00:00', 'merged' => 3, 'not_merged' => 1, ...$summary($nine)])
        ->and([$hourly[20]['tokens'], $hourly[20]['tokens_per_merged_pr'], $hourly[20]['lead_time_ms'], $hourly[20]['delivery_time_ms'], $hourly[20]['pr_merge_wait_ms']])
        ->toBe([4 * 2601, intdiv(4 * 2601, 3), 3 * 3_600_000, 3 * 3_600_000 + 37 * 60_000, 20 * 60_000])
        ->and([$hourly[20]['pr_first_pass'], $hourly[20]['review_rounds'], $hourly[20]['wasted_share']])->toEqual([2 / 3, 5 / 4, (3 * 900 + 2601) / (4 * 2601)])
        // An hour with no task: merged 0 and every number null.
        ->and($hourly[21])->toBe(['hour' => '2026-11-19T10:00:00+00:00', 'merged' => 0, 'not_merged' => 0, ...array_fill_keys($tiles, null)])
        // The hour whose only task reported no tokens: its token numbers stay null, its times are known.
        ->and($hourly[22])->toMatchArray(['merged' => 1, 'tokens' => null, 'tokens_per_merged_pr' => null, 'wasted_share' => null, 'lead_time_ms' => 3_600_000])
        // The day holds the five tasks of 2026-11-19 alone: the median of 1, 2, 3 and 6 hours is 2, not the 3 of hour 09:00.
        ->and($daily[6])->toMatchArray(['date' => '2026-11-19', 'merged' => 4, 'not_merged' => 1, 'lead_time_ms' => 2 * 3_600_000, 'tokens' => 4 * 2601])
        ->and($daily[5])->toMatchArray(['date' => '2026-11-18', 'merged' => 1, 'lead_time_ms' => 5 * 3_600_000]);
});

it('gives every bucket of each period the tile numbers', function (string $period, string $list, int $count): void {
    test()->travelTo(CarbonImmutable::parse('2026-11-19 12:07:00', 'UTC'));
    app(TaskExtensionState::class)->enable();
    insightBucketGroup(insightProject('app'), '2026-11-19 09:05:00', 2);
    insightPeer();

    $buckets = $this->getJson('/api/v1/task-insights?period='.$period)->assertOk()->json('data.'.$list);

    expect($buckets)->toHaveCount($count)
        ->and(array_unique(array_map(static fn (array $bucket): string => implode(',', array_keys($bucket)), $buckets)))->toBe([
            ($list === 'daily' ? 'date' : 'hour').',merged,not_merged,tokens,tokens_per_merged_pr,wasted_share,lead_time_ms,delivery_time_ms,pr_first_pass,pr_merge_wait_ms,review_rounds',
        ]);
})->with([['24h', 'hourly', 24], ['7d', 'daily', 7], ['30d', 'daily', 30]]);

it('keeps unknown tokens null in rows, records, summaries, weeks and breakdowns, and counts only the known ones in a mix', function (): void {
    test()->travelTo(CarbonImmutable::parse('2026-11-19 12:00:00'));
    app(TaskExtensionState::class)->enable();
    $app = insightProject('app');
    $unknown = insightGroup($app, 0.5, ['unknown' => true]);
    $report = static fn (): array => app(TaskInsights::class)->report('7d', null, CarbonImmutable::now())->toArray();

    $row = app(TaskInsights::class)->rowsOf(Task::topLevel()->whereKey($unknown->id))[0];
    expect(Arr::only($row, ['tokens', 'cost', 'input', 'cached', 'wasted']))->toBe(['tokens' => null, 'cost' => null, 'input' => null, 'cached' => null, 'wasted' => null]);
    Artisan::call('task:insights-refresh');
    $facts = json_decode((string) DB::table('task_insight_records')->where('task_id', $unknown->id)->value('facts'), true);
    expect(Arr::only($facts, ['tokens', 'input', 'cached', 'wasted']))->toBe(['tokens' => null, 'input' => null, 'cached' => null, 'wasted' => null]);
    $nulls = ['tokens' => null, 'cost_usd' => null, 'tokens_per_merged_pr' => null, 'cost_per_merged_pr' => null, 'wasted_share' => null, 'cache_hit' => null, 'tokens_per_hundred_lines' => null];
    $stored = app(TaskInsightTotals::class)->read('7d', null, CarbonImmutable::now());
    expect(Arr::only($stored['current'], array_keys($nulls)))->toBe($nulls)
        ->and($stored['current']['merged'])->toBe(1)
        ->and(array_column($stored['weekly'], 'tokens_per_merged_pr'))->each->toBeNull()
        ->and($stored['breakdowns']['model'][0]['tokens_per_merged_pr'])->toBeNull()
        ->and($stored)->toBe($report());

    // Threads without tokens that still report an input split and a cost count for nothing: the group's numbers stay null.
    AgentThread::query()->where('task_group_id', $unknown->id)->update(['input_tokens' => 100, 'cached_input_tokens' => 200, 'cost_usd' => 0.75]);
    $row = static fn (): array => Arr::only(app(TaskInsights::class)->rowsOf(Task::topLevel()->whereKey($unknown->id))[0], ['tokens', 'cost', 'input', 'cached', 'wasted']);
    expect($row())->toBe(['tokens' => null, 'cost' => null, 'input' => null, 'cached' => null, 'wasted' => null]);

    // A known group next to the unknown one: every token number counts the known group alone.
    insightGroup($app, 1.5);
    expect(Arr::only($report()['current'], array_keys($nulls)))->toBe(['tokens' => 2601, 'cost_usd' => 0.85, 'tokens_per_merged_pr' => 2601, 'cost_per_merged_pr' => 0.85,
        'wasted_share' => 900 / 2601, 'cache_hit' => 210 / 2511, 'tokens_per_hundred_lines' => intdiv(2601 * 100, 133)]);

    // One thread that reports tokens among threads that do not: the group counts that thread alone, its split and its cost.
    $haiku = AgentThread::query()->where('task_group_id', $unknown->id)->where('model', 'claude-haiku-5-5');
    $haiku->update(['tokens' => 40, 'input_tokens' => 30, 'cached_input_tokens' => 10, 'cost_usd' => 0.1]);
    expect($row())->toBe(['tokens' => 40, 'cost' => 0.1, 'input' => 30, 'cached' => 10, 'wasted' => 0]);
    // A token-reporting thread without a cached count adds no split: input and cached match the threads that report both.
    $haiku->update(['cached_input_tokens' => null]);
    expect($row())->toBe(['tokens' => 40, 'cost' => 0.1, 'input' => null, 'cached' => null, 'wasted' => 0]);
    // A token-reporting thread without a cost leaves the group's cost unknown.
    $haiku->update(['cost_usd' => null]);
    expect($row())->toBe(['tokens' => 40, 'cost' => null, 'input' => null, 'cached' => null, 'wasted' => 0]);
});

it('drops the records and totals stored with 0 for unknown tokens, so reports come from the source tables until the refresh records them again', function (): void {
    [$app] = insightWorld();
    Artisan::call('task:insights-refresh');
    DB::table('task_insight_records')->update(['facts' => DB::raw("json_set(facts, '$.tokens', 0)")]);
    $recorded = DB::table('task_insight_records')->count();

    (require database_path('migrations/2027_03_15_000000_rerecord_task_insights_with_unknown_tokens.php'))->up();

    expect(DB::table('task_insight_records')->count())->toBe(0)->and(DB::table('task_insight_totals')->count())->toBe(0)
        ->and(app(TaskInsightTotals::class)->read('7d', $app->id, CarbonImmutable::now()))->toBe(app(TaskInsights::class)->report('7d', $app->id, CarbonImmutable::now())->toArray());
    Artisan::call('task:insights-refresh');
    expect(DB::table('task_insight_records')->count())->toBe($recorded)
        ->and(app(TaskInsightTotals::class)->read('7d', null, CarbonImmutable::now()))->toBe(app(TaskInsights::class)->report('7d', null, CarbonImmutable::now())->toArray());
});
