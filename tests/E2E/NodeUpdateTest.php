<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2ETopologyKind;

it('updates node metadata from a control caller through the gateway api', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdevAppprod, withGatewayApi: true);

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
                'cd %s && php artisan node:update app-dev-1 --public-ipv4=203.0.113.45 --json',
                escapeshellarg($topology->checkout('control')),
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
                'cd %s && php artisan tinker --execute=%s',
                escapeshellarg($topology->checkout('gateway')),
                escapeshellarg('echo \App\Models\Node::query()->where("name", "app-dev-1")->value("public_ipv4");'),
            ),
            timeoutSeconds: 120,
        );

        expect(trim($metadataResult->output()))->toBe('203.0.113.45');
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-operator-gateway-appdev-appprod', 'e2e-feature-control-gateway-dev-prod');
