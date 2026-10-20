<?php

declare(strict_types=1);

use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\AgentObservation;
use App\Domain\Tasks\AgentThreadObserver;
use App\Domain\Tasks\AgentThreadState;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskMergeStatus;
use App\Domain\Tasks\TaskPullRequestHealth;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskWorkspaceLease;
use App\Domain\Tasks\TaskWorkspaceWaiting;
use App\Models\TaskWorkspaceLeaseRecord;
use Tests\Support\FakeAgentDriver;
use Tests\Support\FakeTaskCheckRunner;

beforeEach(function (): void {
    tick_bind_defaults();
    $driver = new FakeAgentDriver('pi');
    $driver->observation = new AgentObservation(AgentThreadState::Done, turnId: 'stopped');
    $registry = new AgentDriverRegistry([$driver]);
    app()->instance(AgentDriverRegistry::class, $registry);
    app()->instance(AgentThreadObserver::class, new AgentThreadObserver($registry));
    app()->instance(TaskCheckRunner::class, new FakeTaskCheckRunner);
});

it('admits later checks and receipts after merge-ready is attempted while a review fix returns the group to running', function (): void {
    $group = tick_group();
    $group->update(['status' => TaskGroupStatus::Settling, 'pr_url' => 'https://github.com/acme/orbit/pull/42']);
    $group->tasks()->update(['status' => TaskStatus::Completed]);
    $scheduler = app(TaskScheduler::class);
    $health = new TaskPullRequestHealth('open');
    $leases = app(TaskWorkspaceLease::class);
    $holder = $leases->acquire($group, 'check', 'prior-check');
    expect(fn () => $scheduler->mergeWhenReady($group, $health, null))->toThrow(TaskWorkspaceWaiting::class);

    $fixup = tick_appended_subtask($group);
    $fixup->update(['status' => TaskStatus::Running]);
    $group->update(['status' => TaskGroupStatus::Running]);
    // The settling tick still calls merge-ready after healing has resumed a fixup.
    expect(fn () => $scheduler->mergeWhenReady($group->fresh(['taskable']), $health, null))->toThrow(TaskWorkspaceWaiting::class);
    $leases->release($holder);
    $leases = new TaskWorkspaceLease;
    app()->instance(TaskWorkspaceLease::class, $leases);

    $check = $leases->acquire($group, 'check', 'fixup-check', taskId: $fixup->id);
    expect(fn () => $leases->withReceipt($group, $fixup->id, null, static fn (): bool => true))->toThrow(TaskWorkspaceWaiting::class);
    $leases->release($check);
    expect($leases->withReceipt($group, $fixup->id, null, static fn (): bool => true))->toBeTrue();

    expect($leases->status($group))->toBe([]);
    $this->assertDatabaseMissing('task_workspace_leases', [
        'group_id' => $group->id, 'owner' => 'merge-ready:'.$group->id, 'status' => 'queued',
    ]);
});

it('leaves no merge-ready waiter while settling and evaluates publication on its next eligible call', function (): void {
    $group = tick_group();
    $group->update(['status' => TaskGroupStatus::Settling, 'pr_url' => 'https://github.com/acme/orbit/pull/42']);
    $group->tasks()->update(['status' => TaskStatus::Completed]);
    $scheduler = app(TaskScheduler::class);
    $health = new TaskPullRequestHealth('open');
    $leases = app(TaskWorkspaceLease::class);
    $holder = $leases->acquire($group, 'check', 'prior-check');

    expect(fn () => $scheduler->mergeWhenReady($group, $health, null))->toThrow(TaskWorkspaceWaiting::class);

    expect($leases->status($group)[0]['waiters'])->toBe([])
        ->and($holder->fresh()->status)->toBe('held');
    $leases->release($holder);
    $scheduler->mergeWhenReady($group, $health, null);
    expect($group->fresh()->merge_status)->toBe(TaskMergeStatus::Waiting)
        ->and($group->fresh()->merge_reason)->toBe('GitHub did not report the pull request head.');
    $this->assertDatabaseHas('task_workspace_leases', [
        'group_id' => $group->id, 'owner' => 'merge-ready:'.$group->id, 'status' => 'released',
    ]);
});

it('does not let non-queuing merge-ready admission pass an earlier durable waiter', function (): void {
    $group = tick_group();
    $group->update(['status' => TaskGroupStatus::Settling]);
    $leases = app(TaskWorkspaceLease::class);
    $holder = $leases->acquire($group, 'check', 'prior-check');
    expect(fn () => $leases->acquire($group, 'verdict', 'earlier-receipt'))->toThrow(TaskWorkspaceWaiting::class);
    $earlier = TaskWorkspaceLeaseRecord::query()->where('owner', 'earlier-receipt')->sole();
    $leases->release($holder);

    expect(fn () => app(TaskScheduler::class)->mergeWhenReady($group, new TaskPullRequestHealth('open'), null))->toThrow(TaskWorkspaceWaiting::class);

    expect($earlier->fresh()->status)->toBe('queued')
        ->and($leases->status($group)[0]['waiters'])->toHaveCount(1)
        ->and($leases->acquire($group, 'verdict', 'earlier-receipt')->id)->toBe($earlier->id);
});
