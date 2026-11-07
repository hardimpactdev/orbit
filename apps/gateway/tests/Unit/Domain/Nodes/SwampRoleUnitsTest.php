<?php

declare(strict_types=1);

use App\Domain\Nodes\SwampRoleUnits;
use App\Domain\Operations\OperationBudgetTable;

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

it('renders one renewal service and one timer for the pair', function (): void {
    $units = SwampRoleUnits::for('orbit', 209);

    expect($units['renewal_service'])->toContain('/opt/orbit/ansible/venv/bin/ansible-playbook -i localhost, -c local /usr/local/lib/orbit-swamp/worker-renewal.yml -e orbit_user=orbit -e orbit_node_id=209')
        ->toContain("TimeoutStartSec=3h\nRestart=on-failure\nRestartSec=5min\n")
        ->and($units['renewal_timer'])->toContain("OnActiveSec=1min\nOnUnitInactiveSec=1h\n");
});
