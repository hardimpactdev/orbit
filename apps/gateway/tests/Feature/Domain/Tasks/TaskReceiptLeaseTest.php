<?php

declare(strict_types=1);

use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\AgentObservation;
use App\Domain\Tasks\AgentThreadObserver;
use App\Domain\Tasks\AgentThreadState;
use App\Domain\Tasks\TaskConsultation;
use App\Domain\Tasks\TaskThreadRole;
use App\Domain\Tasks\TaskTurnReceipt;
use App\Domain\Tasks\TaskTurnReceiptException;
use App\Domain\Tasks\TaskTurnReceipts;
use App\Domain\Tasks\TaskWorkspaceLease;
use App\Domain\Tasks\TaskWorkspaceWaiting;
use App\Models\AgentThread;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskWorkspaceLeaseRecord;
use Tests\Support\FakeAgentDriver;
use Tests\Support\FakeTaskTurnReceipts;

beforeEach(function (): void {
    tick_bind_defaults();
});

/** @return array{Task, Task, AgentThread, FakeAgentDriver} */
function receipt_lease_fixture(TaskThreadRole $role): array
{
    $group = tick_group();
    $task = $group->tasks()->firstOrFail();
    $id = $role === TaskThreadRole::Implementer ? $task->implementer_agent_thread_id : $group->reviewer_agent_thread_id;
    $thread = AgentThread::query()->findOrFail($id);
    $driver = new FakeAgentDriver('pi');
    $driver->observation = new AgentObservation(AgentThreadState::Done, turnId: 'prior');
    $registry = new AgentDriverRegistry([$driver]);
    app()->instance(AgentDriverRegistry::class, $registry);
    app()->instance(AgentThreadObserver::class, new AgentThreadObserver($registry));

    return [$group, $task, $thread, $driver];
}

function receipt_lease_value(TaskThreadRole $role, int $threadId): TaskTurnReceipt
{
    return TaskTurnReceipt::parse(FakeTaskTurnReceipts::contents($role === TaskThreadRole::Implementer ? 'ready_for_review' : 'approved'))->withThread($threadId);
}

it('queues receipt collection behind another group and does no checkout read or clear before admission', function (TaskThreadRole $role): void {
    [$group, $task, $thread] = receipt_lease_fixture($role);
    $other = Task::topLevel()->create(['project_id' => $group->project_id, 'title' => 'Other group', 'brief' => 'Shares the Instance.', 'status' => 'running']);
    $other->setRelation('taskable', $group->taskable);
    $leases = app(TaskWorkspaceLease::class);
    $holder = $leases->acquire($other, 'workspace_fast_forward', 'other-group');
    $receipts = new FakeTaskTurnReceipts;
    app()->instance(TaskTurnReceipts::class, $receipts);

    expect(fn () => app(TaskConsultation::class)->collectReceipt($group, $task, $role))->toThrow(TaskWorkspaceWaiting::class);
    expect($receipts->reads())->toBe(0)
        ->and($receipts->cleared)->toBe([])
        ->and(TaskComment::query()->where('task_id', $task->id)->count())->toBe(0)
        ->and($holder->fresh()?->status)->toBe('held');
    $waiter = TaskWorkspaceLeaseRecord::query()->where('group_id', $group->id)->where('status', 'queued')->sole();
    expect($waiter->kind)->toBe('verdict')->and($waiter->task_id)->toBe($task->id);

    $leases->release($holder);
    expect(app(TaskConsultation::class)->collectReceipt($group, $task, $role))->toBeInstanceOf(TaskTurnReceipt::class);
    expect($receipts->reads())->toBe(1)->and($receipts->cleared)->toHaveCount(1)
        ->and($waiter->fresh()?->status)->toBe('released');
})->with(TaskThreadRole::cases());

it('collects a paused actors receipt under its existing lease without changing the dispatch fence', function (TaskThreadRole $role): void {
    [$group, $task, $thread, $driver] = receipt_lease_fixture($role);
    $leases = app(TaskWorkspaceLease::class);
    $holder = $leases->turn($group, $role, 'thread:'.$thread->id, $thread->id);
    $leases->dispatch($thread, $driver->observation, 'same-turn', 'Opening');
    $fence = $holder->fresh()->only(['dispatch_started_at', 'dispatch_key', 'dispatch_hash', 'previous_turn_id']);
    $leaseCount = TaskWorkspaceLeaseRecord::query()->where('instance_id', $group->taskable_id)->count();
    $driver->observation = new AgentObservation(AgentThreadState::AskingForInput, turnId: 'current');
    $receipt = receipt_lease_value($role, $thread->id);
    $receipts = Mockery::mock(TaskTurnReceipts::class);
    $receipts->shouldReceive('read')->once()->andReturnUsing(function () use ($holder, $receipt): TaskTurnReceipt {
        expect($holder->fresh()?->status)->toBe('held');

        return $receipt;
    });
    $receipts->shouldReceive('clear')->once();
    app()->instance(TaskTurnReceipts::class, $receipts);

    expect(app(TaskConsultation::class)->collectReceipt($group, $task, $role))->toBe($receipt);
    expect($holder->fresh()?->status)->toBe('held')
        ->and($holder->fresh()?->only(array_keys($fence)))->toEqual($fence)
        ->and(TaskWorkspaceLeaseRecord::query()->where('instance_id', $group->taskable_id)->count())->toBe($leaseCount)
        ->and(TaskComment::query()->where('receipt_hash', $receipt->hash)->count())->toBe(1);
})->with(TaskThreadRole::cases());

it('does not consume a receipt while the same actor is working unknown or only has an old stopped observation', function (?AgentThreadState $state, bool $uncertain): void {
    [$group, $task, $thread, $driver] = receipt_lease_fixture(TaskThreadRole::Implementer);
    $leases = app(TaskWorkspaceLease::class);
    $holder = $leases->turn($group, TaskThreadRole::Implementer, 'thread:'.$thread->id, $thread->id);
    $leases->dispatch($thread, $driver->observation, 'same-turn', 'Opening');
    $driver->observation = new AgentObservation($state, turnId: $uncertain ? 'current' : 'prior');
    if ($uncertain) {
        $holder->update(['remote_uncertain' => true]);
    }
    $receipts = new FakeTaskTurnReceipts;
    app()->instance(TaskTurnReceipts::class, $receipts);

    expect(fn () => app(TaskConsultation::class)->collectReceipt($group, $task, TaskThreadRole::Implementer))->toThrow(TaskWorkspaceWaiting::class);
    expect($receipts->reads())->toBe(0)->and($receipts->cleared)->toBe([])
        ->and($holder->fresh()?->status)->toBe('held')
        ->and(TaskWorkspaceLeaseRecord::query()->where('status', 'queued')->count())->toBe(0);
})->with([
    'working' => [AgentThreadState::Working, false],
    'unknown' => [null, false],
    'old stopped' => [AgentThreadState::Done, false],
    'old paused' => [AgentThreadState::AskingForInput, false],
    'uncertain remote mutation' => [AgentThreadState::AskingForInput, true],
]);

it('replays a committed receipt after clear fails without duplicating its durable comment', function (TaskThreadRole $role): void {
    [$group, $task, $thread] = receipt_lease_fixture($role);
    $receipt = receipt_lease_value($role, $thread->id);
    $receipts = Mockery::mock(TaskTurnReceipts::class);
    $receipts->shouldReceive('read')->twice()->andReturnUsing(function () use ($group, $receipt): TaskTurnReceipt {
        expect(TaskWorkspaceLeaseRecord::query()->where('active_instance_id', $group->taskable_id)->sole()->kind)->toBe('verdict');

        return $receipt;
    });
    $receipts->shouldReceive('clear')->once()->andThrow(new TaskTurnReceiptException('Lost clear response.'));
    $receipts->shouldReceive('clear')->once();
    app()->instance(TaskTurnReceipts::class, $receipts);

    expect(fn () => app(TaskConsultation::class)->collectReceipt($group, $task, $role))->toThrow(TaskTurnReceiptException::class);
    expect(app(TaskConsultation::class)->collectReceipt($group, $task, $role))->toBe($receipt);
    expect(TaskComment::query()->where('task_id', $task->id)->where('receipt_hash', $receipt->hash)->count())->toBe(1);
})->with(TaskThreadRole::cases());
