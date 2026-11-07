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

it('gives serve and each worker their own temp directory, so a root run can not replace their Swamp start cache', function (): void {
    $units = SwampRoleUnits::for('orbit', 209);

    expect($units['worker'])->toContain("StateDirectory=orbit-swamp-worker/%i orbit-swamp-worker/%i/tmp\n", ' TMPDIR=/var/lib/orbit-swamp-worker/%i/tmp ')
        ->and($units['serve'])->toContain("StateDirectory=orbit-swamp-serve orbit-swamp-serve/tmp\n", ' TMPDIR=/var/lib/orbit-swamp-serve/tmp ');
});
