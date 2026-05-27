<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;

function nodeRemoveSeedGrant(E2ETopologyHarness $topology): void
{
    $checkout = escapeshellarg($topology->checkout('gateway'));
    $script = <<<'PHP'
$nodes = \App\Models\Node::query()
    ->whereIn('name', ['operator-1', 'app-prod-1'])
    ->pluck('id', 'name');

foreach (['operator-1', 'app-prod-1'] as $name) {
    if (! $nodes->has($name)) {
        throw new \RuntimeException("Missing prepared node [{$name}].");
    }
}

\Illuminate\Support\Facades\DB::table('node_access')->updateOrInsert([
    'consumer_node_id' => $nodes->get('operator-1'),
    'serving_node_id' => $nodes->get('app-prod-1'),
], [
    'created_at' => now(),
    'updated_at' => now(),
]);

echo 'seeded';
PHP;

    $topology->ssh(
        'gateway',
        "cd {$checkout} && orbit tinker --execute=".escapeshellarg($script),
        timeoutSeconds: 120,
    );
}

it('removes a node from a operator caller through the gateway api', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppprodIngress, withGatewayApi: true);

    try {
        $topology->withCurrentCheckout(roles: ['operator', 'gateway']);
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        e2eRestartGatewayApi($topology, 'node-remove');
        E2EGatewayApi::waitForGatewayApi($topology->instance('operator'), $config->operatorUser, $topology->lease()->sshKeyPair(), gatewayIp: $gatewayApiIp);

        nodeRemoveSeedGrant($topology);

        $removeResult = $topology->ssh(
            'operator',
            sprintf(
                'cd %s && orbit node:remove app-prod-1 --force --json',
                escapeshellarg($topology->checkout('operator')),
            ),
            timeoutSeconds: 120,
        );

        $removePayload = json_decode(trim($removeResult->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($removePayload['success']['data'])->toBe([
            'name' => 'app-prod-1',
            'action' => 'removed',
            'removed_self' => false,
            'wireguard_peer_removed' => false,
            'grants_removed' => 1,
        ]);

        $showResult = $topology->ssh(
            'operator',
            sprintf(
                'cd %s && (orbit node:show app-prod-1 --json || true)',
                escapeshellarg($topology->checkout('operator')),
            ),
            timeoutSeconds: 120,
        );

        $showPayload = json_decode(trim($showResult->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($showPayload['error']['code'])->toBe('node.not_found');
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-operator_gateway_app-prod_ingress');
