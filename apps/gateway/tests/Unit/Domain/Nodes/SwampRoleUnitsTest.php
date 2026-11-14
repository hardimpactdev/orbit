<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeRoleRuntimeInput;
use App\Domain\Nodes\SwampRoleUnits;
use App\Domain\Operations\OperationBudgetTable;
use App\Infrastructure\Shared\ShutdownFastStop;

it('caps a run in serve at the longest fixed budget, so the cap is in git and survives a trigger publish', function (): void {
    $serve = SwampRoleUnits::for('orbit', 209)['serve'];

    expect(OperationBudgetTable::longestFixedSeconds())->toBeGreaterThanOrEqual(10800)
        ->and($serve)->toContain(' --max-run-duration '.OperationBudgetTable::longestFixedSeconds().' ');
});

it('lets a SIGTERM drain a cold refresh dispatch before systemd kills the worker', function (): void {
    expect(SwampRoleUnits::for('orbit', 209)['worker'])->toContain("TimeoutStopSec=4500\n")
        ->toContain("KillMode=mixed\n");
});

it('asks each worker for one dispatch slot per CPU core', function (): void {
    expect(SwampRoleUnits::for('orbit', 209)['worker'])->toContain(' --concurrency auto ');
});

it('keeps workers persistent and limits repeated startup failures', function (): void {
    expect(SwampRoleUnits::for('orbit', 209)['worker'])->toContain("Restart=on-failure\nRestartSec=30\n")
        ->toContain("StartLimitIntervalSec=300\nStartLimitBurst=5\n")
        ->toContain('--cache-dir=/var/lib/orbit-swamp-worker/%i/cache')
        ->not->toContain('--max-dispatches')->not->toContain('--idle-timeout')->not->toContain('RuntimeMaxSec=');
});

it('starts each worker after serve and waits for serve to be ready, so a cold start connects at the first try', function (): void {
    $worker = SwampRoleUnits::for('orbit', 209)['worker'];
    $wait = 'ExecStartPre=-/usr/bin/curl -sf -o /dev/null --noproxy 127.0.0.1 -m 2 --retry 60 --retry-max-time 60 --retry-delay 1 '
        ."--retry-connrefused --retry-all-errors http://127.0.0.1:9418/ready\n";

    // Bounded under the default 90 s start timeout; the `-` keeps a serve that never gets ready from using up the start limit.
    expect($worker)->toContain("After=network-online.target orbit-swamp-serve.service\n", $wait)
        ->and(strpos($worker, 'ExecStartPre='))->toBeLessThan(strpos($worker, 'ExecStart=/usr/local/bin/swamp worker connect'))
        ->and($worker)->not->toContain('TimeoutStartSec=');
});

it('renders one renewal service and one timer for three permanent workers', function (): void {
    $units = SwampRoleUnits::for('orbit', 209);

    expect($units['renewal_service'])->toContain('/opt/orbit/ansible/venv/bin/ansible-playbook -i localhost, -c local /usr/local/lib/orbit-swamp/worker-renewal.yml -e orbit_user=orbit -e orbit_node_id=209')
        ->toContain("TimeoutStartSec=90min\nRestart=on-failure\nRestartSec=5min\n")
        ->and($units['renewal_timer'])->toContain("OnActiveSec=1min\nOnUnitInactiveSec=5min\n");

    expect(NodeRoleRuntimeInput::SwampWorkers)->toBe(['a', 'b', 'c']);
});

it('lets a system shutdown kill serve and each worker at once, while a normal stop still drains', function (): void {
    $units = SwampRoleUnits::for('orbit', 209);
    $guard = "ExecStop=-+/bin/sh -c '[ \"$$(/usr/bin/systemctl is-system-running)\" != stopping ] || exec /usr/bin/systemctl kill --kill-whom=all --signal=SIGKILL %n'\n";

    // The guard comes from the one shared rule, and each unit keeps its own drain and kill settings beside it.
    expect(ShutdownFastStop::Directive."\n")->toBe($guard)
        ->and($units['worker'])->toContain("KillMode=mixed\nTimeoutStopSec=4500\n".$guard)
        ->and($units['serve'])->toContain("TimeoutStopSec=360\n".$guard)->not->toContain('KillMode')
        ->and($units['renewal_service'])->not->toContain('ExecStop');
});

it('gives serve and each worker their own temp directory, so a root run can not replace their Swamp start cache', function (): void {
    $units = SwampRoleUnits::for('orbit', 209);

    expect($units['worker'])->toContain("StateDirectory=orbit-swamp-worker/%i orbit-swamp-worker/%i/tmp\n", ' TMPDIR=/var/lib/orbit-swamp-worker/%i/tmp ')
        ->and($units['serve'])->toContain("StateDirectory=orbit-swamp-serve orbit-swamp-serve/tmp\n", ' TMPDIR=/var/lib/orbit-swamp-serve/tmp ');
});

it('gives each worker its own SSH control folder at the shared path, so a worker restart can not cut a peer step that used its master', function (): void {
    $worker = SwampRoleUnits::for('orbit', 209)['worker'];

    // @swamp/ssh keeps control sockets under $XDG_RUNTIME_DIR/swamp-ssh/<behavior>; each worker sees its own folder there.
    expect($worker)->toContain("RuntimeDirectory=orbit-swamp-ssh/%i\nRuntimeDirectoryMode=0700\nBindPaths=/run/orbit-swamp-ssh/%i:/tmp/swamp-ssh\n")
        ->toContain(' XDG_RUNTIME_DIR=/tmp ')
        ->toContain(' TMPDIR=/var/lib/orbit-swamp-worker/%i/tmp ');

    // OpenSSH binds "<ControlPath>.<16 random chars>" first and a Unix socket path holds 107 bytes. The longest live behavior
    // name has 55 characters; under the worker TMPDIR such a socket would not fit.
    $socket = fn (string $base): int => strlen($base.'/swamp-ssh/'.str_repeat('x', 55).'/'.str_repeat('0', 12).'.sock.'.str_repeat('r', 16));
    expect($socket('/tmp'))->toBeLessThanOrEqual(107)
        ->and($socket('/var/lib/orbit-swamp-worker/a/tmp'))->toBeGreaterThan(107);
});
