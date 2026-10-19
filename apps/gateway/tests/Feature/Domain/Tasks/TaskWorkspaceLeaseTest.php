<?php

declare(strict_types=1);

use App\Actions\Tasks\RemoveTaskWorkspaceAction;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Instances\InstanceRemover;
use App\Domain\Tasks\AgentDriverException;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\AgentObservation;
use App\Domain\Tasks\AgentThreadObserver;
use App\Domain\Tasks\AgentThreadState;
use App\Domain\Tasks\DeliverablePathRepository;
use App\Domain\Tasks\TaskAssistance;
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskCheckReading;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskPullRequestPublisher;
use App\Domain\Tasks\TaskReviewedCommitSource;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskSubtaskRunner;
use App\Domain\Tasks\TaskThreadRole;
use App\Domain\Tasks\TaskWorkspaceLease;
use App\Domain\Tasks\TaskWorkspaceSnapshot;
use App\Domain\Tasks\TaskWorkspaceWaiting;
use App\Infrastructure\Processes\CommandResult;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskReviewedCommit;
use App\Models\TaskVm;
use App\Models\TaskWorkspaceLeaseRecord;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeAgentDriver;
use Tests\Support\FakeTaskCheckRunner;

beforeEach(function (): void {
    tick_bind_defaults();
});

describe('workspace lease', function (): void {
    it('serializes every actor pair and admits the queued waiter after release', function (string $first, string $second): void {
        $group = tick_group();
        $leases = app(TaskWorkspaceLease::class);
        $holder = $leases->acquire($group, $first, 'first');
        expect(fn () => $leases->acquire($group, $second, 'second'))->toThrow(TaskWorkspaceWaiting::class);
        expect($leases->status($group)[0]['waiters'])->toHaveCount(1);
        $leases->release($holder);
        $next = $leases->acquire($group, $second, 'second');
        expect($next->id)->not->toBe($holder->id);
        expect($leases->status($group)[0]['holder']['owner'])->toBe('second');
    })->with(function (): iterable {
        $actors = ['implementer', 'reviewer', 'check', 'main_merge', 'publication', 'settle', 'teardown', 'verdict'];
        foreach ($actors as $first) {
            foreach ($actors as $second) {
                yield $first.' / '.$second => [$first, $second];
            }
        }
    });

    it('does not let a later actor jump a queued waiter', function (): void {
        $group = tick_group();
        $leases = app(TaskWorkspaceLease::class);
        $holder = $leases->acquire($group, 'check', 'first');
        expect(fn () => $leases->acquire($group, 'reviewer', 'second'))->toThrow(TaskWorkspaceWaiting::class);
        $leases->release($holder);
        expect(fn () => $leases->acquire($group, 'publication', 'third'))->toThrow(TaskWorkspaceWaiting::class);
        expect($leases->acquire($group, 'reviewer', 'second')->owner)->toBe('second');
    });
});

function lease_stopped_driver(): FakeAgentDriver
{
    $driver = new FakeAgentDriver('pi');
    $driver->observation = new AgentObservation(AgentThreadState::Done, turnId: 'old');
    $registry = new AgentDriverRegistry([$driver]);
    app()->instance(AgentDriverRegistry::class, $registry);
    app()->instance(AgentThreadObserver::class, new AgentThreadObserver($registry));

    return $driver;
}

function lease_review_anchor(Task $group): TaskWorkspaceLeaseRecord
{
    $leases = app(TaskWorkspaceLease::class);
    $lease = $leases->acquire($group, 'reviewer', 'baseline');
    $leases->release($lease);

    return $lease;
}

it('retains a persisted turn through restart, unknown state, and an old stopped observation', function (): void {
    $group = tick_group();
    $driver = lease_stopped_driver();
    app()->instance(TaskCheckRunner::class, new FakeTaskCheckRunner);
    $thread = AgentThread::query()->findOrFail($group->tasks->first()->implementer_agent_thread_id);
    $leases = app(TaskWorkspaceLease::class);
    $lease = $leases->turn($group, TaskThreadRole::Implementer, 'thread:'.$thread->id, $thread->id);
    $leases->dispatch($thread, $driver->observation);
    $restarted = new TaskWorkspaceLease;
    $driver->observation = new AgentObservation(null);
    $restarted->reconcile($group);
    expect($lease->fresh()->status)->toBe('held');
    $driver->observation = new AgentObservation(AgentThreadState::Done, turnId: 'old');
    $restarted->reconcile($group);
    expect($lease->fresh()->status)->toBe('held');
    $driver->observation = new AgentObservation(AgentThreadState::Done, turnId: 'new');
    $restarted->reconcile($group);
    expect($lease->fresh()->status)->toBe('released');
});

it('does not mistake an old stopped turn for a prepared dispatch and releases removed preparation only', function (): void {
    $group = tick_group();
    lease_stopped_driver();
    app()->instance(TaskCheckRunner::class, new FakeTaskCheckRunner);
    $thread = AgentThread::query()->findOrFail($group->tasks->first()->implementer_agent_thread_id);
    $leases = app(TaskWorkspaceLease::class);
    $lease = $leases->turn($group, TaskThreadRole::Implementer, 'thread:'.$thread->id, $thread->id);
    $leases->reconcile($group);
    expect($lease->fresh()->status)->toBe('held');
    $thread->delete();
    $leases->reconcile($group);
    expect($lease->fresh()->status)->toBe('released');
});

it('retains lost check ownership and releases only a finished matching process', function (): void {
    $group = tick_group();
    $task = $group->tasks->first();
    $check = $task->checks()->create(['kind' => TaskCheckKind::Handoff, 'status' => TaskCheckStatus::Cancelled,
        'pid' => 321, 'process_started' => 'start', 'started_at' => now(), 'head_before' => 'a', 'tree_before' => 'b']);
    $leases = app(TaskWorkspaceLease::class);
    $lease = $leases->acquire($group, 'check', 'check:'.$task->id);
    $lease->update(['pid' => 321, 'process_started' => 'start', 'check_id' => $check->id]);
    $leases->finishedCheck($check, TaskCheckReading::lost('wrapper missing'));
    expect($lease->fresh()->status)->toBe('held');
    $leases->finishedCheck($check, TaskCheckReading::finished(0, 'a', 'c', [], 'done'));
    expect($lease->fresh()->status)->toBe('released')->and($lease->fresh()->tree_after)->toBe('c');
});

it('admits a queued reviewer before capturing the post-check dispatch baseline', function (): void {
    $group = tick_group();
    lease_stopped_driver();
    $checks = new FakeTaskCheckRunner;
    app()->instance(TaskCheckRunner::class, $checks);
    $task = $group->tasks->first();
    $task->update(['status' => TaskStatus::Reviewing, 'review_attempt' => 1]);
    $thread = AgentThread::query()->findOrFail($group->reviewer_agent_thread_id);
    $thread->update(['task_id' => $task->id]);
    $leases = app(TaskWorkspaceLease::class);
    $holder = $leases->acquire($group, 'check', 'check:first');
    expect(fn () => $leases->turn($group, TaskThreadRole::Reviewer, 'thread:'.$thread->id, $thread->id))->toThrow(TaskWorkspaceWaiting::class);
    expect($task->fresh()->review_workspace_head)->toBeNull();
    $leases->release($holder);
    $leases->turn($group, TaskThreadRole::Reviewer, 'thread:'.$thread->id, $thread->id);
    $checks->tree = str_repeat('d', 40); // Packet/preparation may change the tree before dispatch.
    $leases->dispatch($thread);
    expect($task->fresh()->review_workspace_tree)->toBe($checks->tree);
});

it('queues baseline admission without creating an unstarted claim and starts once after release', function (): void {
    $group = tick_group();
    lease_stopped_driver();
    $checks = new FakeTaskCheckRunner([TaskCheckReading::running()]);
    app()->instance(TaskCheckRunner::class, $checks);
    $leases = app(TaskWorkspaceLease::class);
    $holder = $leases->acquire($group, 'publication', 'busy');
    $task = $group->tasks->first();
    $start = new ReflectionMethod(TaskSubtaskRunner::class, 'startBaseline');
    expect(fn () => $start->invoke(app(TaskSubtaskRunner::class), app(TaskScheduler::class), $group, $task))->toThrow(TaskWorkspaceWaiting::class);
    expect($task->checks()->count())->toBe(0)->and($checks->starts)->toBe(0);
    $leases->release($holder);
    $start->invoke(app(TaskSubtaskRunner::class), app(TaskScheduler::class), $group, $task);
    expect(fn () => $start->invoke(app(TaskSubtaskRunner::class), app(TaskScheduler::class), $group, $task))->toThrow(TaskWorkspaceWaiting::class);
    expect($task->checks()->count())->toBe(1)->and($checks->starts)->toBe(1)->and($task->fresh()->communication_failures)->toBe(0);
});

it('does not resurrect a completed pre-lease actor when its runtime later becomes unreadable', function (): void {
    $group = tick_group();
    $driver = lease_stopped_driver();
    $leases = app(TaskWorkspaceLease::class);
    $leases->reconcile($group);
    expect(TaskWorkspaceLeaseRecord::query()->where('adopted', true)->where('status', 'released')->count())->toBe(2);
    $driver->observation = null;
    $leases->reconcile($group);
    expect(TaskWorkspaceLeaseRecord::query()->where('status', 'held')->exists())->toBeFalse();
});

it('requires a continuous Orbit lease chain and refuses reviewer or unleased changes', function (): void {
    $group = tick_group();
    $leases = app(TaskWorkspaceLease::class);
    $baseline = lease_review_anchor($group);
    $record = TaskWorkspaceLeaseRecord::query()->create(['instance_id' => $group->taskable_id, 'group_id' => $group->id,
        'kind' => 'check', 'owner' => 'first', 'status' => 'released', 'orbit_mutation' => true, 'acquisition_sequence' => 2, 'head_before' => 'a', 'tree_before' => 'b', 'head_after' => 'a', 'tree_after' => 'c']);
    expect($leases->ownedChange($group, 'a', 'b', new TaskWorkspaceSnapshot('a', 'c'), $baseline->id))->toBeTrue();
    expect($leases->ownedChange($group, 'a', 'b', new TaskWorkspaceSnapshot('outside', 'c'), $baseline->id))->toBeFalse();
    $record->update(['kind' => 'reviewer']);
    expect($leases->ownedChange($group, 'a', 'b', new TaskWorkspaceSnapshot('a', 'c'), $baseline->id))->toBeFalse();
    $record->delete();
    $group->tasks->first()->checks()->create(['kind' => TaskCheckKind::Handoff, 'status' => TaskCheckStatus::Changed,
        'pid' => 321, 'process_started' => 'start', 'started_at' => now(), 'finished_at' => now(),
        'head_before' => 'a', 'tree_before' => 'b', 'head_after' => 'a', 'tree_after' => 'c']);
    expect($leases->ownedChange($group, 'a', 'b', new TaskWorkspaceSnapshot('a', 'c'), $baseline->id))->toBeFalse();
});

it('does not attribute an outside edit observed under a read-only verdict lease to Orbit', function (): void {
    $group = tick_group();
    lease_stopped_driver();
    $checks = new FakeTaskCheckRunner;
    app()->instance(TaskCheckRunner::class, $checks);
    $head = $checks->head;
    $tree = $checks->tree;
    $leases = app(TaskWorkspaceLease::class);
    $baseline = lease_review_anchor($group);
    $leases->inspect($group, function () use ($checks): bool {
        $checks->tree = str_repeat('e', 40);

        return true;
    });
    expect(TaskWorkspaceLeaseRecord::query()->where('kind', 'verdict')->sole()->getAttribute('orbit_mutation'))->toBeFalse();
    expect($leases->ownedChange($group, $head, $tree, new TaskWorkspaceSnapshot($head, $checks->tree), $baseline->id))->toBeFalse();
});

it('releases a confirmed rejected send but retains an unknown send result', function (bool $rejected): void {
    $group = tick_group();
    $driver = new class('pi') extends FakeAgentDriver
    {
        public bool $reject = false;

        public function send(AgentThread $thread, string $message, ?string $key = null): void
        {
            $exception = new AgentDriverException('Send failed.');
            $exception->turnRejected = $this->reject;
            throw $exception;
        }
    };
    $driver->reject = $rejected;
    $driver->observation = new AgentObservation(AgentThreadState::Done, turnId: 'old');
    $registry = new AgentDriverRegistry([$driver]);
    app()->instance(AgentThreadObserver::class, new AgentThreadObserver($registry));
    $thread = AgentThread::query()->findOrFail($group->tasks->first()->implementer_agent_thread_id);
    expect(fn () => $registry->get('pi')->send($thread, 'Work.', 'stable-key'))->toThrow(AgentDriverException::class);
    expect(TaskWorkspaceLeaseRecord::query()->latest('id')->firstOrFail()->status)->toBe($rejected ? 'released' : 'held');
})->with([true, false]);

it('retries only the identical keyed command without opening another lease or rewriting the baseline', function (): void {
    $group = tick_group();
    $driver = new class('pi') extends FakeAgentDriver
    {
        public int $attempts = 0;

        public function send(AgentThread $thread, string $message, ?string $key = null): void
        {
            if (++$this->attempts === 1) {
                throw new AgentDriverException('Reply lost.');
            }
        }
    };
    $driver->observation = new AgentObservation(AgentThreadState::Done, turnId: 'old');
    $registry = new AgentDriverRegistry([$driver]);
    app()->instance(AgentThreadObserver::class, new AgentThreadObserver($registry));
    $thread = AgentThread::query()->findOrFail($group->tasks->first()->implementer_agent_thread_id);
    $leased = $registry->get('pi');
    expect(fn () => $leased->send($thread, 'Same command.', 'stable'))->toThrow(AgentDriverException::class);
    $lease = TaskWorkspaceLeaseRecord::query()->latest('id')->firstOrFail();
    $leased->send($thread, 'Same command.', 'stable');
    expect($driver->attempts)->toBe(2)->and($lease->fresh()->status)->toBe('held')
        ->and(TaskWorkspaceLeaseRecord::query()->where('adopted', false)->count())->toBe(1);
    expect(fn () => $leased->send($thread, 'Changed command.', 'stable'))->toThrow(TaskWorkspaceWaiting::class);
    expect(fn () => $leased->send($thread, 'New command.', 'different'))->toThrow(TaskWorkspaceWaiting::class);
    expect($driver->attempts)->toBe(2);
});

it('gives an unkeyed send a durable retry identity and admits only the same message', function (): void {
    $group = tick_group();
    $driver = new class('pi') extends FakeAgentDriver
    {
        /** @var list<string|null> */
        public array $sentKeys = [];

        public function send(AgentThread $thread, string $message, ?string $key = null): void
        {
            $this->sentKeys[] = $key;
            if (count($this->sentKeys) === 1) {
                throw new AgentDriverException('Reply lost.');
            }
        }
    };
    $driver->observation = new AgentObservation(AgentThreadState::Done, turnId: 'old');
    $registry = new AgentDriverRegistry([$driver]);
    app()->instance(AgentThreadObserver::class, new AgentThreadObserver($registry));
    $thread = AgentThread::query()->findOrFail($group->tasks->first()->implementer_agent_thread_id);
    expect(fn () => $registry->get('pi')->send($thread, 'Continue.'))->toThrow(AgentDriverException::class);
    $registry->get('pi')->send($thread, 'Continue.');
    expect($driver->sentKeys)->toHaveCount(2)->and($driver->sentKeys[0])->not->toBeNull()
        ->and($driver->sentKeys[1])->toBe($driver->sentKeys[0]);
    expect(fn () => $registry->get('pi')->send($thread, 'Different turn.'))->toThrow(TaskWorkspaceWaiting::class);
});

it('continues paused input within the same lease and completion fence', function (): void {
    $group = tick_group();
    $driver = lease_stopped_driver();
    $registry = new AgentDriverRegistry([$driver]);
    $thread = AgentThread::query()->findOrFail($group->tasks->first()->implementer_agent_thread_id);
    $leases = app(TaskWorkspaceLease::class);
    $lease = $leases->turn($group, TaskThreadRole::Implementer, 'thread:'.$thread->id, $thread->id);
    $leases->dispatch($thread, $driver->observation);
    $driver->observation = new AgentObservation(AgentThreadState::AskingForInput, turnId: 'new');
    $registry->get('pi')->send($thread, 'Continue without input.');
    expect($lease->fresh()->status)->toBe('held')->and($lease->fresh()->getAttribute('previous_turn_id'))->toBe('old');
    $driver->observation = new AgentObservation(AgentThreadState::Done, turnId: 'new');
    $leases->reconcile($group);
    expect($lease->fresh()->status)->toBe('released');
});

it('preserves merge provenance when the same lease continues into its detached gate', function (): void {
    $group = tick_group();
    lease_stopped_driver();
    $checks = new FakeTaskCheckRunner;
    app()->instance(TaskCheckRunner::class, $checks);
    $leases = app(TaskWorkspaceLease::class);
    $baseline = lease_review_anchor($group);
    $task = $group->tasks->first();
    $beforeHead = $checks->head;
    $beforeTree = $checks->tree;
    $leases->run($group, 'main_merge', 'merge', function () use ($group, $task, $checks, $leases): void {
        $checks->head = str_repeat('c', 40);
        $checks->tree = str_repeat('d', 40);
        $process = $leases->startCheck($group, $task, 'check');
        $check = $task->checks()->where('pid', $process->pid)->where('process_started', $process->started)->sole();
        $check->update(['kind' => TaskCheckKind::BaseMerge]);
        expect(TaskWorkspaceLeaseRecord::query()->where('status', 'held')->sole()->check_id)->toBe($check->id);
        $leases->finishedCheck($check, TaskCheckReading::finished(0, $checks->head, $checks->tree, [], 'done'));
    });
    expect($leases->ownedChange($group, $beforeHead, $beforeTree, new TaskWorkspaceSnapshot($checks->head, $checks->tree), $baseline->id))->toBeTrue();
});

it('refuses historical merge attribution without a review baseline lease', function (): void {
    $group = tick_group();
    $task = $group->tasks->first();
    $head = str_repeat('a', 40);
    $tree = str_repeat('b', 40);
    $merged = str_repeat('c', 40);
    $mergedTree = str_repeat('d', 40);
    TaskReviewedCommit::query()->create(['task_id' => $group->id, 'sha' => $head, 'source' => TaskReviewedCommitSource::OrbitPush, 'review_task_id' => $task->id]);
    $task->checks()->create(['kind' => TaskCheckKind::BaseMerge, 'status' => TaskCheckStatus::Passed,
        'pid' => 123, 'process_started' => 'start', 'started_at' => now()->subMinute(), 'finished_at' => now()->subSecond(),
        'head_before' => $merged, 'tree_before' => $mergedTree, 'head_after' => $merged, 'tree_after' => $mergedTree]);
    $leases = app(TaskWorkspaceLease::class);
    $snapshot = new TaskWorkspaceSnapshot($merged, $mergedTree, $head, $mergedTree, $tree, str_repeat('e', 40));
    expect($leases->ownedChange($group, $head, $tree, $snapshot))->toBeFalse();
    TaskReviewedCommit::query()->create(['task_id' => $group->id, 'sha' => $merged, 'source' => TaskReviewedCommitSource::BaseMerge, 'review_task_id' => $task->id]);
    expect($leases->ownedChange($group, $head, $tree, new TaskWorkspaceSnapshot($merged, 'outside-tree', $head, $mergedTree, $tree, str_repeat('e', 40))))->toBeFalse();
    expect($leases->ownedChange($group, $head, $tree, new TaskWorkspaceSnapshot($merged, $mergedTree, $head, $mergedTree, 'different-base', str_repeat('e', 40))))->toBeFalse();
    expect($leases->ownedChange($group, $head, $tree, $snapshot))->toBeFalse();
    expect(TaskWorkspaceLeaseRecord::query()->where('owner', 'like', 'recorded-merge:%')->exists())->toBeFalse();
});

it('retains uncertain teardown ownership but releases a confirmed remote refusal', function (int $exitCode): void {
    $group = tick_group();
    TaskVm::query()->where('group_id', $group->id)->delete();
    lease_stopped_driver();
    app()->instance(TaskCheckRunner::class, new FakeTaskCheckRunner);
    $remover = new class($exitCode) implements InstanceRemover
    {
        public int $calls = 0;

        public function __construct(private int $exitCode) {}

        public function execute(Instance $instance, bool $force): InstanceRemoval
        {
            $this->calls++;
            throw new RuntimeConvergenceException('remove', 'instance.remove_failed', 'Remote removal failed.', result: new CommandResult($this->exitCode, '', '', 1, false));
        }
    };
    $action = new RemoveTaskWorkspaceAction($remover, app(TaskPullRequestPublisher::class));
    expect(fn () => $action->remove($group))->toThrow(RuntimeConvergenceException::class);
    $lease = TaskWorkspaceLeaseRecord::query()->where('kind', 'teardown')->sole();
    expect($lease->status)->toBe($exitCode === 255 ? 'held' : 'released');
    if ($exitCode === 255) {
        expect($lease->getAttribute('remote_uncertain'))->toBeTrue();
        expect(fn () => $action->remove($group))->toThrow(TaskWorkspaceWaiting::class);
        expect($remover->calls)->toBe(1);
    }
})->with([1, 255]);

it('acknowledges a completed keyed send after restart without reopening its turn fence', function (): void {
    $group = tick_group();
    $driver = lease_stopped_driver();
    app()->instance(TaskCheckRunner::class, new FakeTaskCheckRunner);
    $thread = AgentThread::query()->findOrFail($group->tasks->first()->implementer_agent_thread_id);
    $registry = app(AgentDriverRegistry::class);
    $registry->get('pi')->send($thread, 'Same command.', 'durable');
    $driver->observation = new AgentObservation(AgentThreadState::Done, turnId: 'durable');
    app()->instance(TaskWorkspaceLease::class, new TaskWorkspaceLease);
    $registry->get('pi')->send($thread->fresh(), 'Same command.', 'durable');
    expect(collect($driver->calls)->where('operation', 'send'))->toHaveCount(1);
    expect(TaskWorkspaceLeaseRecord::query()->where('status', 'held')->exists())->toBeFalse();
    expect(app(TaskWorkspaceLease::class)->pendingSend($thread, 'durable', includeCompleted: true))->toBe(['key' => 'durable', 'message' => 'Same command.']);
    expect(fn () => $registry->get('pi')->send($thread->fresh(), 'Changed command.', 'durable'))->toThrow(TaskWorkspaceWaiting::class);
    $leases = app(TaskWorkspaceLease::class);
    expect($leases->acquire($group, 'publication', 'after-completion')->status)->toBe('held');
});

it('drops a cancelled subtasks queued baseline and admits its successor without discarding a holder', function (): void {
    $group = tick_group();
    AgentThread::query()->where('task_group_id', $group->id)->delete();
    $group->tasks()->update(['implementer_agent_thread_id' => null, 'reviewer_agent_thread_id' => null]);
    $group->update(['implementer_agent_thread_id' => null, 'reviewer_agent_thread_id' => null]);
    $group->refresh()->load('tasks');
    lease_stopped_driver();
    $checks = new FakeTaskCheckRunner([TaskCheckReading::running()]);
    app()->instance(TaskCheckRunner::class, $checks);
    $leases = app(TaskWorkspaceLease::class);
    $holder = $leases->acquire($group, 'publication', 'busy');
    $task = $group->tasks->first();
    $next = Task::query()->create(['parent_id' => $group->id, 'position' => 2, 'title' => 'Next', 'brief' => 'Next', 'status' => TaskStatus::Todo]);
    $start = new ReflectionMethod(TaskSubtaskRunner::class, 'startBaseline');
    expect(fn () => $start->invoke(app(TaskSubtaskRunner::class), app(TaskScheduler::class), $group, $task))->toThrow(TaskWorkspaceWaiting::class);
    $queued = TaskWorkspaceLeaseRecord::query()->where('owner', 'check:'.$task->id)->sole();
    expect($queued->getAttribute('task_id'))->toBe($task->id)->and($task->checks()->count())->toBe(0);
    app(TaskScheduler::class)->cancelRunningSubtask($group, $task, static function (): void {});
    expect($task->fresh()->status)->toBe(TaskStatus::Cancelled)->and($queued->fresh()->status)->toBe('cancelled');
    expect($holder->fresh()->status)->toBe('held')->and($checks->starts)->toBe(0);
    $leases->release($holder);
    $start->invoke(app(TaskSubtaskRunner::class), app(TaskScheduler::class), $group->fresh(), $next->fresh());
    expect($checks->starts)->toBe(1)->and($next->fresh()->communication_failures)->toBe(0);
});

it('leases immutable deliverable validation and reuses an existing prepared actor on retry', function (bool $pending): void {
    $group = tick_group();
    lease_stopped_driver();
    app()->instance(TaskCheckRunner::class, new FakeTaskCheckRunner);
    $task = $group->tasks->first();
    $task->update(['subtask_start_commit' => str_repeat('a', 40), 'deliverables' => [['id' => 'source', 'type' => 'file', 'path' => 'existing.php', 'change' => 'modified']]]);
    if ($pending) {
        AgentThread::query()->create(['task_group_id' => $group->id, 'task_id' => $task->id, 'node_id' => $group->taskable->node_id,
            'driver' => 'pi', 'runtime_key' => 'node:'.$group->taskable->node_id, 'external_id' => 'pending:path-validation', 'role' => 'implementer']);
    }
    $repository = new class implements DeliverablePathRepository
    {
        public int $reads = 0;

        public function defaultBranchCommit(Project $project): string
        {
            return str_repeat('a', 40);
        }

        public function files(Project $project, string $commit, ?Instance $workspace = null): array
        {
            $this->reads++;
            expect(TaskWorkspaceLeaseRecord::query()->where('active_instance_id', $workspace->id)->exists())->toBeTrue();

            return ['existing.php'];
        }
    };
    app()->instance(DeliverablePathRepository::class, $repository);
    $leases = app(TaskWorkspaceLease::class);
    $holder = $leases->acquire($group, 'publication', 'busy');
    $validate = new ReflectionMethod(TaskSubtaskRunner::class, 'validateImplementerDeliverablePaths');
    expect(fn () => $validate->invoke(app(TaskSubtaskRunner::class), app(TaskScheduler::class), $task, $group))->toThrow(TaskWorkspaceWaiting::class);
    expect($repository->reads)->toBe(0);
    $leases->release($holder);
    expect($validate->invoke(app(TaskSubtaskRunner::class), app(TaskScheduler::class), $task, $group))->toBeTrue();
    expect($repository->reads)->toBe(1);
    expect(TaskWorkspaceLeaseRecord::query()->where('status', 'queued')->exists())->toBeFalse();
})->with([false, true]);

it('does not credit an outside edit observed during metadata-only remote work to Orbit', function (): void {
    $group = tick_group();
    lease_stopped_driver();
    $checks = new FakeTaskCheckRunner;
    app()->instance(TaskCheckRunner::class, $checks);
    $leases = app(TaskWorkspaceLease::class);
    $baseline = lease_review_anchor($group);
    $head = $checks->head;
    $tree = $checks->tree;
    $leases->run($group, 'publication', 'metadata-only', function () use ($group, $leases, $checks): void {
        $record = $leases->remoteStarted($group->taskable);
        $checks->tree = str_repeat('e', 40);
        $leases->remoteFinished($record);
    });
    expect($leases->ownedChange($group, $head, $tree, new TaskWorkspaceSnapshot($head, $checks->tree), $baseline->id))->toBeFalse();
});

it('never reuses a mutation before the current reviewer baseline after a checkout returns to the same tree', function (): void {
    $group = tick_group();
    $leases = app(TaskWorkspaceLease::class);
    foreach ([['a', 'b', 1], ['b', 'a', 2]] as [$before, $after, $sequence]) {
        TaskWorkspaceLeaseRecord::query()->create(['instance_id' => $group->taskable_id, 'group_id' => $group->id,
            'kind' => 'check', 'owner' => 'old:'.$sequence, 'status' => 'released', 'orbit_mutation' => true,
            'acquisition_sequence' => $sequence, 'head_before' => 'head', 'tree_before' => $before, 'head_after' => 'head', 'tree_after' => $after]);
    }
    $baseline = lease_review_anchor($group);
    expect($leases->ownedChange($group, 'head', 'a', new TaskWorkspaceSnapshot('head', 'b'), $baseline->id))->toBeFalse();
});

it('credits a waiter admitted after review even when its request predates the adopted reviewer record', function (): void {
    $group = tick_group();
    $leases = app(TaskWorkspaceLease::class);
    $queued = TaskWorkspaceLeaseRecord::query()->create(['instance_id' => $group->taskable_id, 'group_id' => $group->id,
        'kind' => 'check', 'owner' => 'early-request', 'status' => 'queued']);
    $baseline = TaskWorkspaceLeaseRecord::query()->create(['instance_id' => $group->taskable_id, 'group_id' => $group->id,
        'kind' => 'reviewer', 'owner' => 'adopted-review', 'status' => 'released', 'adopted' => true, 'acquisition_sequence' => 1]);
    $admitted = $leases->acquire($group, 'check', 'early-request');
    expect($admitted->id)->toBeLessThan($baseline->id)->and($admitted->getAttribute('acquisition_sequence'))->toBe(2);
    $admitted->update(['orbit_mutation' => true, 'head_before' => 'head', 'tree_before' => 'a']);
    $leases->release($admitted, new TaskWorkspaceSnapshot('head', 'b'));
    expect($leases->ownedChange($group, 'head', 'a', new TaskWorkspaceSnapshot('head', 'b'), $baseline->id))->toBeTrue();
});

it('adopts pre-lease ownership from another group using the same Instance before admitting new work', function (bool $remoteCheck): void {
    $first = tick_group();
    $other = $first->replicate(['taskable_id', 'taskable_type', ...TaskAssistance::Fields]);
    $other->save();
    // Corrupt pre-owner attachment: the current model correctly refuses this write.
    DB::table('tasks')->where('id', $other->id)->update(['taskable_id' => $first->taskable_id, 'taskable_type' => $first->getRawOriginal('taskable_type')]);
    $other->refresh();
    $subtask = $first->tasks->first()->replicate(TaskAssistance::Fields);
    $subtask->parent_id = $other->id;
    $subtask->save();
    $other->unsetRelations();
    test_link_agent_threads($other, 'other-reviewer', 'other-implementer');
    $driver = lease_stopped_driver();
    if ($remoteCheck) {
        $task = $other->tasks->first();
        $task->checks()->create(['kind' => TaskCheckKind::Handoff, 'status' => TaskCheckStatus::Running,
            'pid' => 0, 'process_started' => '', 'head_before' => '', 'tree_before' => '', 'started_at' => now()]);
    } else {
        $driver->observation = null;
        AgentThread::query()->where('task_group_id', $first->id)->delete();
    }
    $called = false;
    $leases = app(TaskWorkspaceLease::class);
    expect(fn () => $leases->run($first, 'publication', 'new-work', function () use (&$called): void {
        $called = true;
    }))->toThrow(TaskWorkspaceWaiting::class);
    $holder = TaskWorkspaceLeaseRecord::query()->where('status', 'held')->sole();
    expect($called)->toBeFalse()->and($holder->group_id)->toBe($other->id)->and($holder->getAttribute('adopted'))->toBeTrue();
    expect(TaskWorkspaceLeaseRecord::query()->where('status', 'queued')->sole()->group_id)->toBe($first->id);
})->with([true, false]);

it('drains a finished legacy check but fences the next active legacy check before admitting a new actor', function (): void {
    $group = tick_group();
    lease_stopped_driver();
    $checks = new FakeTaskCheckRunner([
        FakeTaskCheckRunner::passed(), TaskCheckReading::running(), FakeTaskCheckRunner::passed(),
    ]);
    app()->instance(TaskCheckRunner::class, $checks);
    $task = $group->tasks->first();
    $rows = [];
    foreach ([123, 456] as $pid) {
        $rows[] = $task->checks()->create(['kind' => TaskCheckKind::Handoff, 'status' => TaskCheckStatus::Running,
            'pid' => $pid, 'process_started' => 'legacy-'.$pid, 'head_before' => $checks->head, 'tree_before' => $checks->tree, 'started_at' => now()]);
    }
    $leases = app(TaskWorkspaceLease::class);
    $entered = 0;
    $operation = function () use (&$entered): void {
        $entered++;
    };
    expect(fn () => $leases->run($group, 'publication', 'after-checks', $operation))->toThrow(TaskWorkspaceWaiting::class);
    expect($rows[0]->fresh()->status)->toBe(TaskCheckStatus::Passed)
        ->and($rows[1]->fresh()->status)->toBe(TaskCheckStatus::Running)
        ->and(TaskWorkspaceLeaseRecord::query()->where('status', 'held')->sole()->check_id)->toBe($rows[1]->id)
        ->and(TaskWorkspaceLeaseRecord::query()->where('status', 'queued')->sole()->owner)->toBe('after-checks')
        ->and($entered)->toBe(0);
    $leases->run($group, 'publication', 'after-checks', $operation);
    expect($rows[1]->fresh()->status)->toBe(TaskCheckStatus::Passed)
        ->and($entered)->toBe(1)
        ->and(TaskWorkspaceLeaseRecord::query()->whereIn('status', ['held', 'queued'])->exists())->toBeFalse()
        ->and($checks->starts)->toBe(0)->and($checks->cancels)->toBe(0);
});
