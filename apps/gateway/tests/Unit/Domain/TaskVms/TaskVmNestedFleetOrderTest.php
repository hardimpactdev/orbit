<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/** @return array<string,array<string,mixed>> the steps of the nested fleet start, by name */
function nestedFleetSteps(): array
{
    $jobs = Yaml::parseFile(dirname(base_path(), 2).'/swamp/workflows/workflow-task-nested-fleet.yaml')['jobs'];

    return collect(collect($jobs)->firstWhere('name', 'fleet')['steps'])->keyBy('name')->all();
}

/** @return list<string> the steps a step requires to have succeeded */
function requiredSucceeded(array $step): array
{
    return array_values(array_column(array_filter($step['dependsOn'], static fn (array $d): bool => $d['condition'] === ['type' => 'succeeded']), 'step'));
}

it('adds the checkout device only after the NIC, so two config writes of one container never meet', function (): void {
    expect(requiredSucceeded(nestedFleetSteps()['Attach the checkout']))->toContain('Add the NICs');
});

it('runs a step that accepts a skipped step only when the step before that one passed', function (): void {
    $steps = nestedFleetSteps();

    // A failed step makes the steps after it `skipped`, as their guard does, so a skipped dependency proves nothing on its own.
    expect(requiredSucceeded($steps['Apply the checkout']))->toContain('Check the tunnel')
        ->and(requiredSucceeded($steps['Read the network address']))->toContain('Look for the network');
});
