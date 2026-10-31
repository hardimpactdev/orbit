<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskExecutionLock;
use App\Domain\Tasks\TaskWorkspaceLease;
use App\Domain\Tasks\TaskWorkspaceSnapshot;
use App\Domain\Tasks\TaskWorkspaceWaiting;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Tasks\NativeTaskExecutionLock;
use App\Infrastructure\Tasks\RemoteWorkspaceOperationJournal;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\TaskVm;
use App\Models\TaskWorkspaceLeaseRecord;
use App\Models\TaskWorkspaceOperation;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Tests\Support\FakeTaskCheckRunner;
use Tests\Support\LocalShellSshExecutor;
use Tests\Support\TaskOperationFixture;
use Tests\Support\UnreachableSshExecutor;

beforeEach(function (): void {
    TaskOperationFixture::bindDefaults();
    $this->leaseGroup = TaskOperationFixture::create();
    AgentThread::query()->delete();
    $this->leaseChecks = new FakeTaskCheckRunner;
    app()->instance(TaskCheckRunner::class, $this->leaseChecks);
});

it('recovers a proven dead local owner without stealing live or unknown Gateway ownership', function (string $identity, bool $released): void {
    $leases = app(TaskWorkspaceLease::class);
    $holder = $leases->acquire($this->leaseGroup, 'publication', 'interrupted');
    $process = new Process(['sleep', '60']);
    $process->start();
    $pid = $process->getPid();
    $stat = file_get_contents('/proc/'.$pid.'/stat');
    $started = explode(' ', substr($stat, strrpos($stat, ')') + 2))[19];
    $holder->update(['gateway_pid' => $pid, 'gateway_started' => $started]);
    try {
        if ($identity === 'dead' || $identity === 'old_boot') {
            $process->stop(0);
        }
        if ($identity === 'old_boot') {
            $holder->update(['gateway_boot' => 'previous-boot']);
        }
        if ($identity === 'unknown_host') {
            $holder->update(['gateway_host' => 'unreachable-other-gateway']);
        }
        $leases->reconcile($this->leaseGroup);
        $leases->reconcile($this->leaseGroup);
        expect($holder->fresh()->status)->toBe($released ? 'released' : 'held');
    } finally {
        $process->stop(0);
    }
})->with([
    'dead local process' => ['dead', true],
    'live process' => ['alive', false],
    'another Gateway' => ['unknown_host', false],
    'previous boot' => ['old_boot', true],
]);

it('recovers ownership after a delayed snapshot without crediting its uncertain tree to Orbit', function (bool $outsideEdit): void {
    $leases = app(TaskWorkspaceLease::class);
    $baseline = $leases->acquire($this->leaseGroup, 'reviewer', 'review-baseline');
    $leases->release($baseline);
    $head = $this->leaseChecks->head;
    $before = $this->leaseChecks->tree;
    $mutations = 0;
    $leases->run($this->leaseGroup, 'workspace_fast_forward', 'main-once', function () use ($leases, &$mutations): void {
        $record = $leases->remoteStarted($this->leaseGroup->taskable, changesCheckout: true);
        $mutations++;
        $this->leaseChecks->tree = str_repeat('c', 40);
        $leases->remoteFinished($record);
        $this->leaseChecks->failSnapshot = true;
    });
    $holder = TaskWorkspaceLeaseRecord::query()->where('status', 'held')->sole();
    expect($holder->getAttribute('completion_pending'))->toBeTrue()
        ->and($holder->tree_after)->toBeNull();
    $leases->reconcile($this->leaseGroup);
    expect($holder->fresh()->status)->toBe('held');
    if ($outsideEdit) {
        $this->leaseChecks->tree = str_repeat('e', 40);
    }
    $this->leaseChecks->failSnapshot = false;
    $leases->reconcile($this->leaseGroup);
    expect($holder->fresh()->status)->toBe('released')
        ->and($mutations)->toBe(1)
        ->and($holder->fresh()->getAttribute('causality_uncertain'))->toBeTrue()
        ->and($leases->ownedChange($this->leaseGroup, $head, $before, new TaskWorkspaceSnapshot($head, $this->leaseChecks->tree), $baseline->id))->toBeFalse();
})->with([false, true]);

it('rejects unscoped mutations even when an unrelated holder exists', function (bool $occupied): void {
    $leases = app(TaskWorkspaceLease::class);
    if ($occupied) {
        $leases->acquire($this->leaseGroup, 'reviewer', 'another-actor');
    }
    expect(fn () => $leases->remoteStarted($this->leaseGroup->taskable, changesCheckout: true))->toThrow(TaskWorkspaceWaiting::class);
    expect(TaskWorkspaceOperation::query()->count())->toBe(0);
    if ($occupied) {
        expect(TaskWorkspaceLeaseRecord::query()->sole()->getAttribute('remote_uncertain'))->toBeFalse();
    }
})->with([false, true]);

it('does not hold native admission locks across the operation callback', function (): void {
    $directory = sys_get_temp_dir().'/orbit-short-lease-'.bin2hex(random_bytes(8));
    app()->instance(TaskExecutionLock::class, new NativeTaskExecutionLock($directory));
    try {
        app(TaskWorkspaceLease::class)->run($this->leaseGroup, 'publication', 'probe-locks', function () use ($directory): void {
            foreach ([$this->leaseGroup->id, -$this->leaseGroup->taskable_id] as $key) {
                $handle = fopen($directory.'/task-'.$key.'.lock', 'c+');
                try {
                    expect(flock($handle, LOCK_EX | LOCK_NB))->toBeTrue();
                } finally {
                    fclose($handle);
                }
            }
        });
    } finally {
        (new Filesystem)->deleteDirectory($directory);
    }
});

function workspace_receipt_transport(): array
{
    $directory = sys_get_temp_dir().'/orbit-workspace-receipt-'.bin2hex(random_bytes(8));
    mkdir($directory, 0o700);
    // The runner checkout may be a private mount that host systemd cannot see.
    $transport = new LocalShellSshExecutor($directory);
    $transport->environment['HOME'] = $directory;
    app()->instance(SshExecutor::class, $transport);

    return [$directory, $transport, new SshConnection('127.0.0.1', 'fixture', 22, '/unused-key', '/unused-hosts')];
}

it('reconciles an exact remote completion after a lost SSH response without replaying the command', function (): void {
    [$directory, $transport, $connection] = workspace_receipt_transport();
    $leases = app(TaskWorkspaceLease::class);
    $journal = app(RemoteWorkspaceOperationJournal::class);
    try {
        $leases->run($this->leaseGroup, 'publication', 'lost-reply', function () use ($journal, $transport, $connection, $directory): void {
            $journal->execute($connection, new RemoteCommand(['bash', '-c', 'printf x >> "$1"', '--', $directory.'/executions']), function (RemoteCommand $wrapped) use ($transport, $connection, $directory): CommandResult {
                $result = $transport->execute($connection, $wrapped);
                // Lose a completed execution's response, never disguise a refused supervisor launch.
                expect($result->exitCode)->toBe(0)
                    ->and(file_get_contents($directory.'/executions'))->toBe('x');
                app()->instance(SshExecutor::class, new UnreachableSshExecutor);

                return new CommandResult(255, '', '', 0, false);
            });
        });
        $holder = TaskWorkspaceLeaseRecord::query()->where('status', 'held')->sole();
        app()->instance(SshExecutor::class, $transport);
        expect($holder->getAttribute('remote_uncertain'))->toBeTrue();
        $leases->reconcile($this->leaseGroup);
        $leases->reconcile($this->leaseGroup);
        expect($holder->fresh()->status)->toBe('released')
            ->and(file_get_contents($directory.'/executions'))->toBe('x')
            ->and(TaskWorkspaceOperation::query()->sole()->status)->toBe('finished');
    } finally {
        (new Filesystem)->deleteDirectory($directory);
    }
})->group('privileged');

it('reconciles a refused supervisor launch after a lost SSH response without claiming execution', function (): void {
    [$directory, $transport, $connection] = workspace_receipt_transport();
    mkdir($directory.'/bin');
    file_put_contents($directory.'/bin/sudo', "#!/bin/sh\nexit 1\n");
    chmod($directory.'/bin/sudo', 0o700);
    $transport->environment['PATH'] = $directory.'/bin:'.getenv('PATH');
    $leases = app(TaskWorkspaceLease::class);
    try {
        $leases->run($this->leaseGroup, 'publication', 'refused-launch', function () use ($transport, $connection, $directory): void {
            app(RemoteWorkspaceOperationJournal::class)->execute($connection, new RemoteCommand(['bash', '-c', 'printf x >> "$1"', '--', $directory.'/executions']), function (RemoteCommand $wrapped) use ($transport, $connection): CommandResult {
                $result = $transport->execute($connection, $wrapped);
                expect($result->exitCode)->toBe(78);
                app()->instance(SshExecutor::class, new UnreachableSshExecutor);

                return new CommandResult(255, '', '', 0, false);
            });
        });
        $holder = TaskWorkspaceLeaseRecord::query()->where('status', 'held')->sole();
        expect($holder->getAttribute('remote_uncertain'))->toBeTrue()
            ->and(TaskWorkspaceOperation::query()->sole()->status)->toBe('pending')
            ->and(file_exists($directory.'/executions'))->toBeFalse();
        app()->instance(SshExecutor::class, $transport);
        $leases->reconcile($this->leaseGroup);
        $leases->reconcile($this->leaseGroup);
        expect($holder->fresh()->status)->toBe('released')
            ->and(TaskWorkspaceOperation::query()->sole()->status)->toBe('not_started')
            ->and(file_exists($directory.'/executions'))->toBeFalse();
    } finally {
        (new Filesystem)->deleteDirectory($directory);
    }
});

it('atomically tombstones an operation that never reached SSH and refuses its late original', function (): void {
    [$directory, $transport, $connection] = workspace_receipt_transport();
    $leases = app(TaskWorkspaceLease::class);
    $late = null;
    try {
        $leases->run($this->leaseGroup, 'publication', 'delayed-original', function () use ($connection, &$late): void {
            app(RemoteWorkspaceOperationJournal::class)->execute($connection, new RemoteCommand(['bash', '-c', 'exit 99']), function (RemoteCommand $wrapped) use (&$late): CommandResult {
                $late = $wrapped;

                return new CommandResult(255, '', '', 0, false);
            });
        });
        $leases->reconcile($this->leaseGroup);
        expect(TaskWorkspaceOperation::query()->sole()->status)->toBe('not_started')
            ->and(TaskWorkspaceLeaseRecord::query()->sole()->status)->toBe('released')
            ->and($transport->execute($connection, $late)->exitCode)->toBe(75);
    } finally {
        (new Filesystem)->deleteDirectory($directory);
    }
});

it('keeps uncertainty when a matching running operation loses supervision', function (): void {
    [$directory, $transport, $connection] = workspace_receipt_transport();
    $leases = app(TaskWorkspaceLease::class);
    try {
        $leases->run($this->leaseGroup, 'publication', 'lost-supervisor', function () use ($connection): void {
            app()->instance(SshExecutor::class, new UnreachableSshExecutor);
            app(RemoteWorkspaceOperationJournal::class)->execute($connection, new RemoteCommand(['true']), fn (): CommandResult => new CommandResult(255, '', '', 0, false));
        });
        app()->instance(SshExecutor::class, $transport);
        $operation = TaskWorkspaceOperation::query()->sole();
        $root = $directory.'/.local/state/orbit/task-workspace-operations';
        mkdir($root, 0o700, true);
        file_put_contents($root.'/'.$operation->operation, json_encode(['operation' => $operation->operation, 'binding' => $operation->binding, 'state' => 'running'], JSON_THROW_ON_ERROR));
        $leases->reconcile($this->leaseGroup);
        $leases->reconcile($this->leaseGroup);
        expect(TaskWorkspaceLeaseRecord::query()->sole()->status)->toBe('held')
            ->and($operation->fresh()->status)->toBe('pending');
    } finally {
        (new Filesystem)->deleteDirectory($directory);
    }
});

it('keeps uncertainty if another operation is admitted during a completion probe', function (): void {
    $leases = app(TaskWorkspaceLease::class);
    $holder = $leases->acquire($this->leaseGroup, 'publication', 'probe-race');
    $holder->update(['remote_uncertain' => true]);
    $connection = new SshConnection('127.0.0.1', 'fixture', 22, '/unused-key', '/unused-hosts');
    $operation = TaskWorkspaceOperation::query()->create([
        'lease_id' => $holder->id, 'operation' => bin2hex(random_bytes(16)), 'binding' => 'exact-binding',
        'connection' => get_object_vars($connection),
    ]);
    app()->instance(SshExecutor::class, new class($operation) implements SshExecutor
    {
        public function __construct(private TaskWorkspaceOperation $operation) {}

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            TaskWorkspaceOperation::query()->create([
                'lease_id' => $this->operation->lease_id, 'operation' => bin2hex(random_bytes(16)),
                'binding' => $this->operation->binding, 'connection' => $this->operation->connection,
            ]);

            return new CommandResult(0, json_encode(['operation' => $this->operation->operation, 'binding' => $this->operation->binding, 'state' => 'finished'], JSON_THROW_ON_ERROR), '', 0, false);
        }
    });
    expect(app(RemoteWorkspaceOperationJournal::class)->settled($holder))->toBeFalse()
        ->and($holder->fresh()->getAttribute('remote_uncertain'))->toBeTrue()
        ->and($operation->fresh()->status)->toBe('finished')
        ->and(TaskWorkspaceOperation::query()->where('status', 'pending')->count())->toBe(1);
});

it('requires provider-confirmed destruction before releasing a vanished teardown workspace', function (bool $destroyed): void {
    $group = $this->leaseGroup;
    $leases = app(TaskWorkspaceLease::class);
    $holder = $leases->acquire($group, 'teardown', 'teardown:'.$group->id);
    $holder->update(['remote_uncertain' => true]);
    $instanceId = $group->taskable_id;
    $group->taskable()->dissociate();
    $group->save();
    Instance::query()->whereKey($instanceId)->delete();
    if ($destroyed) {
        TaskVm::query()->where('group_id', $group->id)->update(['state' => TaskVmState::Destroyed]);
    }
    $leases->reconcile($group->fresh());
    expect($holder->fresh()->status)->toBe($destroyed ? 'released' : 'held');
})->with([false, true]);

it('recovers a dead Gateway preparation without releasing a dispatched turn', function (bool $dispatched): void {
    $group = $this->leaseGroup;
    $thread = AgentThread::query()->create([
        'task_group_id' => $group->id, 'task_id' => $group->tasks->first()->id,
        'node_id' => $group->taskable->node_id, 'runtime_key' => 'node:'.$group->taskable->node_id, 'driver' => 'pi', 'external_id' => 'prepared-reviewer',
        'role' => 'reviewer', 'state' => 'idle',
    ]);
    $leases = app(TaskWorkspaceLease::class);
    $holder = $leases->acquire($group, 'reviewer', 'thread:'.$thread->id, $thread->id, $thread->task_id);
    $holder->update(['gateway_boot' => 'previous-boot', 'dispatch_started_at' => $dispatched ? now() : null]);
    $leases->reconcile($group);
    expect($holder->fresh()->status)->toBe($dispatched ? 'held' : 'released');
})->with([false, true]);

it('does not attribute an outside edit after synchronous mutation when Gateway dies before after snapshot', function (): void {
    $group = $this->leaseGroup;
    $checks = $this->leaseChecks;
    $leases = app(TaskWorkspaceLease::class);
    $baseline = $leases->acquire($group, 'reviewer', 'review-baseline');
    $leases->release($baseline);
    $head = $checks->head;
    $before = $checks->tree;
    $lease = $leases->acquire($group, 'workspace_fast_forward', 'interrupted-callback');
    $lease->update(['head_before' => $head, 'tree_before' => $before]);
    $leases->within($lease, function () use ($leases, $group, $checks): void {
        $record = $leases->remoteStarted($group->taskable, changesCheckout: true);
        $checks->tree = str_repeat('c', 40);
        $leases->remoteFinished($record);
    });
    // The Gateway was killed after a known mutation finished, before run's finally.
    $lease->update(['gateway_boot' => 'previous-boot']);
    expect($lease->fresh()->getAttribute('completion_pending'))->toBeFalse();
    // A distinct outside writer edits while no Gateway process can read the after-tree.
    $checks->tree = str_repeat('e', 40);
    $leases->reconcile($group);
    expect($lease->fresh()->status)->toBe('released');
    expect($lease->fresh()->tree_after)->toBe(str_repeat('e', 40));
    expect($leases->ownedChange($group, $head, $before, new TaskWorkspaceSnapshot($head, $checks->tree), $baseline->id))->toBeFalse();
});
