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
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use Tests\Support\FakeAgentDriver;
use Tests\Support\FakeTaskCheckRunner;

beforeEach(function (): void {
    $project = Project::query()->create(['name' => 'Lease', 'slug' => 'lease', 'repository_url' => 'https://example.test/lease.git']);
    $node = Node::query()->create(['name' => 'lease-node', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.10', 'public_ssh_host' => '192.0.2.10']);
    $workspace = test_ready_task_workspace($project, $node, ['checkout_path' => '/srv/lease', 'branch' => 'main'], ['orchestration' => 'external']);
    $this->group = Task::topLevel()->findOrFail($workspace->task_workspace_owner_id);
    $this->group->tasks()->create(['title' => 'Work', 'brief' => 'Work.', 'status' => 'running', 'position' => 1]);
    $driver = new FakeAgentDriver('pi');
    $driver->observation = new AgentObservation(AgentThreadState::Done, turnId: 'stopped');
    $registry = new AgentDriverRegistry([$driver]);
    app()->instance(AgentDriverRegistry::class, $registry);
    app()->instance(AgentThreadObserver::class, new AgentThreadObserver($registry));
    app()->instance(TaskCheckRunner::class, new FakeTaskCheckRunner);
});

it('admits later checks and receipts after a review fix returns the group to running', function (): void {
    $group = $this->group;
    $group->update(['status' => TaskGroupStatus::Settling, 'pr_url' => 'https://github.com/acme/orbit/pull/42']);
    $group->tasks()->update(['status' => TaskStatus::Completed]);
    $leases = app(TaskWorkspaceLease::class);
    $holder = $leases->acquire($group, 'check', 'prior-check');

    $fixup = $group->tasks()->create(['title' => 'Fix review', 'brief' => 'Address review.', 'status' => TaskStatus::Todo, 'position' => 2]);
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
