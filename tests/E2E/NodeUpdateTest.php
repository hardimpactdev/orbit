<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2ETopologyKind;

it('updates node metadata from a control caller through the gateway api', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::ControlGatewayDevProd, withGatewayApi: true);

    try {
        $topology->withCurrentCheckout(roles: ['control', 'gateway']);
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        E2EGatewayApi::restart(
            $topology->instance('gateway'),
            'node-update',
            $topology->checkout('gateway'),
            gatewayIp: $gatewayApiIp,
        );
        E2EGatewayApi::waitForGatewayApi($topology->instance('control'), $config->controlUser, $topology->lease()->sshKeyPair(), gatewayIp: $gatewayApiIp);

        $updateResult = $topology->ssh(
            'control',
            sprintf(
                'cd %s && php artisan node:update app-dev-1 --environment=production --json',
                escapeshellarg($topology->checkout('control')),
            ),
            timeoutSeconds: 120,
        );

        $updatePayload = json_decode(trim($updateResult->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($updatePayload['success']['data']['name'])->toBe('app-dev-1')
            ->and($updatePayload['success']['data']['changed'])->toBe(['environment'])
            ->and($updatePayload['success']['data']['action'])->toBe('updated');

        $showResult = $topology->ssh(
            'control',
            sprintf(
                'cd %s && php artisan node:show app-dev-1 --json',
                escapeshellarg($topology->checkout('control')),
            ),
            timeoutSeconds: 120,
        );

        $showPayload = json_decode(trim($showResult->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($showPayload['success']['data']['node']['environment'])->toBe('production');
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-control-gateway-dev-prod');
