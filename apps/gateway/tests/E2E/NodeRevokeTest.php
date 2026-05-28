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
        "cd {$checkout} && php apps/gateway/artisan tinker --execute=".escapeshellarg($script),
        timeoutSeconds: 120,
    );
}

it('revokes node access from a operator caller through the gateway api', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppprodIngress, withGatewayApi: true);

    try {
        $topology->withCurrentCheckout(roles: ['operator', 'gateway']);
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        e2eRestartGatewayApi($topology, 'node-revoke');
        E2EGatewayApi::waitForGatewayApi($topology->instance('operator'), $config->operatorUser, $topology->lease()->sshKeyPair(), gatewayIp: $gatewayApiIp);

        nodeRevokeSeedGrant($topology);

        $revokeResult = $topology->ssh(
            'operator',
            sprintf(
                'cd %s && orbit node:revoke operator-1 app-prod-1 --force --json',
                escapeshellarg($topology->checkout('operator')),
            ),
            timeoutSeconds: 120,
        );

        $revokePayload = json_decode(trim($revokeResult->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($revokePayload['success']['data'])->toBe([
            'consuming_node' => 'operator-1',
            'serving_node' => 'app-prod-1',
            'action' => 'revoked',
            'already_absent' => false,
            'self_lockout' => false,
            'was_gateway_admin' => true,
        ]);

        $showResult = $topology->ssh(
            'operator',
            sprintf(
                'cd %s && orbit node:show app-prod-1 --json',
                escapeshellarg($topology->checkout('operator')),
            ),
            timeoutSeconds: 120,
        );

        $showPayload = json_decode(trim($showResult->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($showPayload['success']['data']['node']['grants']['consuming_nodes'])->not->toContain('operator-1');
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-operator_gateway_app-prod_ingress');
