<?php

declare(strict_types=1);

use Tests\E2E\Support\E2ETopologyKind;

pest()->group('e2e-provision');

it('launches a prepared control topology and runs orbit', function (): void {
    $topology = e2eTopology(E2ETopologyKind::ControlGatewayDevProd);
    $passed = false;

    try {
        $result = $topology->ssh('control', "orbit --version | grep -F 'Orbit'");

        expect($result->successful())->toBeTrue($result->output().$result->errorOutput());

        $passed = true;
    } finally {
        e2eProvisionCleanup($passed, topology: $topology);
    }
});
