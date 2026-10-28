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
use App\Domain\Tasks\TaskAssistance;
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskCheckReading;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskPullRequestPublisher;
use App\Domain\Tasks\TaskReviewedCommitSource;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskThreadRole;
use App\Domain\Tasks\TaskWorkspaceLease;
use App\Domain\Tasks\TaskWorkspaceSnapshot;
use App\Domain\Tasks\TaskWorkspaceWaiting;
use App\Infrastructure\Processes\CommandResult;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\InstanceRemoval;
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
        $actors = ['implementer', 'reviewer', 'check', 'workspace_fast_forward', 'publication', 'settle', 'teardown', 'verdict'];
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
    $key = $leases->dispatch($thread, $driver->observation);
    $restarted = new TaskWorkspaceLease;
    $driver->observation = new AgentObservation(null);
    $restarted->reconcile($group);
    expect($lease->fresh()->status)->toBe('held');
    $driver->observation = new AgentObservation(AgentThreadState::Done, turnId: 'old');
    $restarted->reconcile($group);
    expect($lease->fresh()->status)->toBe('held');
    $driver->observation = new AgentObservation(AgentThreadState::Done, turnId: $key);
    $restarted->reconcile($group);
    expect($lease->fresh()->status)->toBe('released');
});

it('retains a keyed dispatch without matching terminal evidence', function (?string $previous, AgentThreadState $state, string $observed): void {
    $group = tick_group();
    $driver = lease_stopped_driver();
    $thread = AgentThread::query()->findOrFail($group->tasks->first()->implementer_agent_thread_id);
    $leases = app(TaskWorkspaceLease::class);
    $lease = $leases->turn($group, TaskThreadRole::Implementer, 'thread:'.$thread->id, $thread->id);
    $leases->dispatch($thread, $previous === null ? null : new AgentObservation(AgentThreadState::Done, turnId: $previous), key: 'owned-turn', message: 'Work.');
    $driver->observation = new AgentObservation($state, turnId: $observed);

    $leases->reconcile($group);

    expect($lease->refresh()->status)->toBe('held');
    expect($lease->getAttribute('dispatch_completed'))->toBeFalse();
    expect($leases->completedSend($thread, 'owned-turn', 'Work.'))->toBeFalse();
    expect(fn () => $leases->acquire($group, 'check', 'competitor', queue: false))->toThrow(TaskWorkspaceWaiting::class);
})->with([
    'unknown previous turn and unrelated terminal key' => [null, AgentThreadState::Done, 'unrelated-turn'],
    'known previous turn and unrelated terminal key' => ['old', AgentThreadState::Done, 'unrelated-turn'],
    'unknown previous turn and unrelated failed key' => [null, AgentThreadState::Failed, 'unrelated-turn'],
    'known previous turn and unrelated failed key' => ['old', AgentThreadState::Failed, 'unrelated-turn'],
    'unknown previous turn and matching idle key' => [null, AgentThreadState::Idle, 'owned-turn'],
    'matching key is idle, not completed' => ['old', AgentThreadState::Idle, 'owned-turn'],
]);

it('preserves terminal reconciliation for adopted pre-existing turns without a dispatch key', function (?AgentThreadState $initial, AgentThreadState $terminal): void {
    $group = tick_group();
    $driver = lease_stopped_driver();
    app()->instance(TaskCheckRunner::class, new FakeTaskCheckRunner);
    $driver->observation = new AgentObservation($initial, turnId: 'pre-existing-turn');
    $leases = app(TaskWorkspaceLease::class);
    $leases->reconcile($group);
    $lease = TaskWorkspaceLeaseRecord::query()->where('group_id', $group->id)->where('status', 'held')->sole();

    expect($lease->getAttribute('adopted'))->toBeTrue();
    expect($lease->dispatch_key)->toBeNull();
    expect(fn () => $leases->acquire($group, 'check', 'competitor', queue: false))->toThrow(TaskWorkspaceWaiting::class);
    $driver->observation = new AgentObservation($terminal, turnId: 'pre-existing-turn');

    $leases->reconcile($group);

    expect($lease->refresh()->status)->toBe('released');
    expect($lease->getAttribute('dispatch_completed'))->toBeTrue();
    expect($leases->acquire($group, 'check', 'competitor', queue: false)->owner)->toBe('competitor');
})->with([
    'working owner' => [AgentThreadState::Working],
    'paused owner' => [AgentThreadState::AskingForInput],
    'unreadable owner' => [null],
])->with([
    'idle with a turn ID' => [AgentThreadState::Idle],
    'done with a turn ID' => [AgentThreadState::Done],
    'failed with a turn ID' => [AgentThreadState::Failed],
]);

it('releases a keyed dispatch after observing its exact terminal request key', function (?string $previous, AgentThreadState $state): void {
    $group = tick_group();
    $driver = lease_stopped_driver();
    app()->instance(TaskCheckRunner::class, new FakeTaskCheckRunner);
    $thread = AgentThread::query()->findOrFail($group->tasks->first()->implementer_agent_thread_id);
    $leases = app(TaskWorkspaceLease::class);
    $lease = $leases->turn($group, TaskThreadRole::Implementer, 'thread:'.$thread->id, $thread->id);
    $leases->dispatch($thread, $previous === null ? null : new AgentObservation(AgentThreadState::Done, turnId: $previous), key: 'owned-turn', message: 'Work.');
    $driver->observation = new AgentObservation($state, turnId: 'owned-turn');

    $leases->reconcile($group);

    expect($lease->refresh()->status)->toBe('released');
    expect($lease->getAttribute('dispatch_completed'))->toBeTrue();
    expect($leases->completedSend($thread, 'owned-turn', 'Work.'))->toBeTrue();
    expect($leases->acquire($group, 'check', 'next')->owner)->toBe('next');
})->with([
    'done after unreadable previous turn' => [null, AgentThreadState::Done],
    'failed after unreadable previous turn' => [null, AgentThreadState::Failed],
    'done after known previous turn' => ['old', AgentThreadState::Done],
    'failed after known previous turn' => ['old', AgentThreadState::Failed],
]);

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
    $key = $leases->dispatch($thread, $driver->observation);
    $driver->observation = new AgentObservation(AgentThreadState::AskingForInput, turnId: $key);
    $registry->get('pi')->send($thread, 'Continue without input.');
    expect($lease->fresh()->status)->toBe('held')->and($lease->fresh()->getAttribute('previous_turn_id'))->toBe('old');
    $driver->observation = new AgentObservation(AgentThreadState::Done, turnId: $key);
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
    $leases->run($group, 'workspace_fast_forward', 'merge', function () use ($group, $task, $checks, $leases): void {
        $checks->head = str_repeat('c', 40);
        $checks->tree = str_repeat('d', 40);
        $process = $leases->startCheck($group, $task, 'check');
        $check = $task->checks()->where('pid', $process->pid)->where('process_started', $process->started)->sole();
        $check->update(['kind' => TaskCheckKind::Handoff]);
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
    $task->checks()->create(['kind' => TaskCheckKind::Handoff, 'status' => TaskCheckStatus::Passed,
        'pid' => 123, 'process_started' => 'start', 'started_at' => now()->subMinute(), 'finished_at' => now()->subSecond(),
        'head_before' => $merged, 'tree_before' => $mergedTree, 'head_after' => $merged, 'tree_after' => $mergedTree]);
    $leases = app(TaskWorkspaceLease::class);
    $snapshot = new TaskWorkspaceSnapshot($merged, $mergedTree, $head, $mergedTree, $tree, str_repeat('e', 40));
    expect($leases->ownedChange($group, $head, $tree, $snapshot))->toBeFalse();
    TaskReviewedCommit::query()->create(['task_id' => $group->id, 'sha' => $merged, 'source' => TaskReviewedCommitSource::OrbitPush, 'review_task_id' => $task->id]);
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

it('refuses a check outside an admitted scope without creating a lease or starting a process', function (): void {
    $group = tick_group();
    $checks = new FakeTaskCheckRunner;
    app()->instance(TaskCheckRunner::class, $checks);

    expect(fn () => app(TaskWorkspaceLease::class)->startCheck($group, $group->tasks->first(), 'check'))
        ->toThrow(TaskWorkspaceWaiting::class, 'A workspace check requires an admitted workspace scope.');

    expect(TaskWorkspaceLeaseRecord::query()->exists())->toBeFalse()
        ->and($group->tasks->first()->checks()->exists())->toBeFalse()->and($checks->starts)->toBe(0);
});

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
