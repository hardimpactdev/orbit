<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskExtensionState;
use App\Models\AgentThread;
use App\Models\GatewayRelease;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskComment;
use App\Models\TaskQuestion;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-27 12:00:00');
    $gateway = $this->markAsGateway(Node::query()->create([
        'name' => 'insights-gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.91',
        'wireguard_ip' => '10.44.0.91',
    ]));
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
    app(TaskExtensionState::class)->enable();
    $this->project = Project::query()->create([
        'name' => 'Insights', 'code' => 'INS', 'slug' => 'insights',
        'repository_url' => 'git@example.test:insights.git', 'default_branch' => 'main',
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * An ended task with one implementer subtask and its thread.
 *
 * @param  array<string, mixed>  $group
 * @param  array<string, mixed>  $thread
 * @return array{0: Task, 1: Task, 2: AgentThread}
 */
function insights_task(Project $project, string $status, string $ended, array $group = [], array $thread = []): array
{
    $merged = ($group['merged'] ?? false) === true;
    unset($group['merged']);
    $task = Task::query()->forceCreate([
        'project_id' => $project->id, 'title' => 'Feature', 'brief' => 'Brief.', 'status' => $status,
        'ended_at' => CarbonImmutable::parse($ended), 'merged_at' => $merged ? CarbonImmutable::parse($ended) : null,
        'implementer_model' => 'model-a', ...$group,
    ]);
    $subtask = Task::query()->create(['parent_id' => $task->id, 'position' => 1, 'title' => 'Step', 'brief' => 'Step.', 'status' => 'completed']);
    $agent = AgentThread::query()->create([
        'task_group_id' => $task->id, 'task_id' => $subtask->id, 'role' => 'implementer', 'driver' => 'pi',
        'external_id' => 'thread-'.$subtask->id, 'runtime_key' => 'node:1', 'tokens' => 1_000, 'cost_usd' => 1.0, ...$thread,
    ]);
    $subtask->update(['implementer_agent_thread_id' => $agent->id]);

    return [$task, $subtask, $agent];
}

function insights_verdict(Task $subtask, string $type): void
{
    TaskComment::query()->create([
        'task_group_id' => $subtask->parent_id, 'task_id' => $subtask->id, 'type' => $type, 'body' => $type,
        'author' => 'reviewer', 'posted_at' => now(),
    ]);
}

function insights_check(Task $subtask, string $status): void
{
    TaskCheck::query()->create([
        'task_id' => $subtask->id, 'kind' => 'handoff', 'status' => $status, 'pid' => 1, 'process_started' => '1',
        'head_before' => str_repeat('a', 40), 'tree_before' => str_repeat('b', 40), 'started_at' => now(),
    ]);
}

it('reports tokens per merged pull request, yield, first pass, waste, and cost', function (): void {
    insights_task($this->project, 'completed', '2026-10-20', ['merged' => true]);
    // A person merged this one: Orbit never set merge_status, but merged_at holds GitHub's merge time.
    [$personMerged, $step] = insights_task($this->project, 'completed', '2026-10-21', ['merged' => true]);
    Task::query()->create(['parent_id' => $personMerged->id, 'position' => 2, 'title' => 'Fix', 'brief' => 'Fix.', 'status' => 'completed', 'fixup_problem' => 'final-review']);
    AgentThread::query()->create([
        'task_group_id' => $personMerged->id, 'task_id' => $step->id, 'role' => 'implementer', 'driver' => 'pi',
        // A newer implementer thread replaces the first one, whose 1,000 tokens are waste.
        'external_id' => 'replacement', 'runtime_key' => 'node:1', 'tokens' => 500, 'cost_usd' => 0.5,
    ]);
    insights_task($this->project, 'cancelled', '2026-10-22', thread: ['tokens' => 1_500, 'cost_usd' => 1.5]);

    $current = $this->getJson('/api/v1/task-insights')->assertOk()->json('data.current');

    expect($current)->toMatchArray([
        'tasks' => 3, 'merged' => 2, 'tokens' => 4_000, 'tokens_per_merged_pr' => 2_000,
        'cost_usd' => 4.0, 'cost_per_merged_pr' => 2.0, 'yield' => 2 / 3, 'pr_first_pass' => 0.5,
        'wasted_share' => 2_500 / 4_000,
    ]);
});

it('counts only older implementer threads of a subtask as replaced, whatever drives the task', function (): void {
    // An external turn creates the thread without pointing the subtask at it.
    [, $subtask, $thread] = insights_task($this->project, 'completed', '2026-10-20', ['merged' => true, 'orchestration' => 'external']);
    $subtask->update(['implementer_agent_thread_id' => null]);
    expect($this->getJson('/api/v1/task-insights')->assertOk()->json('data.current.wasted_share'))->toEqual(0);

    AgentThread::query()->create([
        'task_group_id' => $thread->task_group_id, 'task_id' => $subtask->id, 'role' => 'implementer', 'driver' => 'pi',
        'external_id' => 'newer', 'runtime_key' => 'node:1', 'tokens' => 3_000, 'cost_usd' => 3.0,
    ]);

    expect($this->getJson('/api/v1/task-insights')->assertOk()->json('data.current.wasted_share'))->toBe(0.25);
});

it('leaves the cost unknown when a thread with tokens has no cost', function (): void {
    insights_task($this->project, 'completed', '2026-10-20', ['merged' => true]);
    insights_task($this->project, 'completed', '2026-10-21', ['merged' => true], ['cost_usd' => null]);

    $current = $this->getJson('/api/v1/task-insights')->assertOk()->json('data.current');

    expect($current['cost_usd'])->toBeNull()->and($current['cost_per_merged_pr'])->toBeNull()
        ->and($current['tokens_per_merged_pr'])->toBe(1_000);
});

it('splits the lead time and counts merged tasks without a ready time as excluded', function (): void {
    insights_task($this->project, 'completed', '2026-10-20', [
        'merged' => true, 'ready_at' => '2026-10-20 08:00:00', 'started_at' => '2026-10-20 09:00:00',
        'settled_at' => '2026-10-20 11:00:00', 'merged_at' => '2026-10-20 11:30:00',
    ]);
    insights_task($this->project, 'completed', '2026-10-21', ['merged' => true, 'merged_at' => '2026-10-21 10:00:00']);

    $current = $this->getJson('/api/v1/task-insights')->assertOk()->json('data.current');

    expect($current)->toMatchArray([
        'lead_time_ms' => 12_600_000, 'queue_ms' => 3_600_000, 'work_ms' => 7_200_000,
        'merge_wait_ms' => 1_800_000, 'excluded' => 1,
    ]);
});

it('reports review, handoff, question, cache, and line numbers', function (): void {
    [$first, $approved] = insights_task($this->project, 'completed', '2026-10-20', ['merged' => true, 'lines_added' => 150, 'lines_deleted' => 50], [
        'tokens' => 2_000, 'input_tokens' => 300, 'cached_input_tokens' => 700,
    ]);
    insights_verdict($approved, 'approved');
    insights_check($approved, 'passed');
    [, $reworked] = insights_task($this->project, 'cancelled', '2026-10-21');
    insights_verdict($reworked, 'changes_requested');
    insights_verdict($reworked, 'approved');
    insights_check($reworked, 'failed');
    insights_check($reworked, 'passed');
    TaskQuestion::query()->create([
        'task_id' => $first->id, 'subtask_id' => $approved->id, 'attempt' => 1, 'asked_by' => 'implementer',
        'question' => 'Which?', 'status' => 'answered', 'cause' => 'scope', 'asked_at' => now(),
    ]);

    $current = $this->getJson('/api/v1/task-insights')->assertOk()->json('data.current');

    expect($current)->toMatchArray([
        'subtask_first_pass' => 0.5, 'review_rounds' => 1.5, 'handoff_first_try' => 0.5,
        'questions_per_task' => 0.5, 'cache_hit' => 0.7,
        'tokens_per_hundred_lines' => 1_000,
    ])->and($current['questions_by_cause'])->toBe([
        'brief_unclear' => 0, 'contract_gap' => 0, 'environment' => 0, 'missed_contract' => 0, 'scope' => 1,
    ]);
});

it('compares the period with the one before it and fills days and weeks', function (): void {
    insights_task($this->project, 'completed', '2026-10-25 10:00:00', ['merged' => true]);
    insights_task($this->project, 'failed', '2026-10-24 10:00:00');
    insights_task($this->project, 'completed', '2026-10-18 10:00:00', ['merged' => true]);

    $data = $this->getJson('/api/v1/task-insights?period=7d')->assertOk()->json('data');

    expect($data)->toMatchArray(['period' => '7d', 'from' => '2026-10-21T00:00:00+00:00', 'to' => '2026-10-28T00:00:00+00:00'])
        ->and($data['current'])->toMatchArray(['tasks' => 2, 'merged' => 1])
        ->and($data['previous'])->toMatchArray(['tasks' => 1, 'merged' => 1, 'yield' => 1])
        ->and($data['daily'])->toHaveCount(7)
        ->and($data['daily'][3])->toBe(['date' => '2026-10-24', 'merged' => 0, 'not_merged' => 1])
        ->and($data['daily'][4])->toBe(['date' => '2026-10-25', 'merged' => 1, 'not_merged' => 0])
        ->and($data['weekly'])->toHaveCount(12)
        ->and($data['weekly'][11])->toBe(['week_start' => '2026-10-21', 'tasks' => 2, 'merged' => 1, 'tokens_per_merged_pr' => 2_000])
        ->and($data['weekly'][10])->toMatchArray(['week_start' => '2026-10-14', 'tasks' => 1, 'merged' => 1]);
});

it('filters by Project and breaks the period down by model, Project, and release', function (): void {
    $other = Project::query()->create([
        'name' => 'Other', 'code' => 'OTH', 'slug' => 'other',
        'repository_url' => 'git@example.test:other.git', 'default_branch' => 'main',
    ]);
    foreach (['aaaaaaaaaaaa', 'bbbbbbbbbbbb'] as $index => $sha) {
        $release = GatewayRelease::query()->create(['sha' => $sha, 'trigger' => 'auto', 'outcome' => 'verified', 'phases' => [], 'duration_ms' => 1]);
        $release->forceFill(['created_at' => CarbonImmutable::parse('2026-10-1'.($index + 1)), 'updated_at' => CarbonImmutable::parse('2026-10-26')])->saveQuietly();
    }
    insights_task($this->project, 'completed', '2026-10-20', ['merged' => true, 'started_at' => '2026-10-11 12:00:00']);
    insights_task($this->project, 'cancelled', '2026-10-21', ['implementer_model' => 'model-b', 'started_at' => '2026-10-13 12:00:00']);
    insights_task($other, 'completed', '2026-10-21', ['merged' => true]);

    $filtered = $this->getJson('/api/v1/task-insights?project_id='.$this->project->id)->assertOk()->json('data');
    $all = $this->getJson('/api/v1/task-insights')->assertOk()->json('data.breakdowns.project');

    expect($filtered['current'])->toMatchArray(['tasks' => 2, 'merged' => 1])
        ->and(array_column($filtered['breakdowns']['model'], 'key'))->toBe(['model-a', 'model-b'])
        ->and($filtered['breakdowns']['release'])->toBe([
            ['key' => 'aaaaaaaaaaaa', 'label' => 'aaaaaaa', 'tasks' => 1, 'merged' => 1, 'tokens_per_merged_pr' => 1_000, 'lead_time_ms' => null, 'yield' => 1],
            ['key' => 'bbbbbbbbbbbb', 'label' => 'bbbbbbb', 'tasks' => 1, 'merged' => 0, 'tokens_per_merged_pr' => null, 'lead_time_ms' => null, 'yield' => 0],
        ])
        ->and(array_column($filtered['releases'], 'sha'))->toBe(['aaaaaaaaaaaa', 'bbbbbbbbbbbb'])
        ->and(array_column($all, 'label'))->toBe(['INS', 'OTH']);
});

it('rejects an unknown period and answers extension.disabled while Tasks are off', function (): void {
    $this->getJson('/api/v1/task-insights?period=90d')->assertUnprocessable();
    app(TaskExtensionState::class)->disable();

    $this->getJson('/api/v1/task-insights')->assertStatus(409)->assertJsonPath('error.code', 'extension.disabled');
});
