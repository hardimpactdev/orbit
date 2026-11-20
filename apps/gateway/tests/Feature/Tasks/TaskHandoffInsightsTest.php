<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskHandoffType;
use App\Domain\Tasks\TaskInsights;
use App\Domain\Tasks\TaskInsightTotals;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskHandoff;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-19 14:25:00', 'UTC'));
    app(TaskExtensionState::class)->enable();
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);
    $gateway = Node::withoutEvents(fn () => Node::query()->create(['name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.2', 'public_ssh_host' => '10.44.0.2', 'user' => 'orbit']));
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $this->project = Project::query()->create(['name' => 'app', 'slug' => 'app', 'repository_url' => 'https://github.com/example/app.git', 'default_branch' => 'main', 'root' => 'public']);
});

function handoffGroup(TaskGroupStatus $status, bool $gated): Task
{
    $group = Task::topLevel()->create(['project_id' => test()->project->id, 'title' => 'T', 'brief' => 'Brief', 'status' => $status]);
    $group->forceFill(['handoffs_gated' => $gated, 'ended_at' => $status === TaskGroupStatus::Running ? null : now()->subDay()])->save();

    return $group;
}

function handoffSubtask(Task $group, int $position): Task
{
    return $group->tasks()->create(['title' => 'Subtask', 'brief' => 'Brief', 'position' => $position]);
}

/** @param  list<array{0: string, 1: string, 2: int|float}>|null  $parts  name, result, seconds */
function handoffCheck(Task $subtask, ?array $parts): TaskCheck
{
    return TaskCheck::query()->create(['task_id' => $subtask->id, 'kind' => TaskCheckKind::Handoff, 'status' => TaskCheckStatus::Passed,
        'pid' => 1, 'process_started' => 'x', 'head_before' => str_repeat('a', 40), 'tree_before' => str_repeat('2', 40), 'exit_code' => 0, 'output' => '',
        'parts' => $parts === null ? null : array_map(static fn (array $part): array => ['name' => $part[0], 'result' => $part[1], 'seconds' => $part[2]], $parts),
        'started_at' => now(), 'finished_at' => now()]);
}

/** @param  array<string, mixed>|null  $receipt */
function handoff(Task $subtask, TaskHandoffType $type, ?array $receipt, string $rejection = '', ?TaskCheck $check = null): void
{
    TaskHandoff::query()->create(['task_id' => $subtask->id, 'type' => $type, 'receipt' => $receipt, 'rejected' => $rejection !== '',
        'rejection_reason' => $rejection, 'task_check_id' => $check?->id]);
}

/**
 * Two gated groups that ended, one that is not gated, and one gated group still running, whose handoffs do not count.
 * Expected: 12 handoffs over 3 subtasks; first reviews `changes` with 2 findings and `approve` with 0; 2 questions over 2 gated tasks;
 * one delivery rejected for each of no_receipt, failed_part and claim_disproved; part lint 5 runs, 1 failure, 46 seconds and
 * part tests 5 runs, 1 failure, 120 seconds; one ended task whose rejected delivery no accepted delivery follows.
 */
function handoffFixture(): void
{
    $receipt = static fn (string $verdict, int $findings): array => ['verdict' => $verdict, 'findings' => $findings];
    $delivery = ['commit' => str_repeat('b', 40), 'tree' => str_repeat('4', 40), 'parts' => [['name' => 'lint', 'result' => 'passed', 'seconds' => 1]]];

    $resolved = handoffGroup(TaskGroupStatus::Completed, true);
    $first = handoffSubtask($resolved, 1);
    handoff($first, TaskHandoffType::Delivery, null, 'no_receipt', handoffCheck($first, null));
    handoff($first, TaskHandoffType::Delivery, $delivery, check: handoffCheck($first, [['lint', 'passed', 10], ['tests', 'passed', 30]]));
    handoff($first, TaskHandoffType::Review, $receipt('changes', 2));
    handoff($first, TaskHandoffType::Delivery, $delivery, check: handoffCheck($first, [['lint', 'passed', 12], ['tests', 'passed', 34]]));
    handoff($first, TaskHandoffType::Review, $receipt('approve', 0));
    $second = handoffSubtask($resolved, 2);
    handoff($second, TaskHandoffType::Question, ['questions' => [['question' => 'Which path?']]]);
    handoff($second, TaskHandoffType::Delivery, $delivery, 'failed_part', handoffCheck($second, [['lint', 'passed', 8], ['tests', 'FAILED', 20.5]]));
    handoff($second, TaskHandoffType::Delivery, $delivery, check: handoffCheck($second, [['lint', 'passed', 10], ['tests', 'passed', 35.5]]));
    handoff($second, TaskHandoffType::Review, null, 'no_receipt');
    handoff($second, TaskHandoffType::Review, $receipt('approve', 0));

    $unresolved = handoffGroup(TaskGroupStatus::Failed, true);
    $third = handoffSubtask($unresolved, 1);
    handoff($third, TaskHandoffType::Question, ['questions' => [['question' => 'Retry?']]]);
    handoff($third, TaskHandoffType::Delivery, $delivery, 'claim_disproved', handoffCheck($third, [['lint', 'FAILED', 6], ['tests', 'skipped', 0]]));

    handoffSubtask(handoffGroup(TaskGroupStatus::Completed, false), 1);
    $running = handoffSubtask(handoffGroup(TaskGroupStatus::Running, true), 1);
    handoff($running, TaskHandoffType::Delivery, null, 'no_receipt');
}

/** @return array<string, mixed> */
function expectedHandoffs(): array
{
    return [
        'tasks' => 2,
        'handoffs_per_subtask' => 4.0,
        'first_review_approval' => 0.5,
        'findings_per_first_review' => 1.0,
        'questions_per_task' => 1.0,
        'rejected_deliveries' => ['check_failed' => 0, 'claim_disproved' => 1, 'failed_part' => 1, 'no_receipt' => 1, 'other_commit' => 0],
        'check_parts' => [
            ['name' => 'lint', 'runs' => 5, 'failures' => 1, 'seconds' => 9.2],
            ['name' => 'tests', 'runs' => 5, 'failures' => 1, 'seconds' => 24.0],
        ],
        'unresolved_tasks' => 1,
    ];
}

it('reads each handoff number from the handoff rows of the gated tasks that ended', function (): void {
    handoffFixture();

    $report = app(TaskInsights::class)->report('30d', null, CarbonImmutable::now())->toArray();

    expect($report['current']['tasks'])->toBe(3)
        ->and($report['current']['handoffs'])->toEqual(expectedHandoffs())
        ->and($report['previous']['handoffs'])->toEqual([...expectedHandoffs(), 'tasks' => 0, 'handoffs_per_subtask' => null, 'first_review_approval' => null,
            'findings_per_first_review' => null, 'questions_per_task' => null, 'check_parts' => [], 'unresolved_tasks' => 0,
            'rejected_deliveries' => array_map(static fn (): int => 0, expectedHandoffs()['rejected_deliveries'])]);
});

it('gives the same handoff numbers from the stored records, and records a handoff written past the observer', function (): void {
    handoffFixture();
    $totals = app(TaskInsightTotals::class);
    $totals->refresh(CarbonImmutable::now());

    expect($this->getJson('/api/v1/task-insights?period=30d')->assertOk()->json('data.current.handoffs'))->toEqual(expectedHandoffs());

    // An accepted delivery after the claim that was disproved resolves the last open rejection.
    $this->travel(1)->minutes();
    $third = Task::query()->whereHas('parent', static fn ($query) => $query->where('status', TaskGroupStatus::Failed->value))->sole();
    DB::table('task_handoffs')->insert(['task_id' => $third->id, 'type' => TaskHandoffType::Delivery->value, 'receipt' => null,
        'rejected' => false, 'rejection_reason' => '', 'created_at' => now()]);
    $totals->refresh(CarbonImmutable::now());

    expect($this->getJson('/api/v1/task-insights?period=30d')->assertOk()->json('data.current.handoffs'))
        ->toEqual([...expectedHandoffs(), 'handoffs_per_subtask' => 13 / 3, 'unresolved_tasks' => 0]);
});
