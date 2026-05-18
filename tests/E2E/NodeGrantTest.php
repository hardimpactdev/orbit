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

        E2EGatewayApi::restart(
            $topology->instance('gateway'),
            'node-grant',
            $topology->checkout('gateway'),
            gatewayIp: $gatewayApiIp,
        );
        E2EGatewayApi::waitForGatewayApi($topology->instance('control'), $config->controlUser, $topology->lease()->sshKeyPair(), gatewayIp: $gatewayApiIp);

        $grantResult = $topology->ssh(
            'control',
            sprintf(
                'cd %s && php artisan node:grant control-1 app-prod-1 --json',
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

        expect($showPayload['success']['data']['node']['grants']['consuming_nodes'])->toContain('control-1');
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-operator-gateway-appdev-appprod', 'e2e-feature-control-gateway-dev-prod');
