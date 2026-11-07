<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

it('checks the firewall only after every mutation succeeded or was guarded, never after native failure', function (): void {
    $workflow = Yaml::parseFile(dirname(base_path(), 2).'/swamp/workflows/workflow-operation-firewall-direct.yaml');
    $steps = collect($workflow['jobs'][0]['steps'])->keyBy('name');

    expect($steps['Change the rules']['concurrency'])->toBe(1)
        ->and($steps['Read the result']['dependsOn'])->toBe([
            ['step' => 'Plan the changes', 'condition' => ['type' => 'succeeded']],
            ['step' => 'Change the rules', 'condition' => ['type' => 'or', 'conditions' => [['type' => 'succeeded'], ['type' => 'skipped']]]],
        ])
        ->and($steps['Read the result']['guard'])->toBe('${{ size(steps["Plan the changes"].outputs.commands) == 0 }}')
        ->and($steps['Check the rules']['dependsOn'])->toBe([
            ['step' => 'Plan the changes', 'condition' => ['type' => 'succeeded']],
            ['step' => 'Read the result', 'condition' => ['type' => 'or', 'conditions' => [['type' => 'succeeded'], ['type' => 'skipped']]]],
        ])
        ->and($steps['Check the rules']['task']['inputs']['stdout'])->toBe('${{ size(steps["Plan the changes"].outputs.commands) == 0 ? steps["Read the rules"].outputs.stdout : steps["Read the result"].outputs.stdout }}')
        ->and($steps['accepted']['dependsOn'])->toBe([['step' => 'Check the rules', 'condition' => ['type' => 'succeeded']]]);
});

it('converges firewall allow through the native planner and end-state check', function (): void {
    // Exercise the real TypeScript behavior, not a second PHP implementation of its rules.
    $process = new Process(['deno', 'test', '--no-lock', 'extensions/behaviors/operations/firewall/firewall_test.ts'], dirname(base_path(), 2).'/swamp');
    $process->setTimeout(120);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());
});
