<?php

declare(strict_types=1);

use Tests\E2E\Support\E2ECommand;
use Tests\E2E\Support\E2ETopologyFactory;
use Tests\E2E\Support\E2ETopologyKind;

pest()->group('e2e-feature', 'e2e-feature-control-gateway-dev-prod');

it('lists nodes from a prepared full topology', function (): void {
    $topology = E2ETopologyFactory::fromEnvironment()->require(E2ETopologyKind::ControlGatewayDevProd);

    try {
        $control = $topology->control();
        $key = $topology->sshKeyPair();

        $result = E2ECommand::ssh($control, 'control', $key, 'cd /home/control/orbit && orbit node:list --json');
        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($payload['success']['data']['nodes'])->toContain(
            fn (array $node): bool => $node['name'] === 'gateway'
        );
        expect($payload['success']['data']['nodes'])->toContain(
            fn (array $node): bool => $node['name'] === 'app-dev-1'
        );
        expect($payload['success']['data']['nodes'])->toContain(
            fn (array $node): bool => $node['name'] === 'app-prod-1'
        );
    } finally {
        $topology->cleanup();
    }
});
