<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/** @return array<string,array<string,mixed>> the steps of one job of a workflow, by name */
function workflowSteps(string $workflow, string $job): array
{
    $jobs = Yaml::parseFile(dirname(base_path(), 2)."/swamp/workflows/{$workflow}.yaml")['jobs'];

    return collect(collect($jobs)->firstWhere('name', $job)['steps'])->keyBy('name')->all();
}

/** @return list<string> the steps a step names in its dependencies */
function dependencyNames(array $step): array
{
    return array_values(array_column($step['dependsOn'] ?? [], 'step'));
}

it('runs a step that accepts a skipped step only when everything that step waited for is named too', function (string $workflow, string $job): void {
    $steps = workflowSteps($workflow, $job);
    $skippable = ['type' => 'or', 'conditions' => [['type' => 'succeeded'], ['type' => 'skipped']]];

    // A failed step makes the steps after it `skipped`, as their guard does, so a skipped dependency proves nothing on its own:
    // the step must also name what the skipped step waited for.
    foreach ($steps as $name => $step) {
        $named = dependencyNames($step);
        foreach ($step['dependsOn'] ?? [] as $dependency) {
            if ($dependency['condition'] !== $skippable || ! isset($steps[$dependency['step']])) {
                continue;
            }
            foreach (dependencyNames($steps[$dependency['step']]) as $before) {
                expect(in_array($before, $named, true))->toBeTrue("{$name} accepts a skipped {$dependency['step']} but does not name {$before}");
            }
        }
    }
})->with([
    'nested apply' => ['workflow-task-nested-apply', 'apply'],
]);
