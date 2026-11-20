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
 * @param  array{merged?: bool, fixup?: bool, cost?: ?float, approved?: bool, passed?: bool, cause?: ?QuestionCause, deployed?: bool, opened?: bool}  $facts
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
            'role' => $role, 'model' => $model, 'tokens' => $tokens, 'input_tokens' => $tokens - 100, 'cached_input_tokens' => 70, 'cost_usd' => $cost]);
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
        ->and($day['hourly'][0])->toBe(['hour' => '2026-11-18T13:00:00+00:00', 'merged' => 0, 'not_merged' => 1])
        ->and($day['hourly'][23])->toBe(['hour' => '2026-11-19T12:00:00+00:00', 'merged' => 1, 'not_merged' => 0])
        ->and([$stored('7d')['hourly'], count($stored('7d')['daily']), $stored('30d')['hourly'], count($stored('30d')['daily'])])->toBe([[], 7, [], 30])
        ->and(app(TaskInsightsExposition::class)->render())->toContain('orbit_task_insights_tasks{project="all",period="24h"} 2');

    // Between refreshes the endpoint serves the stored window, even past the hour.
    test()->travelTo(CarbonImmutable::parse('2026-11-19 13:02:00', 'UTC'));
    expect($this->getJson('/api/v1/task-insights?period=24h')->assertOk()->json('data'))->toBe(json_decode((string) json_encode($day), true));

    Artisan::call('task:insights-refresh');
    $next = $this->getJson('/api/v1/task-insights?period=24h')->assertOk()->json('data');
    expect([$next['from'], $next['to'], $next['current']['tasks']])->toBe(['2026-11-18T14:00:00+00:00', '2026-11-19T14:00:00+00:00', 1])
        ->and($next['hourly'][22])->toBe(['hour' => '2026-11-19T12:00:00+00:00', 'merged' => 1, 'not_merged' => 0]);
});

it('serves a 7d or 30d report stored before hourly buckets with an empty hourly list and its stored numbers', function (?string $scope): void {
    [$app] = insightWorld();
    Artisan::call('task:insights-refresh');
    insightPeer();
    $key = $scope === null ? 'all' : 'project:'.$app->id;
    $query = static fn (?string $period): string => '/api/v1/task-insights?'.http_build_query(array_filter(['period' => $period, 'project_id' => $scope === null ? null : $app->id]));
    $refreshes = DB::table('task_insight_totals')->max('computed_at');

    foreach (['7d', '30d'] as $period) {
        // The report as #4116 stored it: no hourly key, and numbers the source tables no longer give.
        $old = json_decode((string) DB::table('task_insight_totals')->where('scope', $key)->where('period', $period)->value('payload'), true);
        unset($old['hourly']);
        $old['current']['tasks'] = 999;
        DB::table('task_insight_totals')->where('scope', $key)->where('period', $period)->update(['payload' => json_encode($old)]);

        $expected = [...$old, 'hourly' => []];
        foreach ($period === '30d' ? [$period, null] : [$period] as $requested) {
            $data = $this->getJson($query($requested))->assertOk()->json('data');
            expect(array_keys($data))->toBe(['period', 'from', 'to', 'current', 'previous', 'weekly', 'daily', 'hourly', 'breakdowns'])
                ->and($data)->toEqual($expected);
        }
    }
    expect(DB::table('task_insight_totals')->max('computed_at'))->toBe($refreshes);
})->with(['all Projects' => null, 'one Project' => 'app']);

it('computes a stored 24h report again when it lacks its 24 hourly buckets', function (): void {
    insightWorld();
    Artisan::call('task:insights-refresh');
    insightPeer();
    $old = json_decode((string) DB::table('task_insight_totals')->where('scope', 'all')->where('period', '24h')->value('payload'), true);
    $fresh = json_decode((string) json_encode(app(TaskInsights::class)->report('24h', null, CarbonImmutable::now())->toArray()), true);
    unset($old['hourly']);
    DB::table('task_insight_totals')->where('scope', 'all')->where('period', '24h')->update(['payload' => json_encode($old)]);

    expect($this->getJson('/api/v1/task-insights?period=24h')->assertOk()->json('data'))->toBe($fresh)
        ->and(json_decode((string) DB::table('task_insight_totals')->where('scope', 'all')->where('period', '24h')->value('payload'), true)['hourly'])->toHaveCount(24);
});
