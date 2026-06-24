<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2ETopologyKind;

it('grants node access from a operator caller through the gateway api', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppprodIngress, withGatewayApi: true);

    try {
        $topology->withCurrentCheckout(roles: ['operator', 'gateway']);
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        e2eRestartGatewayApi($topology, 'node-grant');
        E2EGatewayApi::waitForGatewayApi(
            $topology->instance('operator'),
            $config->operatorUser,
            $topology->lease()->sshKeyPair(),
            gatewayIp: $gatewayApiIp,
        );

        $grantResult = $topology->ssh(
            'operator',
            sprintf(
                'cd %s && orbit node:grant --preset=operator --json operator-1 app-prod-1',
                escapeshellarg($topology->checkout('operator')),
            ),
            timeoutSeconds: 120,
        );

        $grantPayload = json_decode(trim($grantResult->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($grantPayload['success']['data'])->toBe([
            'consuming_node' => 'operator-1',
            'serving_node' => 'app-prod-1',
            'action' => 'granted',
            'already_granted' => false,
            'permissions' => [
                'app:read',
                'database:read',
                'doctor:verify',
                'firewall_rule:read',
                'node:read',
                'tool:read',
            ],
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

        expect($showPayload['success']['data']['node']['grants']['consuming_nodes'])->toContain([
            'name' => 'operator-1',
            'permissions' => [
                'app:read',
                'database:read',
                'doctor:verify',
                'firewall_rule:read',
                'node:read',
                'tool:read',
            ],
        ]);
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-operator_gateway_app-prod_ingress');
