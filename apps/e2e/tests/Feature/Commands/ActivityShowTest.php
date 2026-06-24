<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;

function activityShowSeed(E2ETopologyHarness $topology): int
{
    $checkout = escapeshellarg($topology->checkout('gateway'));
    $script = <<<'PHP'
$actor = \App\Models\Node::query()->where('name', 'operator-1')->first();

if (! $actor instanceof \App\Models\Node) {
    throw new \RuntimeException('Missing prepared operator-1 node.');
}

\Spatie\Activitylog\Models\Activity::query()->delete();

$activity = activity('api')
    ->causedBy($actor)
    ->event('node.created')
    ->withProperties([
        'type' => 'write',
        'command' => 'node:new',
        'target_node' => 'app-dev-1',
    ])
    ->log('Recorded node.created');

echo $activity->id;
PHP;

    $result = $topology->ssh(
        'gateway',
        "cd {$checkout} && php apps/gateway/artisan tinker --execute=".escapeshellarg($script),
        timeoutSeconds: 120,
    );

    return (int) trim($result->output());
}

it('shows activity details and validates ids from an operator caller', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::OperatorGateway, withGatewayApi: true);

    try {
        $topology->withCurrentCheckout(roles: ['operator', 'gateway']);
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        e2eRestartGatewayApi($topology, 'activity-show');
        E2EGatewayApi::waitForGatewayApi($topology->instance('operator'), $config->operatorUser, $topology->lease()->sshKeyPair(), gatewayIp: $gatewayApiIp);

        $id = activityShowSeed($topology);

        $jsonResult = $topology->ssh(
            'operator',
            sprintf(
                'cd %s && orbit activity:show %d --json',
                escapeshellarg($topology->checkout('operator')),
                $id,
            ),
            timeoutSeconds: 120,
        );

        $jsonPayload = json_decode(trim($jsonResult->output()), associative: true, flags: JSON_THROW_ON_ERROR);
        $activity = $jsonPayload['success']['data']['activity'] ?? null;

        expect($jsonResult->successful())->toBeTrue($jsonResult->output().$jsonResult->errorOutput())
            ->and($activity)->toBeArray()
            ->and($activity['id'])->toBe($id)
            ->and($activity['type'])->toBe('node.created')
            ->and($activity['effect'])->toBe('write')
            ->and($jsonPayload['success']['data']['related'])->toBeArray()
            ->and($jsonPayload['success']['meta']['related_count'])->toBe(0);

        $humanResult = $topology->ssh(
            'operator',
            sprintf(
                'cd %s && orbit activity:show %d',
                escapeshellarg($topology->checkout('operator')),
                $id,
            ),
            timeoutSeconds: 120,
        );

        expect($humanResult->successful())->toBeTrue()
            ->and($humanResult->output())->toContain("Activity: {$id}")
            ->and($humanResult->output())->toContain('Type')
            ->and($humanResult->output())->toContain('node.created')
            ->and($humanResult->output())->toContain('Effect')
            ->and($humanResult->output())->toContain('write');

        $missingResult = $topology->ssh(
            'operator',
            sprintf(
                'cd %s && orbit activity:show --json',
                escapeshellarg($topology->checkout('operator')),
            ),
            timeoutSeconds: 60,
            allowFailure: true,
        );

        $missingPayload = json_decode(trim($missingResult->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($missingResult->successful())->toBeFalse()
            ->and($missingPayload['error']['code'])->toBe('validation_failed')
            ->and($missingPayload['error']['meta']['field'])->toBe('id')
            ->and($missingPayload['error']['meta']['reason'])->toBe('missing');

        $invalidResult = $topology->ssh(
            'operator',
            sprintf(
                'cd %s && orbit activity:show nope --json',
                escapeshellarg($topology->checkout('operator')),
            ),
            timeoutSeconds: 60,
            allowFailure: true,
        );

        $invalidPayload = json_decode(trim($invalidResult->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($invalidResult->successful())->toBeFalse()
            ->and($invalidPayload['error']['code'])->toBe('validation_failed')
            ->and($invalidPayload['error']['meta']['reason'])->toBe('invalid');
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-operator_gateway', 'e2e-feature-operator-gateway');
