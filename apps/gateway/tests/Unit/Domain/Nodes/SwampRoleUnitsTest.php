<?php

declare(strict_types=1);

use App\Domain\Nodes\SwampRoleUnits;
use App\Domain\Operations\OperationBudgetTable;

it('caps a run in serve at the longest fixed budget, so the cap is in git and survives a trigger publish', function (): void {
    $serve = SwampRoleUnits::for('orbit', 209)['serve'];

    expect(OperationBudgetTable::longestFixedSeconds())->toBeGreaterThanOrEqual(10800)
        ->and($serve)->toContain(' --max-run-duration '.OperationBudgetTable::longestFixedSeconds().' ');
});

it('gives a stopping worker the 5 minute drain of the Swamp daemon unit, never an unbounded one', function (): void {
    $worker = SwampRoleUnits::for('orbit', 209)['worker'];

    expect($worker)->toContain("TimeoutStopSec=300\n")->not->toContain('infinity');
});

it('asks each worker for one dispatch slot per CPU core', function (): void {
    expect(SwampRoleUnits::for('orbit', 209)['worker'])->toContain(' --concurrency auto ');
});

it('renews worker connections through native drain and clean restart, never a runtime kill', function (): void {
    $worker = SwampRoleUnits::for('orbit', 209)['worker'];

    expect($worker)->toContain(' --max-dispatches 1 --idle-timeout 5m ')
        ->toContain("Restart=always\nRestartSec=1\n")
        ->toContain('--cache-dir=/var/lib/orbit-swamp-worker/%i/cache')
        ->not->toContain('RuntimeMaxSec=');
});

it('finishes a native worker drain before the authenticated socket reaches eight hours', function (): void {
    // First completion starts the drain; another slot may have just started a full-length run.
    // The initial idle wait plus both runs must fit under Swamp's hard-coded socket lifetime.
    expect(300 + 2 * OperationBudgetTable::longestFixedSeconds())->toBeLessThan(8 * 60 * 60);
});
