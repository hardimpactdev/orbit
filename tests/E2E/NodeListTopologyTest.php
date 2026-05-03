<?php

declare(strict_types=1);

use Tests\E2E\Support\E2ECommand;
use Tests\E2E\Support\E2ETopologyFactory;
use Tests\E2E\Support\E2ETopologyKind;

pest()->group('e2e-feature', 'e2e-feature-control-gateway-dev-prod');

it('lists nodes from a prepared full topology', function (): void {
    $topology = E2ETopologyFactory::fromEnvironment()
        ->withSshUsers(['control' => 'control'])
        ->require(E2ETopologyKind::ControlGatewayDevProd);

    try {
        $control = $topology->control();
        $key = $topology->sshKeyPair();

        $result = E2ECommand::ssh($control, 'control', $key, 'cd /home/control/orbit && orbit node:list --json');
        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        $names = array_column($payload['success']['data']['nodes'], 'name');

        expect($names)->toContain('gateway');
        expect($names)->toContain('app-dev-1');
        expect($names)->toContain('app-prod-1');
    } finally {
        $topology->cleanup();
    }
});
