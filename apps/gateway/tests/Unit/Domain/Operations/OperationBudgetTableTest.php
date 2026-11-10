<?php

declare(strict_types=1);

use App\Domain\Operations\OperationBudgetTable;
use App\Domain\Proof\ProofBatchPlan;

it('gives each kind a fixed number of seconds or the captured admission field', function (): void {
    foreach (OperationBudgetTable::SecondsByKind as $kind => $budget) {
        expect(is_int($budget) ? $budget > 0 : $budget === 'run_budget_seconds')->toBeTrue("{$kind} has an invalid budget");
    }
});

it('takes the longest fixed budget as the Swamp serve run cap', function (): void {
    $fixed = array_filter(OperationBudgetTable::SecondsByKind, is_int(...));

    expect(OperationBudgetTable::longestFixedSeconds())->toBe(max($fixed))->and(max($fixed))->toBeGreaterThanOrEqual(10800);
});

it('has no kind of a command that has a cases file, because a change to the table selects no proof', function (): void {
    $root = dirname(base_path(), 2);
    $commands = array_map(static fn (string $file): string => strtr(basename(dirname($file)).'-'.basename($file, '.cases.yaml'), ['.' => '-', '_' => '-']),
        glob($root.'/swamp/proof/*/*.cases.yaml') ?: []);
    $kinds = array_map(static fn (string $kind): string => strtr($kind, ['.' => '-', '_' => '-']), array_keys(OperationBudgetTable::SecondsByKind));

    // When a command whose kind is budgeted here gets a cases file, map this file to that command in swamp/proof/selection.txt.
    expect(array_values(array_intersect($commands, $kinds)))->toBe([]);
});

it('lets serve run the longest proof shard, whose budget the table does not hold', function (): void {
    // A proof shard captures its budget at admission, so the serve cap does not see it.
    expect(OperationBudgetTable::longestFixedSeconds())->toBeGreaterThanOrEqual(ProofBatchPlan::MaxShardSeconds);
});

it('budgets a private DNS publication for five to ten minutes, above the phase timeout of its workflow', function (): void {
    $workflow = file_get_contents(dirname(base_path(), 2).'/swamp/workflows/workflow-dns-publish.yaml');
    preg_match('/run: timeout (\d+) \/opt\/orbit\/ansible\/venv\/bin\/ansible-playbook/', (string) $workflow, $phase);

    expect(OperationBudgetTable::SecondsByKind['dns.publish'])->toBeGreaterThanOrEqual(300)->toBeLessThanOrEqual(600)
        ->and(OperationBudgetTable::SecondsByKind['dns.publish'])->toBeGreaterThan((int) $phase[1]);
});
