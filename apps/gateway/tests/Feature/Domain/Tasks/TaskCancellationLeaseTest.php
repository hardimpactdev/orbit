<?php

declare(strict_types=1);

use App\Actions\Tasks\CancelTaskGroupAction;
use App\Domain\Instances\InstanceRemover;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\AgentObservation;
use App\Domain\Tasks\AgentThreadObserver;
use App\Domain\Tasks\AgentThreadState;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskPullRequestException;
use App\Domain\Tasks\TaskPullRequestPublisher;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskWorkspaceLease;
use App\Domain\Tasks\TaskWorkspaceWaiting;
use App\Domain\TaskVms\TaskVmState;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskVm;
use App\Models\TaskWorkspaceLeaseRecord;
use Tests\Support\FakeAgentDriver;
use Tests\Support\TaskOperationFixture;

beforeEach(function (): void {
    TaskOperationFixture::bindDefaults();
    bind_task_node_reachability();
    app(TaskExtensionState::class)->enable();
});

describe('busy cancellation API', function (): void {
    it('returns the lease-wait error while an agent thread owns the workspace', function (string $role): void {
        $group = cancellation_lease_group();
        $task = $group->tasks()->sole();
        $groupStatus = $role === 'reviewer' ? TaskGroupStatus::Reviewing : TaskGroupStatus::Running;
        $taskStatus = $role === 'reviewer' ? TaskStatus::Reviewing : TaskStatus::Running;
        $group->update(['status' => $groupStatus]);
        $task->update(['status' => $taskStatus]);
        $instance = $group->taskable;
        $this->markAsGateway($instance->node);
        $this->withServerVariables(['REMOTE_ADDR' => $instance->node->wireguard_ip]);
        $threadId = $role === 'reviewer' ? $group->reviewer_agent_thread_id : $task->implementer_agent_thread_id;
        $leases = app(TaskWorkspaceLease::class);
        $holder = $leases->acquire($group, $role, 'thread:'.$threadId, $threadId, $task->id);
        $holder->update(['dispatch_started_at' => now()]);
        $driver = new FakeAgentDriver('pi');
        $driver->observation = new AgentObservation(AgentThreadState::Working, turnId: 'busy-turn');
        $registry = new AgentDriverRegistry([$driver]);
        app()->instance(AgentDriverRegistry::class, $registry);
        app()->instance(AgentThreadObserver::class, new AgentThreadObserver($registry));
        $removed = cancellation_lease_remover();

        $this->postJson("/api/v1/task-groups/{$group->id}/cancel")
            ->assertConflict()
            ->assertJsonPath('error.code', 'tasks.workspace_waiting');

        expect($group->fresh()->status)->toBe($groupStatus);
        expect($group->fresh()->taskable_id)->toBe($instance->id);
        expect($task->fresh()->status)->toBe($taskStatus);
        expect($holder->fresh()->status)->toBe('held');
        expect($removed->ids)->toBe([]);
        $this->assertModelExists($instance);
        expect(TaskWorkspaceLeaseRecord::query()->where('status', 'queued')->count())->toBe(0);
    })->with(['reviewer', 'implementer']);
});

it('withdraws a refused cancellation intent for an unattached named checkout', function (): void {
    $group = cancellation_lease_group();
    $instance = $group->taskable;
    $instance->update(['branch_override' => 'task-'.$group->id]);
    $leases = app(TaskWorkspaceLease::class);
    $leases->reconcile($group);
    $holder = $leases->acquire($group, 'check', 'busy-check');
    $group->taskable()->dissociate();
    $group->save();
    $removed = cancellation_lease_remover();

    expect(fn () => app(CancelTaskGroupAction::class)->execute($group))->toThrow(TaskWorkspaceWaiting::class);
    expect($holder->fresh()->status)->toBe('held');
    expect($removed->ids)->toBe([]);
    expect(TaskWorkspaceLeaseRecord::query()->where('status', 'queued')->count())->toBe(0);
    $leases->release($holder);
    app(CancelTaskGroupAction::class)->execute($group);

    expect($removed->ids)->toBe([$instance->id]);
    expect($group->fresh()->status)->toBe(TaskGroupStatus::Cancelled);
});

it('limits named leftover recovery to its resolved Instance and its own historical actors', function (): void {
    $group = cancellation_lease_group();
    $instance = $group->taskable;
    $instance->update(['branch_override' => 'task-'.$group->id]);
    $group->taskable()->dissociate();
    $group->save();
    $other = Task::topLevel()->create([
        'project_id' => $group->project_id, 'title' => 'Already completed',
        'brief' => 'Historical group without a workspace.', 'status' => TaskGroupStatus::Completed,
    ]);
    $unrelated = AgentThread::query()->create([
        'task_group_id' => $other->id, 'driver' => 'pi', 'role' => 'implementer',
        'runtime_key' => 'node:'.$instance->node_id, 'external_id' => 'historical-other-group',
        'node_id' => $instance->node_id,
    ]);
    $removed = cancellation_lease_remover();

    app(CancelTaskGroupAction::class)->execute($group);

    expect($removed->ids)->toBe([$instance->id]);
    expect($group->fresh()->status)->toBe(TaskGroupStatus::Cancelled);
    expect(TaskWorkspaceLeaseRecord::query()->where('group_id', $other->id)->count())->toBe(0);
    expect(TaskWorkspaceLeaseRecord::query()->where('thread_id', $unrelated->id)->count())->toBe(0);
    expect(TaskWorkspaceLeaseRecord::query()->where('group_id', $group->id)->whereNull('instance_id')->count())->toBe(0);
});

it('preserves approved commits on a direct retry after offline cancellation and an active holder', function (): void {
    $group = cancellation_lease_group();
    $group->update(['status' => TaskGroupStatus::Settling]);
    $group->tasks()->update(['status' => TaskStatus::Completed]);
    $sha = cancellation_lease_approval($group);
    $removed = cancellation_lease_remover();
    $publisher = Mockery::mock(TaskPullRequestPublisher::class);
    $publisher->shouldReceive('push')->once()->withArgs(function (Task $pushed, string $commit) use ($group, $sha, $removed): bool {
        expect($removed->ids)->toBe([]);
        $removed->expectedLease = TaskWorkspaceLeaseRecord::query()->where('active_instance_id', $group->taskable_id)->sole()->id;

        return $pushed->id === $group->id && $commit === $sha;
    });
    app()->instance(TaskPullRequestPublisher::class, $publisher);
    bind_task_node_reachability(unreachable: true);
    app(CancelTaskGroupAction::class)->execute($group);
    expect($group->fresh()->status)->toBe(TaskGroupStatus::Cancelled);
    expect($removed->ids)->toBe([]);
    bind_task_node_reachability();
    $leases = app(TaskWorkspaceLease::class);
    $holder = $leases->acquire($group, 'check', 'busy-check');

    expect(fn () => app(CancelTaskGroupAction::class)->execute($group))->toThrow(TaskWorkspaceWaiting::class);
    expect($holder->fresh()->status)->toBe('held');
    $leases->release($holder);
    app(CancelTaskGroupAction::class)->execute($group);
    app(CancelTaskGroupAction::class)->execute($group);

    expect($removed->ids)->toBe([$holder->instance_id]);
    expect($group->fresh()->status)->toBe(TaskGroupStatus::Cancelled);
    expect($group->fresh()->taskable_id)->toBeNull();
    expect(TaskWorkspaceLeaseRecord::query()->whereIn('status', ['held', 'queued'])->count())->toBe(0);
});

it('retains approved work when publication fails on a direct offline cancellation retry', function (bool $pending): void {
    $group = cancellation_lease_group();
    $group->update(['status' => TaskGroupStatus::Settling]);
    $group->tasks()->update(['status' => TaskStatus::Completed]);
    cancellation_lease_approval($group);
    $instance = $group->taskable;
    $removed = cancellation_lease_remover();
    bind_task_node_reachability(unreachable: true);
    app(CancelTaskGroupAction::class)->execute($group);
    bind_task_node_reachability();
    $publisher = Mockery::mock(TaskPullRequestPublisher::class);
    $publisher->shouldReceive('push')->once()->andThrow($pending ? TaskPullRequestException::pending('Publication is not complete.') : new TaskPullRequestException('Publication is not complete.'));
    app()->instance(TaskPullRequestPublisher::class, $publisher);

    expect(fn () => app(CancelTaskGroupAction::class)->execute($group))->toThrow(function (ResourceOperationException $exception): void {
        expect($exception->errorCode)->toBe('tasks.push_failed');
        expect($exception->status)->toBe(502);
        expect($exception->getMessage())->not->toContain('remains settling');
    });

    expect($removed->ids)->toBe([]);
    expect($group->fresh()->status)->toBe(TaskGroupStatus::Cancelled);
    expect($group->fresh()->taskable_id)->toBe($instance->id);
    $this->assertModelExists($instance);
})->with(['failed push' => false, 'queued push' => true]);

/** A leftover checkout whose VM is already gone uses the real generic removal path. */
function cancellation_lease_group(): Task
{
    $group = TaskOperationFixture::create();
    $driver = new FakeAgentDriver('pi');
    $driver->observation = new AgentObservation(AgentThreadState::Done, turnId: 'completed');
    $registry = new AgentDriverRegistry([$driver]);
    app()->instance(AgentDriverRegistry::class, $registry);
    app()->instance(AgentThreadObserver::class, new AgentThreadObserver($registry));
    TaskVm::query()->where('group_id', $group->id)->update(['state' => TaskVmState::Destroyed]);

    return $group;
}

function cancellation_lease_approval(Task $group): string
{
    $sha = str_repeat('a', 40);
    TaskComment::query()->create([
        'task_group_id' => $group->id, 'task_id' => $group->tasks()->sole()->id,
        'type' => 'approved', 'body' => 'Approved.', 'author' => 'reviewer', 'commit_sha' => $sha, 'posted_at' => now(),
    ]);

    return $sha;
}

function cancellation_lease_remover(): object
{
    $remover = new class implements InstanceRemover
    {
        /** @var list<int> */
        public array $ids = [];

        public ?int $expectedLease = null;

        public function execute(Instance $instance, bool $force): InstanceRemoval
        {
            $owner = TaskWorkspaceLeaseRecord::query()->where('active_instance_id', $instance->id)->sole();
            expect($owner->kind)->toBe('teardown');
            if ($this->expectedLease !== null) {
                expect($owner->id)->toBe($this->expectedLease);
            }
            $this->ids[] = $instance->id;
            $instance->delete();

            return new InstanceRemoval;
        }
    };
    app()->instance(InstanceRemover::class, $remover);

    return $remover;
}
