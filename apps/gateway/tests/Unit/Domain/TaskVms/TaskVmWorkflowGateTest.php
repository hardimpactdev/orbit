<?php

declare(strict_types=1);

use App\Infrastructure\GatewayReleases\GatewayReleaseAgentDelivery;
use Symfony\Component\Yaml\Yaml;

/** @return array<string,mixed> one job of a workflow, by name */
function workflowJob(string $workflow, string $job): array
{
    $jobs = Yaml::parseFile(dirname(base_path(), 2)."/swamp/workflows/{$workflow}.yaml")['jobs'];

    return collect($jobs)->firstWhere('name', $job);
}

it('places the agent delivery of the checkout on the nested Gateway before its Swamp tokens are renewed and its code migrates', function (): void {
    $steps = collect(workflowJob('workflow-task-nested-apply', 'apply')['steps'])->keyBy('name');
    $checkout = '/home/orbit/orbit/';

    expect($steps['Place the agent delivery']['task']['inputs']['argv'])
        ->toContain($checkout.GatewayReleaseAgentDelivery::Pin, $checkout.GatewayReleaseAgentDelivery::Manifest)
        ->and(array_column($steps['Re-mint the Swamp tokens']['dependsOn'], 'step'))->toBe(['Place the agent delivery'])
        ->and($steps['Re-mint the Swamp tokens']['guard'])->toBe('${{ !(has(inputs.remint) && inputs.remint) }}')
        // Renewal runs only for remint=true; false or absent skips it. A skipped renewal cannot hide failed delivery.
        ->and($steps['Reconcile the Swamp grants']['dependsOn'])->toBe([
            ['step' => 'Place the agent delivery', 'condition' => ['type' => 'succeeded']],
            ['step' => 'Re-mint the Swamp tokens', 'condition' => ['type' => 'or', 'conditions' => [['type' => 'succeeded'], ['type' => 'skipped']]]],
        ])
        ->and($steps['Migrate']['dependsOn'])->toBe([['step' => 'Reconcile the Swamp grants', 'condition' => ['type' => 'succeeded']]]);
});
