<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;

function activityListSeed(E2ETopologyHarness $topology): void
{
    $checkout = escapeshellarg($topology->checkout('gateway'));
    $script = <<<'PHP'
$actor = \App\Models\Node::query()->where('name', 'control-1')->first();

if (! $actor instanceof \App\Models\Node) {
    throw new \RuntimeException('Missing prepared control-1 node.');
}

\Spatie\Activitylog\Models\Activity::query()->delete();

activity('api')
    ->causedBy($actor)
    ->event('node.created')
    ->withProperties([
        'type' => 'write',
        'command' => 'node:new',
        'target_node' => 'app-dev-1',
    ])
    ->log('Recorded node.created');

activity('api')
    ->causedBy($actor)
    ->event('node.removed')
    ->withProperties([
        'type' => 'destructive',
        'command' => 'node:remove',
        'target_node' => 'app-prod-1',
    ])
    ->log('Recorded node.removed');

echo 'seeded';
PHP;

    $topology->ssh(
        'gateway',
        "cd {$checkout} && php artisan tinker --execute=".escapeshellarg($script),
        timeoutSeconds: 120,
    );
}

it('reads gateway activity from a control caller through the gateway api', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::ControlGatewayDevProd, withGatewayApi: true);

    try {
        $topology->withCurrentCheckout(roles: ['control', 'gateway']);
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        E2EGatewayApi::restart(
            $topology->instance('gateway'),
            'activity-list',
            $topology->checkout('gateway'),
            gatewayIp: $gatewayApiIp,
        );
        E2EGatewayApi::waitForGatewayApi($topology->instance('control'), $config->controlUser, $topology->lease()->sshKeyPair(), gatewayIp: $gatewayApiIp);

        activityListSeed($topology);

        $result = $topology->ssh(
            'control',
            sprintf(
                'cd %s && php artisan activity:list --effect=destructive --json',
                escapeshellarg($topology->checkout('control')),
            ),
            timeoutSeconds: 120,
        );

        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);
        $activities = $payload['success']['data']['activities'] ?? null;

        expect($activities)->toBeArray()
            ->and($activities)->toHaveCount(1)
            ->and($activities[0]['type'])->toBe('node.removed')
            ->and($activities[0]['effect'])->toBe('destructive')
            ->and($payload['success']['meta']['filters']['effect'])->toBe('destructive');
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-control-gateway-dev-prod');
