<?php

declare(strict_types=1);

use Tests\E2E\Support\E2ETopologyKind;

it('lists nodes from a prepared control and gateway topology', function (): void {
    $topology = e2eTopology(E2ETopologyKind::ControlGateway)
        ->withCurrentCheckout(roles: ['control']);

    try {
        $result = $topology->ssh('control', "cd {$topology->checkout('control')} && php artisan node:list --json");
        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        $names = array_column($payload['success']['data']['nodes'], 'name');

        expect($names)->toContain('gateway');
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-control-gateway');

it('lists nodes from a prepared control, gateway, and dev topology', function (): void {
    $topology = e2eTopology(E2ETopologyKind::ControlGatewayDev)
        ->withCurrentCheckout(roles: ['control']);

    try {
        $result = $topology->ssh('control', "cd {$topology->checkout('control')} && php artisan node:list --json");
        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        $names = array_column($payload['success']['data']['nodes'], 'name');

        expect($names)->toContain('gateway');
        expect($names)->toContain('app-dev-1');
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-control-gateway-dev');

it('lists nodes from a prepared full topology', function (): void {
    $topology = e2eTopology(E2ETopologyKind::ControlGatewayDevProd)
        ->withCurrentCheckout(roles: ['control']);

    try {
        $result = $topology->ssh('control', "cd {$topology->checkout('control')} && php artisan node:list --json");
        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        $names = array_column($payload['success']['data']['nodes'], 'name');

        expect($names)->toContain('gateway');
        expect($names)->toContain('app-dev-1');
        expect($names)->toContain('app-prod-1');
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-control-gateway-dev-prod');
