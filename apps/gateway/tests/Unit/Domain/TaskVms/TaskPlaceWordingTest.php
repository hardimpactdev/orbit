<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/**
 * Task VM capacity is counted in places (`task_places`, `ci_places`; docs/reference/compute-drivers.md#place-task-vms). Step names reach users
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

// The place release is deleted (docs/reference/old-paths.md#the-pi-task-loop): the cancel workflow keeps its inputs and its end check, which
// reads no release step, and calls no other workflow.
it('keeps no release step in the cancel workflow, and its end check reads none', function (): void {
    $flow = Yaml::parseFile(dirname(base_path(), 2).'/swamp/workflows/workflow-operation-task-cancel.yaml');
    $steps = collect($flow['jobs'])->flatMap(static fn (array $job): array => $job['steps'])->keyBy('name');

    expect($steps->keys()->all())->toBe(['Check the end'])
        ->and($steps->pluck('task.type')->all())->toBe(['assert'])
        ->and($steps['Check the end']['task']['expr'])->not->toContain('steps[')
        ->and($flow['inputs']['required'])->toBe(['operation_id', 'node_id', 'kind', 'intent', 'execution_secrets']);
});
