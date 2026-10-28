<?php

declare(strict_types=1);

use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\AgentObservation;
use App\Domain\Tasks\AgentThreadObserver;
use App\Domain\Tasks\AgentThreadState;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskWorkspaceLease;
use App\Domain\Tasks\TaskWorkspaceWaiting;
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

it('admits later checks and receipts after a review fix returns the group to running', function (): void {
    $group = tick_group();
    $group->update(['status' => TaskGroupStatus::Settling, 'pr_url' => 'https://github.com/acme/orbit/pull/42']);
    $group->tasks()->update(['status' => TaskStatus::Completed]);
    $leases = app(TaskWorkspaceLease::class);
    $holder = $leases->acquire($group, 'check', 'prior-check');

    $fixup = tick_appended_subtask($group);
    $fixup->update(['status' => TaskStatus::Running]);
    $group->update(['status' => TaskGroupStatus::Running]);
    $leases->release($holder);
    $leases = new TaskWorkspaceLease;
    app()->instance(TaskWorkspaceLease::class, $leases);

    $check = $leases->acquire($group, 'check', 'fixup-check', taskId: $fixup->id);
    expect(fn () => $leases->withReceipt($group, $fixup->id, null, static fn (): bool => true))->toThrow(TaskWorkspaceWaiting::class);
    $leases->release($check);
    expect($leases->withReceipt($group, $fixup->id, null, static fn (): bool => true))->toBeTrue();

    expect($leases->status($group))->toBe([]);
});
