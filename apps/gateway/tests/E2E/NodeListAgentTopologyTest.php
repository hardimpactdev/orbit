<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2ETopologyKind;

it('lists the agent node from a control caller against the agent-extended topology', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAgent, withGatewayApi: true);

    try {
        expect($topology->lease()->devApp())->toBeNull()
            ->and($topology->lease()->prodApp())->toBeNull()
            ->and($topology->lease()->agent())->not->toBeNull();

        $topology->withCurrentCheckout(roles: ['control', 'gateway']);
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        e2eRestartGatewayApi($topology, 'node-list-agent');
        E2EGatewayApi::waitForGatewayApi(
            $topology->instance('control'),
            $config->controlUser,
            $topology->lease()->sshKeyPair(),
            gatewayIp: $gatewayApiIp,
        );

        $result = $topology->ssh(
            'control',
            sprintf(
                'cd %s && php apps/gateway/artisan node:list --role=agent --json',
                escapeshellarg($topology->checkout('control')),
            ),
            timeoutSeconds: 120,
        );

        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);
        $nodes = $payload['success']['data']['nodes'] ?? null;

        expect($nodes)->toBeArray()
            ->and(array_column($nodes, 'name'))->toContain('agent-1');
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-provider-incus', 'e2e-feature-operator_gateway_agent');
