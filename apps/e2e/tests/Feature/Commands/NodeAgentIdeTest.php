<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2ETopologyKind;

it('sets node agent IDE intent from a operator caller through the gateway api', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev, withGatewayApi: true);

    try {
        $topology->withCurrentCheckout(roles: ['operator', 'gateway']);
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        e2eRestartGatewayApi($topology, 'node-agent-ide');
        E2EGatewayApi::waitForGatewayApi(
            $topology->instance('operator'),
            $config->operatorUser,
            $topology->lease()->sshKeyPair(),
            gatewayIp: $gatewayApiIp,
        );

        $setResult = $topology->ssh(
            'operator',
            sprintf(
                'cd %s && orbit node:agent-ide app-dev-1 opencode --json',
                escapeshellarg($topology->checkout('operator')),
            ),
            timeoutSeconds: 120,
        );

        $setPayload = json_decode(trim($setResult->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($setPayload['success']['data']['name'])
            ->toBe('app-dev-1')
            ->and($setPayload['success']['data']['agent_ide'])
            ->toBe([
                'adapter' => 'opencode',
                'source' => 'node',
            ]);

        $showResult = $topology->ssh(
            'operator',
            sprintf(
                'cd %s && orbit node:show app-dev-1 --json',
                escapeshellarg($topology->checkout('operator')),
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
})->group('e2e-feature', 'e2e-feature-operator_gateway_app-dev', 'e2e-feature-operator-gateway-dev');
