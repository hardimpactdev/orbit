<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;

function nodeRevokeSeedGrant(E2ETopologyHarness $topology): void
{
    $checkout = escapeshellarg($topology->checkout('gateway'));
    $script = <<<'PHP'
$nodes = \App\Models\Node::query()
    ->whereIn('name', ['control-1', 'app-prod-1'])
    ->pluck('id', 'name');

foreach (['control-1', 'app-prod-1'] as $name) {
    if (! $nodes->has($name)) {
        throw new \RuntimeException("Missing prepared node [{$name}].");
    }
}

\Illuminate\Support\Facades\DB::table('node_access')->updateOrInsert([
    'consumer_node_id' => $nodes->get('control-1'),
    'serving_node_id' => $nodes->get('app-prod-1'),
], [
    'created_at' => now(),
    'updated_at' => now(),
]);

echo 'seeded';
PHP;

    $topology->ssh(
        'gateway',
        "cd {$checkout} && php artisan tinker --execute=".escapeshellarg($script),
        timeoutSeconds: 120,
    );
}

it('revokes node access from a control caller through the gateway api', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::ControlGatewayDevProd, withGatewayApi: true);

    try {
        $topology->withCurrentCheckout(roles: ['control', 'gateway']);
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        E2EGatewayApi::restart(
            $topology->instance('gateway'),
            'node-revoke',
            $topology->checkout('gateway'),
            gatewayIp: $gatewayApiIp,
        );
        E2EGatewayApi::waitForGatewayApi($topology->instance('control'), $config->controlUser, $topology->lease()->sshKeyPair(), gatewayIp: $gatewayApiIp);

        nodeRevokeSeedGrant($topology);

        $revokeResult = $topology->ssh(
            'control',
            sprintf(
                'cd %s && php artisan node:revoke control-1 app-prod-1 --force --json',
                escapeshellarg($topology->checkout('control')),
            ),
            timeoutSeconds: 120,
        );

        $revokePayload = json_decode(trim($revokeResult->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($revokePayload['success']['data'])->toBe([
            'consuming_node' => 'control-1',
            'serving_node' => 'app-prod-1',
            'action' => 'revoked',
            'already_absent' => false,
            'self_lockout' => false,
        ]);

        $showResult = $topology->ssh(
            'control',
            sprintf(
                'cd %s && php artisan node:show app-prod-1 --json',
                escapeshellarg($topology->checkout('control')),
            ),
            timeoutSeconds: 120,
        );

        $showPayload = json_decode(trim($showResult->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($showPayload['success']['data']['node']['grants']['consuming_nodes'])->not->toContain('control-1');
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-control-gateway-dev-prod');
