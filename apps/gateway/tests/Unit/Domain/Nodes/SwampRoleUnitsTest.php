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
