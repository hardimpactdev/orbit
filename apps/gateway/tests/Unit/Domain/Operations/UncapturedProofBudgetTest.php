<?php

declare(strict_types=1);

use App\Domain\Operations\OperationRunBudgets;
use App\Models\Operation;

it('gives a proof run that older code admitted without a budget the 3,600 seconds it had, and a captured one its own', function (array $input, int $seconds): void {
    $run = new Operation(['kind' => 'proof.command', 'input' => ['command' => 'firewall:allow', ...$input], 'state' => ['swamp_run_id' => 'run'], 'attempts' => 1]);

    expect(OperationRunBudgets::seconds($run))->toBe($seconds);
})->with([
    'admitted by older code' => [[], 3600],
    'captured' => [['run_budget_seconds' => 4500], 4500],
    'captured below the old budget' => [['run_budget_seconds' => 1200], 1200],
]);
