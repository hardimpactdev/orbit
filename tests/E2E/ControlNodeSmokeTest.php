<?php

declare(strict_types=1);

use App\E2E\Support\E2ETopologyKind;

pest()->group('e2e-feature', 'e2e-feature-operator', 'e2e-feature-control');

it('runs the current checkout from a prepared control topology', function (): void {
    $topology = e2eTopology(E2ETopologyKind::Operator);
    $passed = false;

    try {
        $checkouts = e2eCheckout($topology, roles: ['control']);
        $result = $topology->ssh('control', sprintf(
            'cd %s && orbit --version >/dev/null && orbit list --raw >/dev/null',
            escapeshellarg($checkouts['control']),
        ));

        expect($result->successful())->toBeTrue($result->output().$result->errorOutput());

        $passed = true;
    } finally {
        e2eProvisionCleanup($passed, topology: $topology);
    }
});
