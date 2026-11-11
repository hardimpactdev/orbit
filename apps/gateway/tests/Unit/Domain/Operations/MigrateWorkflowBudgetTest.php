<?php

declare(strict_types=1);

use App\Domain\Operations\OperationRunBudgets;
use App\Models\Operation;
use Symfony\Component\Yaml\Yaml;

it('budgets every declared migrate phase including restore and cleanup instead of learning from a tiny success', function (): void {
    $workflow = Yaml::parseFile(dirname(base_path(), 2).'/swamp/workflows/workflow-operation-instance-migrate.yaml');
    $bounds = [];
    foreach ($workflow['jobs'][0]['steps'] as $step) {
        if (preg_match('/\Atimeout ([0-9]+) /', $step['task']['inputs']['run'] ?? '', $match) === 1) {
            $bounds[$step['name']] = (int) $match[1];
        }
    }
    $budget = OperationRunBudgets::seconds((new Operation)->forceFill(['kind' => 'instance.migrate', 'attempts' => 1]));

    expect($bounds)->toHaveKeys(['container', 'container-processes', 'restore', 'cleanup'])
        ->and($budget)->toBe(OperationRunBudgets::workflowSeconds('operation-instance-migrate'))
        ->and($budget)->toBeGreaterThanOrEqual(array_sum($bounds) + 600);
});

it('grows the whole-run budget by each increased phase timeout including a restoration child', function (): void {
    $directory = sys_get_temp_dir().'/migrate-budget-'.bin2hex(random_bytes(6));
    mkdir($directory);
    $root = $directory.'/workflow-move.yaml';
    $restore = $directory.'/workflow-restore.yaml';
    try {
        file_put_contents($root, "jobs:\n- name: move\n  steps:\n  - task: {type: behavior, inputs: {run: 'timeout 120 ansible-playbook move.yml'}}\n  - task: {type: workflow, workflowIdOrName: restore}\n");
        file_put_contents($restore, "jobs:\n- name: restore\n  steps:\n  - task: {type: behavior, inputs: {run: 'timeout 300 ansible-playbook restore.yml'}}\n");
        $before = OperationRunBudgets::workflowSeconds('move', $directory);
        file_put_contents($restore, str_replace('timeout 300 ', 'timeout 420 ', (string) file_get_contents($restore)));

        expect($before)->toBe(1020)
            ->and(OperationRunBudgets::workflowSeconds('move', $directory))->toBe($before + 120);
        file_put_contents($root, str_replace('timeout 120 ', 'timeout 121 ', (string) file_get_contents($root)));
        expect(OperationRunBudgets::workflowSeconds('move', $directory))->toBe($before + 121);
    } finally {
        unlink($root);
        unlink($restore);
        rmdir($directory);
    }
});
