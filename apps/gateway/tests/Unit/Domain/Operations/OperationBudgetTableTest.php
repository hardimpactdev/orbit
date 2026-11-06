<?php

declare(strict_types=1);

use App\Domain\Operations\OperationBudgetTable;

it('gives each kind a fixed number of seconds or the captured admission field', function (): void {
    foreach (OperationBudgetTable::SecondsByKind as $kind => $budget) {
        expect(is_int($budget) ? $budget > 0 : $budget === 'run_budget_seconds')->toBeTrue("{$kind} has an invalid budget");
    }
});

it('takes the longest fixed budget as the Swamp serve run cap', function (): void {
    $fixed = array_filter(OperationBudgetTable::SecondsByKind, is_int(...));

    expect(OperationBudgetTable::longestFixedSeconds())->toBe(max($fixed))->and(max($fixed))->toBeGreaterThanOrEqual(10800);
});
