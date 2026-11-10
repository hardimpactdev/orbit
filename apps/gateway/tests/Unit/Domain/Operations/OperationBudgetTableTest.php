<?php

declare(strict_types=1);

use App\Domain\Operations\OperationBudgetTable;
use App\Domain\Proof\ProofBatchPlan;
use App\Domain\Proof\ProofChangedFile;
use App\Domain\Proof\ProofChangedFiles;
use App\Domain\Proof\ProofSelection;

it('gives each kind a fixed number of seconds or the captured admission field', function (): void {
    foreach (OperationBudgetTable::SecondsByKind as $kind => $budget) {
        expect(is_int($budget) ? $budget > 0 : $budget === 'run_budget_seconds')->toBeTrue("{$kind} has an invalid budget");
    }
});

it('takes the longest fixed budget as the Swamp serve run cap', function (): void {
    $fixed = array_filter(OperationBudgetTable::SecondsByKind, is_int(...));

    expect(OperationBudgetTable::longestFixedSeconds())->toBe(max($fixed))->and(max($fixed))->toBeGreaterThanOrEqual(10800);
});

it('selects no proof for a budget-only change even when budgeted commands have cases files', function (): void {
    $root = dirname(base_path(), 2);
    $files = array_map(static fn (string $file): string => substr($file, strlen($root) + 1),
        glob($root.'/swamp/proof/*/*.cases.yaml') ?: []);
    $selected = ProofSelection::select((string) file_get_contents($root.'/swamp/proof/selection.txt'),
        new ProofChangedFiles(str_repeat('a', 40), [new ProofChangedFile('apps/gateway/app/Domain/Operations/OperationBudgetTable.php', 'modified')], true),
        false, ProofSelection::casesFiles($files));

    expect($selected->files())->toBe([])
        ->and($selected->everythingBecause)->toBeNull()
        ->and($selected->nothingToProve())->toBeTrue();
});

it('lets serve run the longest proof shard, whose budget the table does not hold', function (): void {
    // A proof shard captures its budget at admission, so the serve cap does not see it.
    expect(OperationBudgetTable::longestFixedSeconds())->toBeGreaterThanOrEqual(ProofBatchPlan::MaxShardSeconds);
});
