<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2ETopologyKind;

it('manages and lists node access permissions from the gateway', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev, withGatewayApi: true);

    try {
        $topology->withCurrentCheckout(roles: ['operator', 'gateway']);
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        e2eRestartGatewayApi($topology, 'node-permissions');
        E2EGatewayApi::waitForGatewayApi(
            $topology->instance('operator'),
            $config->operatorUser,
            $topology->lease()->sshKeyPair(),
            gatewayIp: $gatewayApiIp,
        );

        $setResult = $topology->ssh(
            'gateway',
            sprintf(
                'cd %s && orbit node:permissions operator-1 app-dev-1 --preset=operator --json',
                escapeshellarg($topology->checkout('gateway')),
            ),
            timeoutSeconds: 120,
        );

        $setPayload = json_decode(trim($setResult->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($setPayload['success']['data']['consuming_node'])->toBe('operator-1')
            ->and($setPayload['success']['data']['serving_node'])->toBe('app-dev-1')
            ->and($setPayload['success']['data']['permissions'])->toContain('node:read');

        $listResult = $topology->ssh(
            'gateway',
            sprintf(
                'cd %s && orbit node:permissions operator-1 app-dev-1 --json',
                escapeshellarg($topology->checkout('gateway')),
            ),
            timeoutSeconds: 120,
        );

        $listPayload = json_decode(trim($listResult->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($listPayload['success']['data']['consuming_node'])->toBe('operator-1')
            ->and($listPayload['success']['data']['serving_node'])->toBe('app-dev-1')
            ->and($listPayload['success']['data']['permissions'])->toContain('node:read');
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-operator_gateway_app-dev', 'e2e-feature-operator-gateway-dev');
