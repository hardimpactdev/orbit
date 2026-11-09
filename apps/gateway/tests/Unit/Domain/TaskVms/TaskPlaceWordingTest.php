<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/**
 * Task VM capacity is counted in places (`task_places`, `ci_places`; docs/reference/tasks.md#task-places). Step names reach users
 * through operation:show and failed_step, so none of them says "slot". Internal workflow and class names may.
 */
it('names no workflow job or step after a slot', function (): void {
    $named = [];
    foreach (glob(dirname(base_path(), 2).'/swamp/workflows/*.yaml') ?: [] as $file) {
        preg_match_all('/^\s*- name: (.+)$/m', (string) file_get_contents($file), $names);
        foreach ($names[1] as $name) {
            if (preg_match('/\bslots?\b/i', $name) === 1) {
                $named[] = basename($file).': '.$name;
            }
        }
    }

    expect($named)->toBe([]);
});

it('names the place in the cancel, prep and proof steps that claim or release one', function (): void {
    $steps = static fn (string $workflow): array => collect(Yaml::parseFile(dirname(base_path(), 2)."/swamp/workflows/{$workflow}.yaml")['jobs'])
        ->flatMap(static fn (array $job): array => array_column($job['steps'], 'name'))->all();

    expect($steps('workflow-operation-task-cancel'))->toContain('Release the place')
        ->and($steps('workflow-operation-task-prep'))->toContain('Claim a task place')
        ->and($steps('workflow-operation-proof-run'))->toContain('Claim a place', 'Release the place');
});
