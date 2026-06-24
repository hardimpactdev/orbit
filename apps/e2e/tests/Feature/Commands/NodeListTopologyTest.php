<?php

declare(strict_types=1);

use App\E2E\Support\E2ETopologyKind;

it('lists nodes from a prepared operator and gateway topology', function (): void {
    $topology = e2eTopology(E2ETopologyKind::OperatorGateway);
    $topology->withCurrentCheckout(roles: ['operator', 'gateway']);
    e2eRestartGatewayApi($topology, 'node-list-operator-gateway');

    try {
        $result = $topology->ssh('operator', "cd {$topology->checkout('operator')} && orbit node:list --json");
        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        $names = array_column($payload['success']['data']['nodes'], 'name');

        expect($names)->toContain('gateway');
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-canary', 'e2e-feature-operator_gateway', 'e2e-feature-operator-gateway');

it('lists nodes from a prepared operator, gateway, and dev topology', function (): void {
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev);
    $topology->withCurrentCheckout(roles: ['operator', 'gateway']);
    e2eRestartGatewayApi($topology, 'node-list-operator-gateway-dev');

    try {
        $result = $topology->ssh('operator', "cd {$topology->checkout('operator')} && orbit node:list --json");
        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        $names = array_column($payload['success']['data']['nodes'], 'name');

        expect($names)->toContain('gateway');
        expect($names)->toContain('app-dev-1');
    } finally {
        $topology->cleanup();
    }
})->group(
    'e2e-feature',
    'e2e-feature-canary',
    'e2e-feature-operator_gateway_app-dev',
    'e2e-feature-operator-gateway-dev',
);

it('lists nodes from a prepared full topology', function (): void {
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdevAppprod);
    $topology->withCurrentCheckout(roles: ['operator', 'gateway']);
    e2eRestartGatewayApi($topology, 'node-list-operator-gateway-dev-prod');

    try {
        $result = $topology->ssh('operator', "cd {$topology->checkout('operator')} && orbit node:list --json");
        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        $names = array_column($payload['success']['data']['nodes'], 'name');

        expect($names)->toContain('gateway');
        expect($names)->toContain('app-dev-1');
        expect($names)->toContain('app-prod-1');
    } finally {
        $topology->cleanup();
    }
})->group(
    'e2e-feature',
    'e2e-feature-canary',
    'e2e-feature-operator_gateway_app-dev_app-prod',
    'e2e-feature-operator-gateway-dev-prod',
);
