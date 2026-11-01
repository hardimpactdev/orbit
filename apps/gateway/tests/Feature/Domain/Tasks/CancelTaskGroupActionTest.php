<?php

declare(strict_types=1);

use App\Actions\Tasks\CancelTaskGroupAction;
use App\Actions\Tasks\RemoveTaskWorkspaceAction;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\AgentObservation;
use App\Domain\Tasks\AgentThreadObserver;
use App\Domain\Tasks\AgentThreadState;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskPullRequestPublisher;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskWorkspaceLease;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskWorkspaceLeaseRecord;
use Tests\Support\FakeAgentDriver;

function cancellable_task_group(TaskGroupStatus $status, ?string $prUrl = null): Task
{
    $project = Project::query()->create([
        'name' => 'cancel-app',
        'slug' => 'cancel-app',
        'repository_url' => 'git@github.com:hardimpactdev/orbit.git',
        'default_branch' => 'main',
    ]);
    $node = Node::query()->create([
        'name' => 'cancel-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '10.44.0.160',
        'wireguard_ip' => '10.44.0.160',
    ]);
    $group = Task::topLevel()->create([
        'project_id' => $project->id,
        'title' => 'Cancel me',
        'brief' => 'Remove the stuck task workspace.',
        'status' => $status,
        'pr_url' => $prUrl,
    ]);
    test_task_workspace($group, [
        'node_id' => $node->id,
        'name' => 'task-21',
        'branch_override' => null,
        'checkout_path' => '/srv/orbit/apps/cancel-app/task-21',
    ]);

    return $group->fresh(['project', 'taskable']) ?? $group;
}

function cancel_subtask(Task $group, TaskStatus $status, int $position = 1): Task
{
    return Task::query()->create([
        'parent_id' => $group->id,
        'position' => $position,
        'title' => 'Subtask '.$position,
        'brief' => 'Part of the group.',
        'status' => $status,
    ]);
}

beforeEach(function (): void {
    bind_task_node_reachability();
    app(TaskExtensionState::class)->enable();
    $this->publisher = Mockery::mock(TaskPullRequestPublisher::class);
    $this->publisher->shouldNotReceive('publish', 'push', 'pushHandoff');
    app()->instance(TaskPullRequestPublisher::class, $this->publisher);
    $this->group = cancellable_task_group(TaskGroupStatus::Running);
    cancel_subtask($this->group, TaskStatus::Running);
});

it('cancels a group whose native ending has already removed its workspace', function (TaskGroupStatus $status): void {
    $this->group->workspace()->firstOrFail()->delete();
    $this->group->taskable()->dissociate();
    $this->group->status = $status;
    $this->group->save();

    $cancelled = app(CancelTaskGroupAction::class)->execute($this->group);

    expect($cancelled->status)->toBe(TaskGroupStatus::Cancelled)
        ->and($cancelled->taskable_id)->toBeNull()
        ->and($cancelled->tasks()->sole()->status)->toBe(TaskStatus::Cancelled)
        ->and(Instance::query()->count())->toBe(0);
})->with([TaskGroupStatus::Running, TaskGroupStatus::Cancelled]);

it('refuses a completed group or a settling group with a published pull request', function (TaskGroupStatus $status, ?string $url): void {
    $this->group->update(['status' => $status, 'pr_url' => $url]);
    $instance = $this->group->workspace()->firstOrFail();

    expect(fn () => app(CancelTaskGroupAction::class)->execute($this->group))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('tasks.not_cancellable')->and($exception->status)->toBe(409);
        });
    expect($instance->fresh())->not->toBeNull()->and($this->group->fresh()->status)->toBe($status);
})->with([
    [TaskGroupStatus::Completed, null],
    [TaskGroupStatus::Settling, 'https://github.com/example/project/pull/7'],
    [TaskGroupStatus::WaitingForReview, 'https://github.com/example/project/pull/7'],
]);

it('retains the workspace and records refusal instead of restoring the deleted PHP remover', function (): void {
    $instance = $this->group->workspace()->firstOrFail();

    expect(fn () => app(CancelTaskGroupAction::class)->execute($this->group))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('operation.kind_unavailable')->and($exception->status)->toBe(503);
        });

    expect($instance->fresh())->not->toBeNull()
        ->and($this->group->fresh()->taskable_id)->toBe($instance->id)
        ->and($this->group->fresh()->assistance_kind)->toBe(AssistanceKind::RemovalFailure)
        ->and($this->group->fresh()->assistance_reason)->toContain(RemoveTaskWorkspaceAction::RemovalFailedPrefix);
});

it('leaves the group and workspace untouched while Tasks is disabled', function (): void {
    app(TaskExtensionState::class)->disable();
    $instance = $this->group->workspace()->firstOrFail();

    expect(fn () => app(CancelTaskGroupAction::class)->execute($this->group))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('extension.disabled')->and($exception->status)->toBe(409);
        });
    expect($this->group->fresh()->status)->toBe(TaskGroupStatus::Running)->and($instance->fresh())->not->toBeNull();
});

it('deletes a handoff branch after native workspace cleanup only when a check pushed one', function (bool $handedOff): void {
    $group = $this->group;
    $group->workspace()->firstOrFail()->delete();
    $group->taskable()->dissociate();
    $group->save();
    TaskCheck::query()->create([
        'task_id' => $group->tasks()->sole()->id, 'kind' => TaskCheckKind::Handoff,
        'status' => TaskCheckStatus::Passed, 'commit_sha' => $handedOff ? str_repeat('c', 40) : null,
        'pid' => 1, 'process_started' => 'earlier', 'head_before' => str_repeat('a', 40),
        'tree_before' => str_repeat('b', 40), 'started_at' => now(),
    ]);
    if ($handedOff) {
        $this->publisher->shouldReceive('deleteHandoffBranch')->once()->withArgs(function (Task $task) use ($group): bool {
            expect($task->workspace()->exists())->toBeFalse();

            return $task->id === $group->id;
        });
    } else {
        $this->publisher->shouldNotReceive('deleteHandoffBranch');
    }

    expect(app(CancelTaskGroupAction::class)->execute($group)->status)->toBe(TaskGroupStatus::Cancelled);
})->with([true, false]);

it('refuses busy cancellation without changing actors or leaving a queued teardown', function (string $role): void {
    $group = $this->group;
    $task = $group->tasks()->sole();
    $groupStatus = $role === 'reviewer' ? TaskGroupStatus::Reviewing : TaskGroupStatus::Running;
    $taskStatus = $role === 'reviewer' ? TaskStatus::Reviewing : TaskStatus::Running;
    $group->update(['status' => $groupStatus]);
    $task->update(['status' => $taskStatus]);
    test_link_agent_threads($group);
    $instance = $group->workspace()->firstOrFail();
    $this->markAsGateway($instance->node);
    $this->withServerVariables(['REMOTE_ADDR' => $instance->node->wireguard_ip]);
    $threadId = $role === 'reviewer' ? $group->reviewer_agent_thread_id : $task->fresh()->implementer_agent_thread_id;
    $holder = app(TaskWorkspaceLease::class)->acquire($group, $role, 'thread:'.$threadId, $threadId, $task->id);
    $holder->update(['dispatch_started_at' => now()]);
    $driver = new FakeAgentDriver('pi');
    $driver->observation = new AgentObservation(AgentThreadState::Working, turnId: 'busy-turn');
    $registry = new AgentDriverRegistry([$driver]);
    app()->instance(AgentDriverRegistry::class, $registry);
    app()->instance(AgentThreadObserver::class, new AgentThreadObserver($registry));

    $this->postJson("/api/v1/task-groups/{$group->id}/cancel")
        ->assertConflict()->assertJsonPath('error.code', 'tasks.workspace_waiting');

    expect($group->fresh()->status)->toBe($groupStatus)
        ->and($group->fresh()->taskable_id)->toBe($instance->id)
        ->and($task->fresh()->status)->toBe($taskStatus)
        ->and($holder->fresh()->status)->toBe('held')
        ->and(TaskWorkspaceLeaseRecord::query()->where('status', 'queued')->count())->toBe(0);
    $this->assertModelExists($instance);
})->with(['reviewer', 'implementer']);
