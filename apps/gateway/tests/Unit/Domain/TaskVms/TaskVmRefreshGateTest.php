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

it('runs the steps of the chart in order, each only after the one before passed, and discards the candidate after a failure', function (): void {
    $steps = refreshJob('workflow-operation-task-vm-image-refresh', 'refresh')['steps'];

    expect(array_column($steps, 'name'))->toBe(['Start the refresh VM', 'Converge everything', 'Publish the image', 'Promote']);
    foreach (array_slice($steps, 1) as $at => $step) {
        expect($step['dependsOn'])->toBe([['step' => $steps[$at]['name'], 'condition' => ['type' => 'succeeded']]]);
    }
    expect(refreshJob('workflow-operation-task-vm-image-refresh', 'discard'))
        ->toMatchArray(['dependsOn' => [['job' => 'refresh', 'condition' => ['type' => 'failed']]]])
        ->and(refreshJob('workflow-operation-task-vm-image-refresh', 'discard')['steps'][0]['task']['inputs']['tags'])->toBe('discard');
});

it('places the agent delivery of the checkout on the nested Gateway before its Swamp tokens are renewed and its code migrates', function (): void {
    $steps = collect(refreshJob('workflow-task-nested-apply', 'apply')['steps'])->keyBy('name');
    $checkout = '/home/orbit/orbit/';

    expect($steps['Place the agent delivery']['task']['inputs']['argv'])
        ->toContain($checkout.GatewayReleaseAgentDelivery::Pin, $checkout.GatewayReleaseAgentDelivery::Manifest)
        ->and(array_column($steps['Re-mint the Swamp tokens']['dependsOn'], 'step'))->toBe(['Place the agent delivery'])
        ->and($steps['Migrate']['dependsOn'])->toContain(['step' => 'Place the agent delivery', 'condition' => ['type' => 'succeeded']]);
});

it('carries the error of a failed Ansible run to the Operation: the run keeps its output and an assert fails with its stderr', function (string $workflow, string $run, string $check): void {
    $steps = collect(refreshJob($workflow, $workflow === 'workflow-task-vm-image-ansible' ? 'run' : 'phase')['steps'])->keyBy('name');

    expect($steps[$run]['task']['inputs']['ignoreExitCode'])->toBeTrue()
        ->and($steps[$run]['task']['inputs']['env'])->toMatchArray(['ANSIBLE_DISPLAY_FAILED_STDERR' => 'true', 'ANSIBLE_STDOUT_CALLBACK' => 'community.general.timestamp'])
        ->and($steps[$check]['task']['type'])->toBe('assert')
        ->and($steps[$check]['task']['expr'])->toBe('steps["'.$run.'"].outputs.exitCode == 0')
        ->and($steps[$check]['task']['message'])->toContain('steps["'.$run.'"].outputs.stderr');
})->with([
    ['workflow-task-vm-image-ansible', 'Run the Ansible phases', 'Check the Ansible phases'],
    ['workflow-task-vm-host-phase', 'Run the host phase', 'Check the host phase'],
]);
