<?php

declare(strict_types=1);

use App\Domain\Nodes\SwampRoleUnits;
use App\Domain\Operations\OperationRunBudgets;

it('caps a run in serve at the longest fixed budget, so the cap is in git and survives a trigger publish', function (): void {
    $serve = SwampRoleUnits::for('orbit', 209)['serve'];

    expect(OperationRunBudgets::longestFixedSeconds())->toBeGreaterThanOrEqual(10800)
        ->and($serve)->toContain(' --max-run-duration '.OperationRunBudgets::longestFixedSeconds().' ');
});
