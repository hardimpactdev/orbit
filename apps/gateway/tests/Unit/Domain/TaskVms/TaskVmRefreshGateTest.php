<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/** @return array<string,mixed> one job of a workflow, by name */
function refreshJob(string $workflow, string $job): array
{
    $jobs = Yaml::parseFile(base_path("../../swamp/workflows/{$workflow}.yaml"))['jobs'];

    return collect($jobs)->firstWhere('name', $job);
}

it('stops the refresh VM, and so builds and promotes the image, only after every step before it passed', function (): void {
    $steps = refreshJob('workflow-operation-task-vm-image-refresh', 'refresh')['steps'];
    $names = array_column($steps, 'name');
    $stop = $steps[array_search('Stop the refresh VM', $names, true)];
    $before = array_slice($names, 0, array_search('Stop the refresh VM', $names, true));

    // A failed step makes every later step `skipped`, as a guard does, so a step that names only the last one would run after a failure.
    expect(array_column($stop['dependsOn'], 'step'))->toEqualCanonicalizing($before);
    foreach ($stop['dependsOn'] as $dependency) {
        expect($dependency['condition']['type'] === 'succeeded'
            || $dependency['condition'] === ['type' => 'or', 'conditions' => [['type' => 'succeeded'], ['type' => 'skipped']]])->toBeTrue();
    }
});

it('places the agent delivery of the checkout on the nested Gateway before its Swamp tokens are renewed and its Nodes converge', function (): void {
    $steps = collect(refreshJob('workflow-task-nested-apply', 'apply')['steps'])->keyBy('name');
    $argv = $steps['Place the agent delivery']['task']['inputs']['argv'];

    expect($argv)->toContain('/home/orbit/orbit/apps/gateway/resources/agent/frozen-manifest.json', '/home/orbit/orbit/apps/agent/delivery/manifest.json')
        ->and(array_column($steps['Re-mint the Swamp tokens']['dependsOn'], 'step'))->toBe(['Place the agent delivery']);
});
