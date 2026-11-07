<?php

declare(strict_types=1);

use App\Infrastructure\GatewayReleases\GatewayReleaseAgentDelivery;
use Symfony\Component\Yaml\Yaml;

/** @return array<string,mixed> one job of a workflow, by name */
function refreshJob(string $workflow, string $job): array
{
    $jobs = Yaml::parseFile(dirname(base_path(), 2)."/swamp/workflows/{$workflow}.yaml")['jobs'];

    return collect($jobs)->firstWhere('name', $job);
}

it('stops the refresh VM, and so builds and promotes the image, only after every step before it passed', function (): void {
    $steps = refreshJob('workflow-operation-task-vm-image-refresh', 'refresh')['steps'];
    $names = array_column($steps, 'name');
    $at = array_search('Stop the refresh VM', $names, true);
    $passed = [['type' => 'succeeded'], ['type' => 'or', 'conditions' => [['type' => 'succeeded'], ['type' => 'skipped']]]];

    // A failed step makes every later step `skipped`, as a guard does, so a step that names only the last one would run after a failure.
    expect(array_column($steps[$at]['dependsOn'], 'step'))->toEqualCanonicalizing(array_slice($names, 0, $at));
    foreach ($steps[$at]['dependsOn'] as $dependency) {
        expect($passed)->toContain($dependency['condition']);
    }
    // After the stop, nothing accepts a skipped step: a skip there can only come from a failure.
    foreach (array_slice($steps, $at + 1) as $step) {
        foreach ($step['dependsOn'] as $dependency) {
            expect($dependency['condition'])->toBe(['type' => 'succeeded']);
        }
    }
});

it('places the agent delivery of the checkout on the nested Gateway before its Swamp tokens are renewed and its code migrates', function (): void {
    $steps = collect(refreshJob('workflow-task-nested-apply', 'apply')['steps'])->keyBy('name');
    $checkout = '/home/orbit/orbit/';

    expect($steps['Place the agent delivery']['task']['inputs']['argv'])
        ->toContain($checkout.GatewayReleaseAgentDelivery::Pin, $checkout.GatewayReleaseAgentDelivery::Manifest)
        ->and(array_column($steps['Re-mint the Swamp tokens']['dependsOn'], 'step'))->toBe(['Place the agent delivery'])
        ->and($steps['Migrate']['dependsOn'])->toContain(['step' => 'Place the agent delivery', 'condition' => ['type' => 'succeeded']]);
});
