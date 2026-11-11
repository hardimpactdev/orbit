<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationBudgetTable;
use App\Domain\Operations\OperationRunBudgets;
use App\Models\Operation;
use Symfony\Component\Yaml\Yaml;

it('keeps the explicit migrate budget above the lower bound of explicitly declared step timeouts including restore and cleanup', function (): void {
    // Test-only lower bound: sum every declaration serially, including both guard branches and child occurrences.
    // Undeclared metadata bounds and dynamic forEach repetitions are deliberately not inferred.
    $declared = function (string $name, array $parents = []) use (&$declared): int {
        expect($parents)->not->toContain($name);
        $workflow = Yaml::parseFile(dirname(base_path(), 2).'/swamp/workflows/workflow-'.$name.'.yaml');
        $seconds = 0;
        foreach ($workflow['jobs'] as $job) {
            foreach ($job['steps'] as $step) {
                $task = $step['task'];
                if ($task['type'] === 'workflow') {
                    $seconds += $declared($task['workflowIdOrName'], [...$parents, $name]);

                    continue;
                }
                $inputs = $task['inputs'] ?? [];
                $bounds = is_int($inputs['timeoutSec'] ?? null) ? [$inputs['timeoutSec']] : [];
                if (preg_match_all('/(?:^|[\s;&|])(?:\/usr\/bin\/)?timeout\s+([1-9][0-9]*)(?=\s)/', $inputs['run'] ?? '', $matches)) {
                    $bounds = [...$bounds, ...array_map(intval(...), $matches[1])];
                }
                $argv = $inputs['argv'] ?? [];
                if (is_array($argv)) {
                    foreach ($argv as $index => $argument) {
                        if (in_array($argument, ['timeout', '/usr/bin/timeout'], true) && ctype_digit((string) ($argv[$index + 1] ?? ''))) {
                            $bounds[] = (int) $argv[$index + 1];
                        }
                    }
                }
                $seconds += $bounds === [] ? 0 : max($bounds);
            }
        }

        return $seconds;
    };
    $workflow = app(DirectOperation::class)->definition('instance.migrate')['workflow'];
    $budget = OperationRunBudgets::seconds((new Operation)->forceFill(['kind' => 'instance.migrate', 'attempts' => 1]));

    expect($budget)->toBe(OperationBudgetTable::SecondsByKind['instance.migrate'])
        ->and($budget)->toBeGreaterThanOrEqual($declared($workflow) + 600);
});
