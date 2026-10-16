<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskCheckReading;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskSubtaskRunner;
use App\Domain\Tasks\TaskWorkspaceLease;
use App\Domain\Tasks\TaskWorkspaceWaiting;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Activity;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\TaskCheck;
use App\Models\TaskVm;
use App\Models\TaskWorkspaceLeaseRecord;
use App\Models\TaskWorkspaceOperation;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Tests\Support\FakeTaskCheckRunner;
use Tests\Support\LocalShellSshExecutor;

beforeEach(function (): void {
    tick_bind_defaults();
    $this->group = tick_group();
    AgentThread::query()->delete();
    app()->instance(TaskCheckRunner::class, new FakeTaskCheckRunner);
    $this->receiptHome = sys_get_temp_dir().'/orbit-recovery-'.bin2hex(random_bytes(8));
    mkdir($this->receiptHome, 0o700);
    mkdir($this->receiptHome.'/bin');
    // Exact systemctl property names/states match the native supervision protocol.
    file_put_contents($this->receiptHome.'/bin/systemctl', <<<'SH'
#!/bin/sh
if test "$1" = list-units; then
    if test -f "$HOME/active"; then
        printf '[{"unit":"orbit-workspace-operation-active.service"}]\n'
    elif test -f "$HOME/unreadable"; then
        exit 1
    else
        printf '[]\n'
    fi
elif test "$1" = show; then
    printf 'LoadState=loaded\nActiveState=active\nSubState=running\nControlGroup=\nDescription=fixture\n'
else
    exit 99
fi
SH);
    chmod($this->receiptHome.'/bin/systemctl', 0o700);
    $this->transport = new LocalShellSshExecutor;
    $this->transport->environment = ['HOME' => $this->receiptHome, 'PATH' => $this->receiptHome.'/bin:'.getenv('PATH')];
    $this->connection = new SshConnection('127.0.0.1', 'fixture', 22, '/unused-key', '/unused-hosts');
    app()->instance(SshExecutor::class, $this->transport);
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldReceive('privateKeyPath')->andReturn('/unused-key');
    $hosts = Mockery::mock(KnownHostsStore::class);
    $hosts->shouldReceive('path')->andReturn('/unused-hosts');
    app()->instance(DevelopmentSshExecutor::class, new DevelopmentSshExecutor($this->transport, $keys, $hosts));
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->receiptHome);
});

/** @return array{TaskWorkspaceLeaseRecord, TaskWorkspaceOperation} */
function operator_recovery_holder(): array
{
    $lease = app(TaskWorkspaceLease::class)->acquire(test()->group, 'publication', 'interrupted');
    $lease->update(['gateway_boot' => 'previous-boot', 'remote_uncertain' => true, 'orbit_mutation' => true]);
    $operation = TaskWorkspaceOperation::query()->create([
        'lease_id' => $lease->id, 'operation' => bin2hex(random_bytes(16)),
        'binding' => hash('sha256', $lease->id.':'.$lease->instance_id.':'.$lease->acquisition_sequence),
        'connection' => get_object_vars(test()->connection),
    ]);
    $root = test()->receiptHome.'/.local/state/orbit/task-workspace-operations';
    mkdir($root, 0o700, true);
    // Unreadable receipt must require global proof, never absence-based automatic release.
    file_put_contents($root.'/'.$operation->operation, '{');

    return [$lease, $operation];
}

function operator_protocol(string $mode, string $operation, string $binding, array $arguments = []): CommandResult
{
    return test()->transport->execute(test()->connection, new RemoteCommand([
        'python3', '-c', file_get_contents(resource_path('tasks/workspace-operation.py')),
        $mode, $operation, $binding, '0', ...$arguments,
    ]));
}

it('seals a lost receipt before releasing dead ownership and audits repeated operator recovery', function (): void {
    [$lease, $operation] = operator_recovery_holder();
    $this->group->update(['assistance_requested' => true]);

    $this->artisan('tasks:lease:release', ['lease-id' => (string) $lease->id, '--reason' => 'Investigated missing receipt'])->assertSuccessful();
    $this->artisan('tasks:lease:release', ['lease-id' => (string) $lease->id, '--reason' => 'Confirm completed recovery'])->assertSuccessful();

    expect($lease->fresh()->status)->toBe('released')
        ->and($lease->fresh()->getAttribute('recovery_sealed'))->toBeTrue()
        ->and($lease->fresh()->getAttribute('causality_uncertain'))->toBeTrue()
        ->and($lease->fresh()->tree_after)->toBeNull()
        ->and($operation->fresh()->status)->toBe('finished')
        ->and($this->group->fresh()->assistance_requested)->toBeTrue();
    expect(Activity::query()->where('command', 'tasks:lease:release')->where('status', 'completed')->count())->toBe(2);
    $late = operator_protocol('run', bin2hex(random_bytes(16)), $operation->binding, ['touch', $this->receiptHome.'/late']);
    $payload = operator_protocol('payload', $operation->operation, $operation->binding, ['touch', $this->receiptHome.'/payload']);
    expect($late->exitCode)->toBe(75)->and($payload->exitCode)->toBe(75)
        ->and(file_exists($this->receiptHome.'/late'))->toBeFalse()
        ->and(file_exists($this->receiptHome.'/payload'))->toBeFalse();
});

it('keeps active or unreadable managed supervision sealed and held until positive quiescence', function (string $condition): void {
    [$lease, $operation] = operator_recovery_holder();
    touch($this->receiptHome.'/'.$condition);

    $this->artisan('tasks:lease:release', ['lease-id' => (string) $lease->id, '--reason' => 'Investigated active supervision'])->assertFailed();
    app(TaskWorkspaceLease::class)->remoteFinished($lease);
    app(TaskWorkspaceLease::class)->release($lease);
    app(TaskWorkspaceLease::class)->reconcile($this->group);
    expect($lease->fresh()->status)->toBe('held')
        ->and($lease->fresh()->getAttribute('recovery_sealed'))->toBeTrue()
        ->and($operation->fresh()->status)->toBe('pending');
    expect(Activity::query()->where('command', 'tasks:lease:release')->sole()->status)->toBe('failed');

    unlink($this->receiptHome.'/'.$condition);
    $this->artisan('tasks:lease:release', ['lease-id' => (string) $lease->id, '--reason' => 'Supervision now proven stopped'])->assertSuccessful();
    expect($lease->fresh()->status)->toBe('released');
})->with(['active', 'unreadable']);

it('refuses live foreign and legacy owners or detached checks without terminal evidence', function (string $condition): void {
    [$lease] = operator_recovery_holder();
    if ($condition === 'live') {
        $lease->update(['gateway_boot' => trim(file_get_contents('/proc/sys/kernel/random/boot_id'))]);
    } elseif ($condition === 'foreign') {
        $lease->update(['gateway_host' => 'another-gateway']);
    } elseif ($condition === 'legacy') {
        $lease->update(['journaled' => false]);
    } else {
        $lease->update(['pid' => 0, 'process_started' => 'unknown']);
    }

    $this->artisan('tasks:lease:release', ['lease-id' => (string) $lease->id, '--reason' => 'Unproven owner'])->assertFailed();
    expect($lease->fresh()->status)->toBe('held')->and($lease->fresh()->getAttribute('recovery_sealed'))->toBeFalse();
})->with(['live', 'foreign', 'legacy', 'check']);

it('recovers a missing non VM Instance only through recorded endpoint proof', function (bool $hasEndpoint): void {
    [$lease, $operation] = operator_recovery_holder();
    TaskVm::query()->where('group_id', $this->group->id)->delete();
    $this->group->taskable()->dissociate();
    $this->group->save();
    Instance::query()->whereKey($lease->instance_id)->delete();
    if (! $hasEndpoint) {
        $operation->delete();
    }

    if ($hasEndpoint) {
        $this->artisan('tasks:lease:release', ['lease-id' => (string) $lease->id, '--reason' => 'Removed Instance with saved ownership'])->assertSuccessful();
    } else {
        $this->artisan('tasks:lease:release', ['lease-id' => (string) $lease->id, '--reason' => 'Removed Instance without saved ownership'])->assertFailed();
    }
    expect($lease->fresh()->status)->toBe($hasEndpoint ? 'released' : 'held');
})->with([false, true]);

it('rejects a stale in memory scope after an operator seal before admitting any new SSH', function (): void {
    $leases = app(TaskWorkspaceLease::class);
    $lease = $leases->acquire($this->group, 'publication', 'stale');
    $leases->within($lease, function () use ($leases, $lease): void {
        $lease->update(['recovery_sealed' => true]);
        expect(fn () => $leases->currentScope())->toThrow(TaskWorkspaceWaiting::class);
        expect(fn () => $leases->remoteStarted($this->group->taskable, true))->toThrow(TaskWorkspaceWaiting::class);
    });
    expect(TaskWorkspaceOperation::query()->count())->toBe(0);
});

it('settles a previous boot receipt as unknown without replay or success attribution', function (): void {
    [$lease, $operation] = operator_recovery_holder();
    $path = $this->receiptHome.'/.local/state/orbit/task-workspace-operations/'.$operation->operation;
    file_put_contents($path, json_encode(['operation' => $operation->operation, 'binding' => $operation->binding, 'state' => 'running', 'boot' => 'previous-boot'], JSON_THROW_ON_ERROR));

    app(TaskWorkspaceLease::class)->reconcile($this->group);

    $receipt = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    expect($receipt['state'])->toBe('finished')->and($receipt['exit_code'])->toBeNull()
        ->and($receipt['outcome_unknown'])->toBeTrue()
        ->and($lease->fresh()->status)->toBe('released')
        ->and($lease->fresh()->getAttribute('causality_uncertain'))->toBeTrue();
});

it('keeps current boot missing supervision and unreadable receipts fenced', function (bool $unreadable): void {
    [$lease, $operation] = operator_recovery_holder();
    if (! $unreadable) {
        file_put_contents($this->receiptHome.'/.local/state/orbit/task-workspace-operations/'.$operation->operation,
            json_encode(['operation' => $operation->operation, 'binding' => $operation->binding, 'state' => 'running',
                'boot' => trim(file_get_contents('/proc/sys/kernel/random/boot_id'))], JSON_THROW_ON_ERROR));
    }
    app(TaskWorkspaceLease::class)->reconcile($this->group);
    expect($lease->fresh()->status)->toBe('held')->and($operation->fresh()->status)->toBe('pending');
})->with([false, true]);

it('requires a reason and validates exact operator lease and gateway identifiers', function (): void {
    [$lease] = operator_recovery_holder();
    $this->artisan('tasks:lease:release', ['lease-id' => (string) $lease->id])->assertFailed();
    $this->artisan('tasks:lease:release', ['lease-id' => 'unknown', '--reason' => 'Investigation'])->assertFailed();
    $this->artisan('tasks:lease:release', ['lease-id' => (string) $lease->id, '--reason' => 'Investigation', '--gateway-node' => 'unknown'])->assertFailed();
    expect($lease->fresh()->status)->toBe('held');
});

it('binds reboot interruption to the exact run even when pid and start ticks repeat', function (): void {
    $script = $this->receiptHome.'/check';
    copy(resource_path('tasks/check'), $script);
    $program = <<<'PYTHON'
import contextlib, importlib.machinery, importlib.util, io, json, os, sys
loader = importlib.machinery.SourceFileLoader('check', sys.argv[1])
check = importlib.util.module_from_spec(importlib.util.spec_from_loader('check', loader))
loader.exec_module(check)
root = os.path.join(check.DIRECTORY, 'check-starts')
os.makedirs(root, exist_ok=True)
pid, started = os.getpid(), check.process_started(os.getpid())
boot = open('/proc/sys/kernel/random/boot_id').read().strip()
old, current = 'a' * 32, 'b' * 32
for identity, identity_boot in [(old, 'previous-boot'), (current, boot)]:
    with open(os.path.join(root, identity + '.json'), 'w') as output:
        json.dump({'run_id': identity, 'pid': pid, 'started': started, 'boot': identity_boot, 'state': 'started'}, output)
def capture(function, *arguments):
    output = io.StringIO()
    with contextlib.redirect_stdout(output):
        function(*arguments)
    return json.loads(output.getvalue())
results = {'old': capture(check.status, pid, started, old), 'current': capture(check.status, pid, started, current),
           'old_cancel': capture(check.cancel, pid, started, old)}
try:
    check.status(pid, started)
except RuntimeError:
    results['ambiguous_legacy'] = True
print(json.dumps(results))
PYTHON;
    $process = new Process(['python3', '-c', $program, $script], env: $this->transport->environment);
    $data = json_decode($process->mustRun()->getOutput(), true, flags: JSON_THROW_ON_ERROR);

    expect($data['old']['state'])->toBe('interrupted')
        ->and($data['current']['state'])->toBe('running')
        ->and($data['old_cancel']['cancelled'])->toBeFalse()
        ->and($data['ambiguous_legacy'])->toBeTrue();
});

it('records a rebooted exact check as failed and releases only with uncertain attribution', function (): void {
    $leases = app(TaskWorkspaceLease::class);
    $leases->startCheck($this->group, $this->group->tasks->first(), 'true');
    $lease = TaskWorkspaceLeaseRecord::query()->where('status', 'held')->sole();
    $check = TaskCheck::query()->findOrFail($lease->check_id);
    expect($check->process()->runId)->toBe($lease->getAttribute('check_run_id'));

    app(TaskSubtaskRunner::class)->recordReading($check, TaskCheckReading::interrupted('The workspace rebooted.'));
    $leases->reconcile($this->group);

    expect($check->fresh()->status)->toBe(TaskCheckStatus::Failed)
        ->and($check->fresh()->exit_code)->toBe(1)
        ->and($check->fresh()->head_after)->toBeNull()
        ->and($lease->fresh()->status)->toBe('released')
        ->and($lease->fresh()->getAttribute('causality_uncertain'))->toBeTrue();
});

it('never stops a native live managed unit to manufacture operator quiescence', function (): void {
    $operation = bin2hex(random_bytes(16));
    $binding = bin2hex(random_bytes(32));
    $fifo = $this->receiptHome.'/finish';
    posix_mkfifo($fifo, 0o600);
    $program = file_get_contents(resource_path('tasks/workspace-operation.py'));
    $process = new Process(['python3', '-c', $program, 'run', $operation, $binding, '0',
        'bash', '-c', 'printf "ready\n"; read -r value < "$1"; printf completed', '--', $fifo],
        env: ['HOME' => $this->receiptHome]);
    $process->start();
    try {
        expect($process->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'ready')))->toBeTrue();
        $probe = new Process(['python3', '-c', $program, 'seal', str_repeat('0', 32), $binding, '0', json_encode([$operation], JSON_THROW_ON_ERROR)],
            env: ['HOME' => $this->receiptHome]);
        $data = json_decode($probe->mustRun()->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        expect($data['state'])->toBe('running')->and($process->isRunning())->toBeTrue();
        file_put_contents($fifo, "finish\n");
        $process->wait();
        expect($process->getExitCode())->toBe(0)->and($process->getOutput())->toContain('completed');
    } finally {
        $process->stop(0);
        (new Process(['sudo', '-n', 'systemctl', 'stop', 'orbit-workspace-operation-'.$operation.'.service']))->run();
    }
})->group('privileged');

it('does not let a stale historical check completion release a successor with reused pid and ticks', function (): void {
    $leases = app(TaskWorkspaceLease::class);
    $leases->startCheck($this->group, $this->group->tasks->first(), 'true');
    $lease = TaskWorkspaceLeaseRecord::query()->where('status', 'held')->sole();
    $current = TaskCheck::query()->findOrFail($lease->check_id);
    $historical = $current->replicate();
    $historical->save();

    $leases->finishedCheck($historical, TaskCheckReading::interrupted('Historical boot ended.'));

    expect($lease->fresh()->status)->toBe('held')
        ->and($lease->fresh()->check_id)->toBe($current->id)
        ->and($lease->fresh()->getAttribute('causality_uncertain'))->toBeFalse();
});

it('requires the original boot and exact dead process when a pinned Node proves foreign Gateway ownership', function (string $identity, bool $released): void {
    [$lease] = operator_recovery_holder();
    $host = 'recorded-foreign-gateway';
    $boot = trim(file_get_contents('/proc/sys/kernel/random/boot_id'));
    $lease->update(['gateway_host' => $host, 'gateway_boot' => $identity === 'rebooted' ? 'previous-boot' : $boot,
        'gateway_pid' => $identity === 'live' ? getmypid() : 2147483647, 'gateway_started' => 'unmatched']);
    if ($identity === 'live') {
        $stat = file_get_contents('/proc/'.getmypid().'/stat');
        $lease->update(['gateway_started' => explode(' ', substr($stat, strrpos($stat, ')') + 2))[19]]);
    }
    $transport = new class($this->transport, $host) implements SshExecutor
    {
        public function __construct(private LocalShellSshExecutor $local, private string $host) {}

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            if (str_contains($command->arguments[2] ?? '', 'socket.gethostname()')) {
                $arguments = $command->arguments;
                // The pinned endpoint's hostname is controlled; its /proc boot/PID reads remain real.
                $arguments[2] = 'import socket; socket.gethostname = lambda: '.json_encode($this->host, JSON_THROW_ON_ERROR)."\n".$arguments[2];

                return $this->local->execute($connection, new RemoteCommand($arguments));
            }

            return $this->local->execute($connection, $command);
        }
    };
    app()->instance(SshExecutor::class, $transport);
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldReceive('privateKeyPath')->andReturn('/unused-key');
    $hosts = Mockery::mock(KnownHostsStore::class);
    $hosts->shouldReceive('path')->andReturn('/unused-hosts');
    app()->instance(DevelopmentSshExecutor::class, new DevelopmentSshExecutor($transport, $keys, $hosts));
    $parameters = ['lease-id' => (string) $lease->id, '--reason' => 'Pinned foreign owner investigation',
        '--gateway-node' => (string) $this->group->taskable->node_id];
    if ($released) {
        $this->artisan('tasks:lease:release', $parameters)->assertSuccessful();
    } else {
        $this->artisan('tasks:lease:release', $parameters)->assertFailed();
    }
    expect($lease->fresh()->status)->toBe($released ? 'released' : 'held');
})->with(['dead exact host' => ['dead', true], 'live exact process' => ['live', false], 'duplicate hostname on different boot' => ['rebooted', false]]);
