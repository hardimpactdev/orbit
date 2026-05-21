<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2ETopologyKind;

it('grants node access from a control caller through the gateway api', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdevAppprod, withGatewayApi: true);

    try {
        $topology->withCurrentCheckout(roles: ['control', 'gateway']);
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        e2eRestartGatewayApi($topology, 'node-grant');
        E2EGatewayApi::waitForGatewayApi($topology->instance('control'), $config->controlUser, $topology->lease()->sshKeyPair(), gatewayIp: $gatewayApiIp);

        $grantResult = $topology->ssh(
            'control',
            sprintf(
                'cd %s && php artisan node:grant --preset=operator --json control-1 app-prod-1',
                escapeshellarg($topology->checkout('control')),
            ),
            timeoutSeconds: 120,
        );

        $grantPayload = json_decode(trim($grantResult->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($grantPayload['success']['data'])->toBe([
            'consuming_node' => 'control-1',
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
                'tool:restart',
            ],
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

        expect($showPayload['success']['data']['node']['grants']['consuming_nodes'])->toContain([
            'name' => 'control-1',
            'permissions' => [
                'app:read',
                'database:read',
                'doctor:verify',
                'firewall_rule:read',
                'node:read',
                'tool:read',
                'tool:restart',
            ],
        ]);
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-operator_gateway_app-dev_app-prod', 'e2e-feature-control-gateway-dev-prod');
