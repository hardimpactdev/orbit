<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2ETopologyKind;

it('updates node metadata from a operator caller through the gateway api', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev, withGatewayApi: true);

    try {
        $topology->withCurrentCheckout(roles: ['operator', 'gateway']);
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        e2eRestartGatewayApi($topology, 'node-update');
        E2EGatewayApi::waitForGatewayApi($topology->instance('operator'), $config->operatorUser, $topology->lease()->sshKeyPair(), gatewayIp: $gatewayApiIp);

        $updateResult = $topology->ssh(
            'operator',
            sprintf(
                'cd %s && orbit node:update app-dev-1 --public-ipv4=203.0.113.45 --json',
                escapeshellarg($topology->checkout('operator')),
            ),
            timeoutSeconds: 120,
        );

        $updatePayload = json_decode(trim($updateResult->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($updatePayload['success']['data']['name'])->toBe('app-dev-1')
            ->and($updatePayload['success']['data']['changed'])->toBe(['public_ipv4'])
            ->and($updatePayload['success']['data']['action'])->toBe('updated');

        $metadataResult = $topology->ssh(
            'gateway',
            sprintf(
                'cd %s && php apps/gateway/artisan tinker --execute=%s',
                escapeshellarg($topology->checkout('gateway')),
                escapeshellarg('echo \App\Models\Node::query()->where("name", "app-dev-1")->value("public_ipv4");'),
            ),
            timeoutSeconds: 120,
        );

        expect(trim($metadataResult->output()))->toBe('203.0.113.45');
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-operator_gateway_app-dev', 'e2e-feature-operator-gateway-dev');
