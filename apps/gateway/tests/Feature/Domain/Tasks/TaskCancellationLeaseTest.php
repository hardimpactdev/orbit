<?php

declare(strict_types=1);

use App\Actions\Tasks\CancelTaskGroupAction;
use App\Actions\Tasks\CompleteTaskGroupAction;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Instances\InstanceRemover;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\AgentObservation;
use App\Domain\Tasks\AgentThreadObserver;
use App\Domain\Tasks\AgentThreadState;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskPullRequestPublisher;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskWorkspaceLease;
use App\Domain\Tasks\TaskWorkspaceWaiting;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\Processes\CommandResult;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskVm;
use App\Models\TaskWorkspaceLeaseRecord;
use Tests\Support\FakeAgentDriver;
use Tests\Support\FakeTaskTurnReceipts;

beforeEach(function (): void {
    tick_bind_defaults();
    bind_task_node_reachability();
    app(TaskExtensionState::class)->enable();
});

it('lets later scheduler ticks progress after repeated busy cancellation requests return', function (): void {
    [$group, $task, $receipts] = tick_review([FakeTaskTurnReceipts::contents('approved')]);
    $leases = app(TaskWorkspaceLease::class);
    $holder = $leases->acquire($group, 'check', 'busy-check');

    foreach ([1, 2] as $attempt) {
        expect(fn () => app(CancelTaskGroupAction::class)->execute($group))->toThrow(TaskWorkspaceWaiting::class);
        expect($holder->fresh()->status)->toBe('held');
        expect($group->fresh()->status)->toBe(TaskGroupStatus::Reviewing);
        expect($task->fresh()->status)->toBe(TaskStatus::Reviewing);
        expect(TaskWorkspaceLeaseRecord::query()->where('status', 'queued')->count())->toBe(0);
    }
    $leases->release($holder);
    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    expect($receipts->reads())->toBeGreaterThan(0);
});

it('finishes cancellation on retry after the active owner releases and repeated success is safe', function (): void {
    $group = cancellation_lease_group();
    $instance = $group->taskable;
    $removed = cancellation_lease_remover();
    $leases = app(TaskWorkspaceLease::class);
    $holder = $leases->acquire($group, 'check', 'busy-check');

    expect(fn () => app(CancelTaskGroupAction::class)->execute($group))->toThrow(TaskWorkspaceWaiting::class);
    expect($removed->ids)->toBe([]);
    expect($holder->fresh()->status)->toBe('held');
    $leases->release($holder);
    app(CancelTaskGroupAction::class)->execute($group);
    app(CancelTaskGroupAction::class)->execute($group);
    app(TaskScheduler::class)->tick();

    expect($removed->ids)->toBe([$instance->id]);
    expect($group->fresh()->status)->toBe(TaskGroupStatus::Cancelled);
    expect($group->fresh()->taskable_id)->toBeNull();
    expect($group->tasks()->sole()->status)->toBe(TaskStatus::Cancelled);
    $this->assertModelMissing($instance);
    expect(TaskWorkspaceLeaseRecord::query()->whereIn('status', ['held', 'queued'])->count())->toBe(0);
});

it('keeps approved work until busy cancellation can push and remove under one lease', function (): void {
    $group = cancellation_lease_group();
    $group->update(['status' => TaskGroupStatus::Settling]);
    $group->tasks()->update(['status' => TaskStatus::Completed]);
    $sha = cancellation_lease_approval($group);
    $leases = app(TaskWorkspaceLease::class);
    $holder = $leases->acquire($group, 'check', 'busy-check');
    $removed = cancellation_lease_remover();
    $publisher = Mockery::mock(TaskPullRequestPublisher::class);
    $publisher->shouldReceive('push')->once()->withArgs(function (Task $pushed, string $commit) use ($group, $sha, $removed): bool {
        expect($removed->ids)->toBe([]);
        $owner = TaskWorkspaceLeaseRecord::query()->where('active_instance_id', $group->taskable_id)->sole();
        expect($owner->kind)->toBe('teardown');
        $removed->expectedLease = $owner->id;

        return $pushed->id === $group->id && $commit === $sha;
    });
    app()->instance(TaskPullRequestPublisher::class, $publisher);

    expect(fn () => app(CancelTaskGroupAction::class)->execute($group))->toThrow(TaskWorkspaceWaiting::class);
    expect($holder->fresh()->status)->toBe('held');
    expect(TaskWorkspaceLeaseRecord::query()->where('status', 'queued')->count())->toBe(0);
    expect($group->fresh()->status)->toBe(TaskGroupStatus::Settling);
    $leases->release($holder);
    app(CancelTaskGroupAction::class)->execute($group);
    app(TaskScheduler::class)->tick();

    expect($removed->ids)->toBe([$holder->instance_id]);
    expect($group->fresh()->status)->toBe(TaskGroupStatus::Cancelled);
});

it('lets the sweep consume teardown queued by completion without releasing an active owner', function (): void {
    $group = cancellation_lease_group();
    $group->update(['status' => TaskGroupStatus::Settling]);
    $group->tasks()->update(['status' => TaskStatus::Completed]);
    $removed = cancellation_lease_remover();
    $leases = app(TaskWorkspaceLease::class);
    $holder = $leases->acquire($group, 'check', 'busy-check');

    app(CompleteTaskGroupAction::class)->execute($group);
    app(CompleteTaskGroupAction::class)->execute($group);
    expect($group->fresh()->status)->toBe(TaskGroupStatus::Completed);
    expect($holder->fresh()->status)->toBe('held');
    expect($removed->ids)->toBe([]);
    expect(app(TaskScheduler::class)->removeAbandonedWorkspaces())->toBe(0);
    $leases->release($holder);
    $this->artisan('tasks:tick')->assertSuccessful();
    $this->artisan('tasks:tick')->assertSuccessful();

    expect($removed->ids)->toBe([$holder->instance_id]);
    expect($group->fresh()->taskable_id)->toBeNull();
    expect(TaskWorkspaceLeaseRecord::query()->whereIn('status', ['held', 'queued'])->count())->toBe(0);
});

it('lets the sweep consume a repeated cancelled request and push its approval before removal', function (): void {
    $group = cancellation_lease_group();
    $group->update(['status' => TaskGroupStatus::Cancelled]);
    $group->tasks()->update(['status' => TaskStatus::Completed]);
    $sha = cancellation_lease_approval($group);
    $leases = app(TaskWorkspaceLease::class);
    $holder = $leases->acquire($group, 'check', 'busy-check');
    $removed = cancellation_lease_remover();
    $publisher = Mockery::mock(TaskPullRequestPublisher::class);
    $publisher->shouldReceive('push')->once()->withArgs(function (Task $pushed, string $commit) use ($group, $sha, $removed): bool {
        expect($removed->ids)->toBe([]);
        $removed->expectedLease = TaskWorkspaceLeaseRecord::query()->where('active_instance_id', $group->taskable_id)->sole()->id;

        return $pushed->id === $group->id && $commit === $sha;
    });
    app()->instance(TaskPullRequestPublisher::class, $publisher);

    expect(fn () => app(CancelTaskGroupAction::class)->execute($group))->toThrow(TaskWorkspaceWaiting::class);
    expect(app(TaskScheduler::class)->removeAbandonedWorkspaces())->toBe(0);
    expect($holder->fresh()->status)->toBe('held');
    expect($removed->ids)->toBe([]);
    $leases->release($holder);
    $this->artisan('tasks:tick')->assertSuccessful();
    $this->artisan('tasks:tick')->assertSuccessful();

    expect($removed->ids)->toBe([$holder->instance_id]);
    expect($group->fresh()->status)->toBe(TaskGroupStatus::Cancelled);
    expect($group->fresh()->taskable_id)->toBeNull();
    expect(TaskWorkspaceLeaseRecord::query()->whereIn('status', ['held', 'queued'])->count())->toBe(0);
});

it('keeps an uncertain cancellation removal fenced across repeated requests and scheduler ticks', function (): void {
    $group = cancellation_lease_group();
    $instance = $group->taskable;
    $remover = Mockery::mock(InstanceRemover::class);
    $remover->shouldReceive('execute')->once()->andThrow(new RuntimeConvergenceException(
        'remove', 'instance.remove_failed', 'Remote removal outcome is unknown.',
        result: new CommandResult(255, '', '', 1, false),
    ));
    app()->instance(InstanceRemover::class, $remover);

    expect(fn () => app(CancelTaskGroupAction::class)->execute($group))->toThrow(RuntimeConvergenceException::class);
    $holder = TaskWorkspaceLeaseRecord::query()->where('active_instance_id', $instance->id)->sole();
    expect($holder->getAttribute('remote_uncertain'))->toBeTrue();
    expect(fn () => app(CancelTaskGroupAction::class)->execute($group))->toThrow(TaskWorkspaceWaiting::class);
    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    expect($holder->fresh()->status)->toBe('held');
    expect($holder->fresh()->getAttribute('remote_uncertain'))->toBeTrue();
    expect($group->fresh()->status)->toBe(TaskGroupStatus::Running);
    $this->assertModelExists($instance);
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

/** A leftover checkout whose VM is already gone uses the real generic removal path. */
function cancellation_lease_group(): Task
{
    $group = tick_group();
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
