<?php

declare(strict_types=1);

use App\Actions\Tasks\CompleteTaskGroupAction;
use App\Domain\Instances\InstanceRemover;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\AgentObservation;
use App\Domain\Tasks\AgentThreadState;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\TaskAssistance;
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStatus;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCheck;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\GitHub\GitHubTestSupport;
use Tests\Support\FakeAgentDriver;
use Tests\Support\FakeTaskCheckRunner;

function complete_group(TaskGroupStatus $status = TaskGroupStatus::Settling): Task
{
    $project = Project::query()->create([
        'name' => 'complete-app',
        'slug' => 'complete-app',
        'repository_url' => 'git@github.com:hardimpactdev/orbit.git',
        'default_branch' => 'main',
    ]);
    $node = Node::query()->create([
        'name' => 'complete-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '10.44.0.150',
        'wireguard_ip' => '10.44.0.150',
    ]);
    $group = Task::topLevel()->create([
        'project_id' => $project->id,
        'title' => 'Complete me',
        'brief' => 'Remove the workspace after merge.',
        'status' => $status,
        'pr_url' => 'https://github.com/hardimpactdev/orbit/pull/543',
    ]);
    test_task_workspace($group, [
        'node_id' => $node->id,
        'name' => 'task-20',
        'branch_override' => null,
        'checkout_path' => '/srv/orbit/apps/complete-app/task-20',
    ]);

    return $group->fresh(['project', 'taskable']) ?? $group;
}

it('removes the shared App instance and marks a settling group completed', function (TaskGroupStatus $status): void {
    app(TaskExtensionState::class)->enable();
    $group = complete_group($status);
    $instanceId = $group->taskable_id;
    $remover = new class implements InstanceRemover
    {
        /** @var list<array{0: int, 1: bool}> */
        public array $calls = [];

        public function execute(Instance $instance, bool $force): InstanceRemoval
        {
            $this->calls[] = [$instance->id, $force];
            $instance->delete();

            return new InstanceRemoval;
        }
    };
    app()->instance(InstanceRemover::class, $remover);

    $completed = app(CompleteTaskGroupAction::class)->execute($group);

    expect($completed->status)->toBe(TaskGroupStatus::Completed)
        ->and($completed->taskable_id)->toBeNull()
        ->and($completed->settled_at)->not->toBeNull()
        ->and($remover->calls)->toBe([[$instanceId, true]])
        ->and(Instance::query()->find($instanceId))->toBeNull();
})->with([TaskGroupStatus::Settling, TaskGroupStatus::WaitingForReview]);

it('never removes a user Instance named and branched like the workspace of the group it completes', function (): void {
    app(TaskExtensionState::class)->enable();
    $group = complete_group();
    $name = 'task-'.$group->id;
    $workspace = $group->taskable;
    $group->taskable()->dissociate();
    $group->save();
    $workspace->delete();
    $user = Instance::query()->create(['project_id' => $group->project_id, 'node_id' => $workspace->node_id, 'name' => $name, 'branch_override' => $name, 'checkout_path' => '/srv/orbit/apps/complete-app/'.$name, 'status' => 'source_resolved']);
    $remover = new class implements InstanceRemover
    {
        /** @var list<int> */
        public array $calls = [];

        public function execute(Instance $instance, bool $force): InstanceRemoval
        {
            $this->calls[] = $instance->id;
            $instance->delete();

            return new InstanceRemoval;
        }
    };
    app()->instance(InstanceRemover::class, $remover);

    $completed = app(CompleteTaskGroupAction::class)->execute($group);

    expect($completed->status)->toBe(TaskGroupStatus::Completed)
        ->and($remover->calls)->toBe([])
        ->and(Instance::query()->find($user->id))->name->toBe($name)->branch_override->toBe($name);
});

it('is idempotent for an already completed group and retries a leftover workspace', function (): void {
    app(TaskExtensionState::class)->enable();
    $group = complete_group(TaskGroupStatus::Completed);
    $instanceId = $group->taskable_id;
    $remover = new class implements InstanceRemover
    {
        /** @var list<array{0: int, 1: bool}> */
        public array $calls = [];

        public function execute(Instance $instance, bool $force): InstanceRemoval
        {
            $this->calls[] = [$instance->id, $force];
            $instance->delete();

            return new InstanceRemoval;
        }
    };
    app()->instance(InstanceRemover::class, $remover);

    $completed = app(CompleteTaskGroupAction::class)->execute($group);
    $again = app(CompleteTaskGroupAction::class)->execute($completed);

    expect($completed->status)->toBe(TaskGroupStatus::Completed)
        ->and($again->status)->toBe(TaskGroupStatus::Completed)
        ->and($remover->calls)->toBe([[$instanceId, true]])
        ->and($again->taskable_id)->toBeNull()
        ->and(Instance::query()->find($instanceId))->toBeNull();
});

it('marks the group completed and reports the removal failure when the workspace cannot be removed', function (): void {
    app(TaskExtensionState::class)->enable();
    $remover = new class implements InstanceRemover
    {
        public bool $fail = true;

        public function execute(Instance $instance, bool $force): InstanceRemoval
        {
            if ($this->fail) {
                throw new ResourceOperationException('instance.force_failed', 'The Node is unreachable.', 409);
            }
            $instance->delete();

            return new InstanceRemoval;
        }
    };
    app()->instance(InstanceRemover::class, $remover);
    $group = complete_group();
    TaskAssistance::apply($group, AssistanceKind::Direction, 'Which mirror?', 'Choose a mirror.');
    $instanceId = $group->taskable_id;

    $completed = app(CompleteTaskGroupAction::class)->execute($group);

    expect($completed->status)->toBe(TaskGroupStatus::Completed)
        ->and($completed->taskable_id)->toBe($instanceId)
        ->and($completed->assistance_requested)->toBeTrue()
        ->and($completed->assistance_kind)->toBe(AssistanceKind::RemovalFailure)
        ->and($completed->assistance_question)->toBeNull()
        ->and($completed->assistance_reason)->toBe('Workspace removal failed: The Node is unreachable.')
        ->and(Instance::query()->find($instanceId))->not->toBeNull();

    $remover->fail = false;
    $retried = app(CompleteTaskGroupAction::class)->execute($completed);

    expect($retried->status)->toBe(TaskGroupStatus::Completed)
        ->and($retried->taskable_id)->toBeNull()
        ->and($retried->assistance_requested)->toBeFalse()
        ->and(Instance::query()->find($instanceId))->toBeNull();
});

it('returns 409 tasks.not_settling when the group is still running', function (): void {
    app(TaskExtensionState::class)->enable();
    $group = complete_group(TaskGroupStatus::Running);

    expect(fn () => app(CompleteTaskGroupAction::class)->execute($group))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('tasks.not_settling')
                ->and($exception->status)->toBe(409);
        });
});

it('returns 409 extension.disabled while the extension is off', function (): void {
    $group = complete_group();

    expect(fn () => app(CompleteTaskGroupAction::class)->execute($group))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('extension.disabled')
                ->and($exception->status)->toBe(409);
        });
});

/** Authorizes only the watched PR, not the separately published PR. */
function complete_ended_read(?string $state): void
{
    Http::preventStrayRequests();
    GitHubTestSupport::storeApp();
    Http::fake([
        'https://api.github.com/repos/hardimpactdev/orbit/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_complete'], 201),
        'https://api.github.com/repos/hardimpactdev/orbit/pulls/544' => $state === null
            ? Http::response([], 503)
            : Http::response(['state' => $state === 'open' ? 'open' : 'closed', 'merged' => $state === 'merged', 'merged_at' => $state === 'merged' ? '2026-10-08T10:00:00Z' : null]),
    ]);
}

it('complete ended cancels open subtasks through the API and removes the workspace', function (TaskGroupStatus $status, string $state): void {
    app(TaskExtensionState::class)->enable();
    complete_ended_read($state);
    $group = complete_group($status);
    test_update_task($group, ['watched_pr_url' => 'https://github.com/hardimpactdev/orbit/pull/544', 'watched_pr_state' => 'open', 'assistance_requested' => true, 'assistance_reason' => 'Watched pull request ended: prior notice.']);
    $instance = $group->taskable;
    $instanceId = $instance->id;
    $this->markAsGateway($instance->node);
    $this->withServerVariables(['REMOTE_ADDR' => $instance->node->wireguard_ip]);
    $tasks = collect([TaskStatus::Todo, TaskStatus::Running, TaskStatus::Reviewing, TaskStatus::Completed, TaskStatus::Failed, TaskStatus::Cancelled])
        ->map(fn (TaskStatus $status, int $position): Task => test_create_task(Task::query(), [
            'parent_id' => $group->id, 'position' => $position + 1, 'title' => $status->value, 'brief' => 'Keep terminal work.',
            'status' => $status, 'assistance_requested' => true, 'assistance_reason' => 'Previous reason.',
        ]));
    foreach ([$tasks[1], $tasks[2]] as $task) {
        $role = $task->status === TaskStatus::Running ? 'implementer' : 'reviewer';
        $thread = AgentThread::query()->create([
            'task_group_id' => $group->id, 'task_id' => $task->id, 'node_id' => $instance->node_id,
            'driver' => 'fake', 'runtime_key' => 'node:'.$instance->node_id, 'external_id' => $role, 'role' => $role,
        ]);
        if ($role === 'implementer') {
            $task->update(['implementer_agent_thread_id' => $thread->id]);
        } else {
            $group->update(['reviewer_agent_thread_id' => $thread->id]);
        }
    }
    $check = TaskCheck::query()->create([
        'task_id' => $tasks[1]->id, 'kind' => TaskCheckKind::Baseline, 'status' => TaskCheckStatus::Running,
        'pid' => 4100, 'process_started' => 'Wed Sep 23 12:00:00 2026', 'head_before' => str_repeat('a', 40), 'tree_before' => str_repeat('b', 40), 'started_at' => now(),
    ]);
    $driver = new FakeAgentDriver('fake');
    $driver->observation = new AgentObservation(AgentThreadState::Done, turnId: 'stopped-for-completion');
    $driver->supportsInterruption = true;
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));
    $checks = new FakeTaskCheckRunner;
    app()->instance(TaskCheckRunner::class, $checks);
    $level = DB::transactionLevel();
    $remover = new class($group->id, $level) implements InstanceRemover
    {
        public function __construct(private int $groupId, private int $level) {}

        public function execute(Instance $instance, bool $force): InstanceRemoval
        {
            expect($force)->toBeTrue()
                ->and(DB::transactionLevel())->toBe($this->level)
                ->and(Task::topLevel()->findOrFail($this->groupId)->status)->toBe(TaskGroupStatus::Completed)
                ->and(Task::query()->where('parent_id', $this->groupId)->whereIn('status', ['todo', 'running', 'reviewing'])->exists())->toBeFalse();
            $instance->delete();

            return new InstanceRemoval;
        }
    };
    app()->instance(InstanceRemover::class, $remover);

    $this->postJson("/api/v1/task-groups/{$group->id}/complete")
        ->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.assistance_requested', false)->assertJsonPath('data.taskable_id', null);

    expect($group->fresh()->watched_pr_completion)->toBe($state)
        ->and($group->fresh()->merged_at?->toIso8601ZuluString())->toBe($state === 'merged' ? '2026-10-08T10:00:00Z' : null)
        ->and($group->fresh()->ended_at)->not->toBeNull()
        ->and($group->fresh()->pr_url)->toBe('https://github.com/hardimpactdev/orbit/pull/543')
        ->and($group->fresh()->assistance_requested)->toBeFalse()
        ->and($group->fresh()->settled_at)->not->toBeNull()
        ->and($group->tasks()->orderBy('position')->get()->map(fn (Task $task): TaskStatus => $task->status)->all())->toBe([TaskStatus::Cancelled, TaskStatus::Cancelled, TaskStatus::Cancelled, TaskStatus::Completed, TaskStatus::Failed, TaskStatus::Cancelled])
        ->and($check->fresh()->status)->toBe(TaskCheckStatus::Cancelled)
        ->and($check->fresh()->finished_at)->not->toBeNull()
        ->and($checks->cancels)->toBe(1)
        ->and($checks->starts)->toBe(0)
        ->and($driver->calls)->toBe([['operation' => 'interrupt', 'thread' => 'implementer'], ['operation' => 'interrupt', 'thread' => 'reviewer']])
        ->and($driver->interruptTransactionLevels)->toBe([$level, $level])
        ->and($checks->cancelTransactionLevels)->toBe([$level])
        ->and(Instance::query()->find($instanceId))->toBeNull();
    Http::assertSentCount(3);
})->with([TaskGroupStatus::Running, TaskGroupStatus::Reviewing])->with(['merged', 'closed']);

it('complete ended still refuses other running or reviewing groups', function (TaskGroupStatus $status, ?string $state, ?string $url): void {
    app(TaskExtensionState::class)->enable();
    complete_ended_read($state);
    $group = complete_group($status);
    $group->update(['watched_pr_url' => $url, 'watched_pr_state' => 'merged']);
    $task = Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'Open', 'brief' => 'Still open.', 'status' => TaskStatus::Todo]);
    $this->markAsGateway($group->taskable->node);
    $this->withServerVariables(['REMOTE_ADDR' => $group->taskable->node->wireguard_ip]);

    $this->postJson("/api/v1/task-groups/{$group->id}/complete")
        ->assertStatus(409)->assertJsonPath('error.code', 'tasks.not_settling');

    expect($group->fresh()->status)->toBe($status)
        ->and($task->fresh()->status)->toBe(TaskStatus::Todo)
        ->and(Instance::query()->find($group->taskable_id))->not->toBeNull();
})->with([TaskGroupStatus::Running, TaskGroupStatus::Reviewing])->with([
    'missing URL' => ['merged', null],
    'open' => ['open', 'https://github.com/hardimpactdev/orbit/pull/544'],
    'unreadable' => [null, 'https://github.com/hardimpactdev/orbit/pull/544'],
    'wrong repository' => ['merged', 'https://github.com/another/orbit/pull/544'],
]);

it('complete ended revalidates and stops a replacement reviewer and check before workspace removal', function (bool $fail): void {
    app(TaskExtensionState::class)->enable();
    complete_ended_read('merged');
    $group = complete_group(TaskGroupStatus::Running);
    $group->update(['watched_pr_url' => 'https://github.com/hardimpactdev/orbit/pull/544']);
    $task = Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'Acting', 'brief' => 'Stop replacement.', 'status' => TaskStatus::Running]);
    $thread = AgentThread::query()->create([
        'task_group_id' => $group->id, 'task_id' => $task->id, 'node_id' => $group->taskable->node_id,
        'driver' => 'fake', 'runtime_key' => 'node:'.$group->taskable->node_id, 'external_id' => 'implementer', 'role' => 'implementer',
    ]);
    $task->update(['implementer_agent_thread_id' => $thread->id]);
    $previousReviewer = AgentThread::query()->create([
        'task_group_id' => $group->id, 'task_id' => $task->id, 'node_id' => $group->taskable->node_id,
        'driver' => 'fake', 'runtime_key' => 'node:'.$group->taskable->node_id, 'external_id' => 'previous-reviewer', 'role' => 'reviewer',
    ]);
    $group->update(['reviewer_agent_thread_id' => $previousReviewer->id]);
    $driver = new FakeAgentDriver('fake');
    $driver->observation = new AgentObservation(AgentThreadState::Done, turnId: 'stopped-for-completion');
    $driver->supportsInterruption = true;
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));
    $checks = new FakeTaskCheckRunner;
    app()->instance(TaskCheckRunner::class, $checks);
    $driver->duringInterrupt = static function () use ($group, $task, $driver, $fail): void {
        $driver->duringInterrupt = $fail ? static function () use ($driver): void {
            $driver->duringInterrupt = null;
            $driver->failNextInterrupt = true;
        } : null;
        // Inject a changed stop snapshot independently of guarded admission to exercise fail-closed revalidation.
        $replacement = AgentThread::query()->create([
            'task_group_id' => $group->id, 'task_id' => $task->id, 'node_id' => $group->taskable->node_id,
            'driver' => 'fake', 'runtime_key' => 'node:'.$group->taskable->node_id, 'external_id' => 'replacement-reviewer', 'role' => 'reviewer',
        ]);
        $group->fresh()->update(['status' => TaskGroupStatus::Reviewing, 'reviewer_agent_thread_id' => $replacement->id]);
        $task->update(['status' => TaskStatus::Reviewing]);
        TaskCheck::query()->create([
            'task_id' => $task->id, 'kind' => TaskCheckKind::Handoff, 'status' => TaskCheckStatus::Running,
            'pid' => 4200, 'process_started' => 'Wed Sep 23 12:00:01 2026', 'head_before' => str_repeat('a', 40), 'tree_before' => str_repeat('b', 40), 'started_at' => now(),
        ]);
    };
    $instanceId = $group->taskable_id;
    $remover = new class($driver, $checks, $fail) implements InstanceRemover
    {
        public function __construct(private FakeAgentDriver $driver, private FakeTaskCheckRunner $checks, private bool $fail) {}

        public function execute(Instance $instance, bool $force): InstanceRemoval
        {
            expect(array_column($this->driver->calls, 'thread'))->toBe($this->fail ? ['implementer', 'replacement-reviewer', 'replacement-reviewer'] : ['implementer', 'replacement-reviewer'])
                ->and($this->checks->cancels)->toBe(2);
            $instance->delete();

            return new InstanceRemoval;
        }
    };
    app()->instance(InstanceRemover::class, $remover);

    if ($fail) {
        expect(fn () => app(CompleteTaskGroupAction::class)->execute($group))->toThrow(ResourceOperationException::class);
        expect($group->fresh()->status)->toBe(TaskGroupStatus::Reviewing)
            ->and($task->fresh()->status)->toBe(TaskStatus::Reviewing)
            ->and($task->checks()->firstOrFail()->status)->toBe(TaskCheckStatus::Running)
            ->and(Instance::query()->find($instanceId))->not->toBeNull();
        Http::fake();
    }
    $completed = app(CompleteTaskGroupAction::class)->execute($group);

    expect($completed->status)->toBe(TaskGroupStatus::Completed)
        ->and($completed->taskable_id)->toBeNull()
        ->and($completed->assistance_requested)->toBeFalse()
        ->and($task->fresh()->status)->toBe(TaskStatus::Cancelled)
        ->and($task->checks()->firstOrFail()->status)->toBe(TaskCheckStatus::Cancelled)
        ->and(Instance::query()->find($instanceId))->toBeNull();
    if ($fail) {
        Http::assertNothingSent();
    }
})->with([false, true]);

it('complete ended retries workspace removal after the completion commit without GitHub', function (): void {
    app(TaskExtensionState::class)->enable();
    complete_ended_read('closed');
    $group = complete_group(TaskGroupStatus::Running);
    $group->update(['watched_pr_url' => 'https://github.com/hardimpactdev/orbit/pull/544']);
    $task = Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'Open', 'brief' => 'Cancel.', 'status' => TaskStatus::Todo]);
    $remover = new class implements InstanceRemover
    {
        public bool $fail = true;

        public function execute(Instance $instance, bool $force): InstanceRemoval
        {
            if ($this->fail) {
                throw new RuntimeException('Stopped before workspace removal.');
            }
            $instance->delete();

            return new InstanceRemoval;
        }
    };
    app()->instance(InstanceRemover::class, $remover);
    // Propagate the injected crash rather than handling it as a manual cleanup failure.
    expect(fn () => app(CompleteTaskGroupAction::class)->execute($group, finishWhenRemovalFails: false))->toThrow(RuntimeException::class);

    expect($group->fresh()->status)->toBe(TaskGroupStatus::Completed)
        ->and($group->fresh()->watched_pr_completion)->toBe('closed')
        ->and($task->fresh()->status)->toBe(TaskStatus::Cancelled)
        ->and($group->fresh()->taskable_id)->not->toBeNull();
    Http::assertSentCount(3);
    Http::fake();
    $remover->fail = false;

    $completed = app(CompleteTaskGroupAction::class)->execute($group);

    expect($completed->status)->toBe(TaskGroupStatus::Completed)
        ->and($completed->taskable_id)->toBeNull()
        ->and($completed->assistance_requested)->toBeFalse()
        ->and($task->fresh()->status)->toBe(TaskStatus::Cancelled);
    Http::assertNothingSent();
});
