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
use App\Infrastructure\Tasks\TaskUnitProgram;
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
if test -f "$HOME/units.json"; then
    exec /usr/bin/python3 -c '
import json, os, sys
units = json.load(open(os.path.join(os.environ["HOME"], "units.json")))
if sys.argv[1] == "list-units":
    print(json.dumps([{"unit": unit["unit"]} for unit in units]))
elif sys.argv[1] == "show":
    unit = next(item for item in units if item["unit"] == sys.argv[2])
    if unit.get("unreadable"):
        sys.exit(1)
    for key, value in unit.items():
        if key != "unit":
            print(key + "=" + value)
else:
    sys.exit(99)
' "$@"
elif test "$1" = list-units; then
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
        'python3', '-c', TaskUnitProgram::read('tasks/workspace-operation.py'),
        $mode, $operation, $binding, '0', ...$arguments,
    ]));
}

it('seals a lost receipt before releasing dead ownership and audits repeated operator recovery', function (): void {
    [$lease, $operation] = operator_recovery_holder();
    test_update_task($this->group, ['assistance_requested' => true]);

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

it('scopes operator quiescence to its lease and fails closed on ambiguous managed unit identity', function (string $condition, bool $released): void {
    [$lease, $operation] = operator_recovery_holder();
    $runId = bin2hex(random_bytes(16));
    $lease->update(['check_run_id' => $runId]);
    $identity = bin2hex(random_bytes(16));
    $unit = ['unit' => 'orbit-workspace-operation-'.$identity.'.service',
        'LoadState' => 'loaded', 'ActiveState' => 'active', 'SubState' => 'running',
        'ControlGroup' => '', 'Description' => 'Orbit workspace operation '.bin2hex(random_bytes(32))];
    if ($condition === 'same binding') {
        $unit['Description'] = 'Orbit workspace operation '.$operation->binding;
    } elseif ($condition === 'known operation different binding') {
        $unit['unit'] = 'orbit-workspace-operation-'.$operation->operation.'.service';
    } elseif ($condition === 'other check' || $condition === 'same check' || $condition === 'check identity mismatch') {
        $identity = $condition === 'other check' ? $identity : $runId;
        $unit['unit'] = 'orbit-task-check-'.$identity.'.service';
        $unit['Description'] = 'Orbit task check '.($condition === 'check identity mismatch' ? bin2hex(random_bytes(16)) : $identity);
    } elseif ($condition === 'missing description') {
        unset($unit['Description']);
    } elseif ($condition === 'unreadable unit') {
        $unit['unreadable'] = true;
    } elseif ($condition === 'vanished unit') {
        $unit = ['unit' => $unit['unit'], 'LoadState' => 'not-found', 'ActiveState' => 'inactive', 'ControlGroup' => ''];
    } elseif ($condition === 'unloaded unit') {
        $unit['LoadState'] = 'error';
    }
    file_put_contents($this->receiptHome.'/units.json', json_encode([$unit], JSON_THROW_ON_ERROR));
    $parameters = ['lease-id' => (string) $lease->id, '--reason' => 'Shared node scoped proof'];

    if ($released) {
        $this->artisan('tasks:lease:release', $parameters)->assertSuccessful();
    } else {
        $this->artisan('tasks:lease:release', $parameters)->assertFailed();
    }

    expect($lease->fresh()->status)->toBe($released ? 'released' : 'held')
        ->and($lease->fresh()->getAttribute('recovery_sealed'))->toBeTrue()
        ->and($operation->fresh()->status)->toBe($released ? 'finished' : 'pending');
    expect(json_decode(file_get_contents($this->receiptHome.'/units.json'), true, flags: JSON_THROW_ON_ERROR))->toBe([$unit]);
})->with([
    'other operation' => ['other operation', true],
    'other check' => ['other check', true],
    'same binding including unlisted operation' => ['same binding', false],
    'known operation with another binding' => ['known operation different binding', false],
    'same check run' => ['same check', false],
    'check identity mismatch' => ['check identity mismatch', false],
    'missing description' => ['missing description', false],
    'unreadable unit' => ['unreadable unit', false],
    'vanished unit' => ['vanished unit', true],
    'unloaded unit' => ['unloaded unit', false],
]);

it('refuses inconsistent persisted lease identities before sealing any endpoint', function (string $condition): void {
    [$lease, $operation] = operator_recovery_holder();
    if ($condition === 'operation binding') {
        $operation->update(['binding' => bin2hex(random_bytes(32))]);
    } else {
        $lease->update(['check_run_id' => 'unverifiable-check']);
    }

    $this->artisan('tasks:lease:release', ['lease-id' => (string) $lease->id, '--reason' => 'Inconsistent journal identity'])->assertFailed();

    expect($lease->fresh()->status)->toBe('held')
        ->and($operation->fresh()->status)->toBe('pending');
    expect(glob($this->receiptHome.'/.local/state/orbit/task-workspace-operations/binding-*.sealed'))->toBe([]);
})->with(['operation binding', 'check run']);

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

it('rejects a stale in memory scope after sealing or replacement before admitting any new SSH', function (bool $sealed): void {
    $leases = app(TaskWorkspaceLease::class);
    $lease = $leases->acquire($this->group, 'publication', 'stale');
    $leases->within($lease, function () use ($leases, $lease, $sealed): void {
        if ($sealed) {
            $lease->update(['recovery_sealed' => true]);
        } else {
            $lease->update(['status' => 'released', 'active_instance_id' => null]);
            (new TaskWorkspaceLease)->acquire($this->group, 'publication', 'successor');
        }
        expect(fn () => $leases->currentScope())->toThrow(TaskWorkspaceWaiting::class);
        expect(fn () => $leases->remoteStarted($this->group->taskable, true))->toThrow(TaskWorkspaceWaiting::class);
    });
    expect(TaskWorkspaceOperation::query()->count())->toBe(0);
})->with([false, true]);

it('retires only a proven refused scope through nested unwind without admitting late SSH', function (): void {
    $leases = app(TaskWorkspaceLease::class);
    $thread = AgentThread::query()->create([
        'task_group_id' => $this->group->id, 'task_id' => $this->group->tasks->first()->id,
        'node_id' => $this->group->taskable->node_id, 'runtime_key' => 'node:'.$this->group->taskable->node_id,
        'driver' => 'pi', 'external_id' => 'refused-reviewer', 'role' => 'reviewer', 'state' => 'idle',
    ]);
    $lease = $leases->acquire($this->group, 'reviewer', 'thread:'.$thread->id, $thread->id, $thread->task_id);
    $leases->within($lease, function () use ($leases, $lease, $thread): void {
        $leases->within($lease, function () use ($leases, $thread): void {
            $leases->rejectedDispatch($thread);
            expect($leases->abandonPreparation($thread))->toBeTrue();
            expect(fn () => $leases->remoteStarted($this->group->taskable, true))->toThrow(TaskWorkspaceWaiting::class);
        });
        expect($leases->currentScope())->toBeNull();
        expect(fn () => $leases->remoteStarted($this->group->taskable, true))->toThrow(TaskWorkspaceWaiting::class);
    });
    expect($leases->currentScope())->toBeNull();
    expect(TaskWorkspaceLeaseRecord::query()->whereIn('status', ['held', 'queued'])->count())->toBe(0);
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
    file_put_contents($script, TaskUnitProgram::read('tasks/check'));
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

it('never stops a native live managed unit to manufacture operator quiescence', function (bool $queued): void {
    $operation = bin2hex(random_bytes(16));
    $binding = bin2hex(random_bytes(32));
    $fifo = $this->receiptHome.'/finish';
    posix_mkfifo($fifo, 0o600);
    $finish = fopen($fifo, 'r+');
    $environment = ['HOME' => $this->receiptHome];
    $barrier = null;
    $process = null;
    $barrierUnit = 'orbit-test-lease-start-'.$operation.'.service';
    try {
        if ($queued) {
            $start = $this->receiptHome.'/start';
            posix_mkfifo($start, 0o600);
            $barrier = new Process(['sudo', '-n', 'systemd-run', '--quiet', '--pipe', '--unit='.$barrierUnit,
                '--property=Type=oneshot', 'bash', '-c', 'printf "blocked\n"; read -r value < "$1"', '--', $start]);
            $barrier->start();
            expect($barrier->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'blocked')))->toBeTrue();
            file_put_contents($this->receiptHome.'/bin/sudo', str_replace('BARRIER_UNIT', $barrierUnit, <<<'SH'
#!/bin/sh
if test "$2" = systemd-run; then
    shift 2
    exec /usr/bin/sudo -n systemd-run --property=After=BARRIER_UNIT "$@"
fi
exec /usr/bin/sudo "$@"
SH));
            // Hold the native start job behind the oneshot, then release it only after observing its queued state.
            file_put_contents($this->receiptHome.'/bin/systemctl', <<<'SH'
#!/bin/sh
state=$(/usr/bin/systemctl "$@") || exit "$?"
if printf '%s\n' "$state" | grep -qx 'LoadState=loaded' &&
   printf '%s\n' "$state" | grep -qx 'ActiveState=inactive' &&
   printf '%s\n' "$state" | grep -qx 'ExecMainCode=0' &&
   ! test -f "$HOME/queued-state"; then
    printf '%s\n' "$state" > "$HOME/queued-state"
    printf 'start\n' > "$HOME/start"
fi
printf '%s\n' "$state"
SH);
            chmod($this->receiptHome.'/bin/sudo', 0o700);
            $environment['PATH'] = $this->receiptHome.'/bin:'.getenv('PATH');
        }
        $program = TaskUnitProgram::read('tasks/workspace-operation.py');
        $process = new Process(['python3', '-c', $program, 'run', $operation, $binding, '0',
            'bash', '-c', 'printf "ready\n"; read -r value < "$1"; printf completed', '--', $fifo],
            cwd: $this->receiptHome, env: $environment);
        $process->start();
        $ready = $process->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'ready'));
        if ($queued) {
            expect(file_get_contents($this->receiptHome.'/queued-state'))->toContain('LoadState=loaded', 'ActiveState=inactive', 'SubState=dead', 'ExecMainCode=0', 'ExecMainStatus=0')
                ->toMatch('/^Job=[1-9][0-9]*$/m');
        }
        expect($ready)->toBeTrue();
        $probe = new Process(['python3', '-c', $program, 'seal', str_repeat('0', 32), $binding, '0', json_encode([$operation], JSON_THROW_ON_ERROR)],
            env: ['HOME' => $this->receiptHome]);
        $data = json_decode($probe->mustRun()->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        expect($data['state'])->toBe('running')->and($process->isRunning())->toBeTrue();
        fwrite($finish, "finish\n");
        $process->wait();
        expect($process->getExitCode())->toBe(0)->and($process->getOutput())->toContain('completed');
        $receipt = json_decode(file_get_contents($this->receiptHome.'/.local/state/orbit/task-workspace-operations/'.$operation), true, flags: JSON_THROW_ON_ERROR);
        expect($receipt['state'])->toBe('finished')->and($receipt['exit_code'])->toBe(0);
    } finally {
        fclose($finish);
        $process?->stop(0);
        (new Process(['sudo', '-n', 'systemctl', 'stop', 'orbit-workspace-operation-'.$operation.'.service']))->run();
        if ($barrier !== null) {
            (new Process(['sudo', '-n', 'systemctl', 'stop', $barrierUnit]))->run();
            $barrier->stop(0);
        }
        (new Process(['sudo', '-n', 'systemctl', 'reset-failed', 'orbit-workspace-operation-'.$operation.'.service', $barrierUnit]))->run();
    }
})->with(['immediate start' => false, 'queued start' => true])->group('privileged');

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

it('uses boot changes and exact process death consistently on a pinned foreign Gateway', function (string $identity, bool $released): void {
    [$lease] = operator_recovery_holder();
    $host = 'recorded-foreign-gateway';
    $boot = trim(file_get_contents('/proc/sys/kernel/random/boot_id'));
    $lease->update(['gateway_host' => $identity === 'wrong host' ? 'other-host' : $host, 'gateway_boot' => in_array($identity, ['rebooted', 'wrong host'], true) ? 'previous-boot' : $boot,
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
            if (is_string($command->input) && str_contains($command->input, '# Program: gateway.task-workspace-owner')) {
                // The pinned endpoint's hostname is controlled; its /proc boot/PID reads remain real.
                $input = 'import socket; socket.gethostname = lambda: '.json_encode($this->host, JSON_THROW_ON_ERROR)."\n".$command->input;

                return $this->local->execute($connection, new RemoteCommand($command->arguments, input: $input));
            }

            return $this->local->execute($connection, $command);
        }
    };
    app()->instance(SshExecutor::class, $transport);
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldReceive('privateKeyPath')->andReturn('/unused-key');
    $hosts = Mockery::mock(KnownHostsStore::class);
    $hosts->shouldReceive('path')->andReturn('/unused-hosts');
    app()->instance(SshKeyProvider::class, $keys);
    app()->instance(KnownHostsStore::class, $hosts);
    $parameters = ['lease-id' => (string) $lease->id, '--reason' => 'Pinned foreign owner investigation',
        '--gateway-node' => (string) $this->group->taskable->node_id];
    if ($released) {
        $this->artisan('tasks:lease:release', $parameters)->assertSuccessful();
    } else {
        $this->artisan('tasks:lease:release', $parameters)->assertFailed();
    }
    expect($lease->fresh()->status)->toBe($released ? 'released' : 'held');
})->with(['dead exact host' => ['dead', true], 'live exact process' => ['live', false], 'rebooted pinned host' => ['rebooted', true], 'mismatched hostname after reboot' => ['wrong host', false]]);

it('proves native quiescence for only the target binding and check run without stopping another workspace', function (string $kind, bool $relevant): void {
    $operation = bin2hex(random_bytes(16));
    $binding = bin2hex(random_bytes(32));
    $runId = bin2hex(random_bytes(16));
    $unit = $kind === 'operation' ? 'orbit-workspace-operation-'.$operation.'.service' : 'orbit-task-check-'.$runId.'.service';
    $description = $kind === 'operation' ? 'Orbit workspace operation '.$binding : 'Orbit task check '.$runId;
    $fifo = $this->receiptHome.'/native-finish';
    posix_mkfifo($fifo, 0o600);
    $process = new Process(['sudo', '-n', 'systemd-run', '--quiet', '--pipe', '--unit='.$unit,
        '--uid='.(string) posix_geteuid(), '--gid='.(string) posix_getegid(),
        '--property=Type=exec', '--property=ExitType=cgroup',
        '--description='.$description, '--', 'bash', '-c',
        'printf "ready\n"; read -r value < "$1"; printf completed', '--', $fifo]);
    $process->start();
    try {
        $isReady = static fn (): bool => str_contains($process->getOutput(), "ready\n");
        expect($isReady() || $process->waitUntil($isReady))->toBeTrue();
        $targetBinding = $relevant ? $binding : bin2hex(random_bytes(32));
        $checkRuns = $kind === 'check' && $relevant ? [$runId] : [];
        $probe = new Process(['python3', '-c', TaskUnitProgram::read('tasks/workspace-operation.py'),
            'seal', str_repeat('0', 32), $targetBinding, '0', '[]', json_encode($checkRuns, JSON_THROW_ON_ERROR)],
            env: ['HOME' => $this->receiptHome]);
        $data = json_decode($probe->mustRun()->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        expect($data['state'])->toBe($relevant ? 'running' : 'sealed')
            ->and($process->isRunning())->toBeTrue();

        file_put_contents($fifo, "finish\n");
        $process->wait();
        expect($process->getExitCode())->toBe(0)->and($process->getOutput())->toContain('completed');
    } finally {
        $process->stop(0);
        (new Process(['sudo', '-n', 'systemctl', 'stop', $unit]))->run();
    }
})->with(['operation' => ['operation'], 'check' => ['check']])->with([false, true])->group('privileged');

it('preserves literal command arguments through native workspace supervision', function (): void {
    $operation = bin2hex(random_bytes(16));
    $binding = bin2hex(random_bytes(32));
    $arguments = ['${HOME}', '$HOME', '$$', '${1-}', 'space and "quotes"', "line\nnext", '%n', ''];
    $program = TaskUnitProgram::read('tasks/workspace-operation.py');
    $process = new Process(['python3', '-c', $program, 'run', $operation, $binding, '0',
        'python3', '-c', 'import json, sys; print(json.dumps(sys.argv[1:]))', ...$arguments],
        cwd: $this->receiptHome, env: ['HOME' => $this->receiptHome]);

    try {
        $process->run();
        expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
        expect(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR))->toBe($arguments);
        $receipt = json_decode(file_get_contents($this->receiptHome.'/.local/state/orbit/task-workspace-operations/'.$operation), true, flags: JSON_THROW_ON_ERROR);
        expect($receipt)->toMatchArray(['state' => 'finished', 'exit_code' => 0]);
    } finally {
        $process->stop(0);
        (new Process(['sudo', '-n', 'systemctl', 'stop', 'orbit-workspace-operation-'.$operation.'.service']))->run();
    }
})->group('privileged');

it('reads topology status through native workspace supervision without expanding the shell program', function (): void {
    $operation = bin2hex(random_bytes(16));
    $binding = bin2hex(random_bytes(32));
    $process = new Process(['python3', '-c', TaskUnitProgram::read('tasks/workspace-operation.py'),
        'run', $operation, $binding, '0', 'bash', '-c', TaskUnitProgram::read('task-vms/topology.sh'),
        'topology', 'status', 'abcdef0123456789'], cwd: $this->receiptHome, env: ['HOME' => $this->receiptHome]);

    try {
        $process->run();
        expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
        expect(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR))->toBe(['state' => 'lost']);
    } finally {
        $process->stop(0);
        (new Process(['sudo', '-n', 'systemctl', 'stop', 'orbit-workspace-operation-'.$operation.'.service']))->run();
    }
})->group('privileged');
