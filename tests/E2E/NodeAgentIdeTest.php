<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2ETopologyKind;

it('sets node agent IDE intent from a control caller through the gateway api', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::ControlGatewayDevProd, withGatewayApi: true);

    try {
        $topology->withCurrentCheckout(roles: ['control', 'gateway']);
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        E2EGatewayApi::restart(
            $topology->instance('gateway'),
            'node-agent-ide',
            $topology->checkout('gateway'),
            gatewayIp: $gatewayApiIp,
        );
        E2EGatewayApi::waitForGatewayApi($topology->instance('control'), $config->controlUser, $topology->lease()->sshKeyPair(), gatewayIp: $gatewayApiIp);

        $setResult = $topology->ssh(
            'control',
            sprintf(
                'cd %s && php artisan node:agent-ide app-dev-1 opencode --json',
                escapeshellarg($topology->checkout('control')),
            ),
            timeoutSeconds: 120,
        );

        $setPayload = json_decode(trim($setResult->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($setPayload['success']['data']['name'])->toBe('app-dev-1')
            ->and($setPayload['success']['data']['agent_ide'])->toBe([
                'adapter' => 'opencode',
                'source' => 'node',
            ]);

        $showResult = $topology->ssh(
            'control',
            sprintf(
                'cd %s && php artisan node:show app-dev-1 --json',
                escapeshellarg($topology->checkout('control')),
            ),
            timeoutSeconds: 120,
        );

        $showPayload = json_decode(trim($showResult->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($showPayload['success']['data']['node']['agent_ide'])->toBe([
            'adapter' => 'opencode',
            'source' => 'node',
        ]);
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-control-gateway-dev-prod');
