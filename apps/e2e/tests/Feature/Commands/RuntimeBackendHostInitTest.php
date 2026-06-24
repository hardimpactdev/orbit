<?php

declare(strict_types=1);

use App\E2E\Support\E2ETopologyKind;

it('verifies the VM runtime backend is managed by host init', function (): void {
    $topology = e2eTopology(E2ETopologyKind::OperatorGateway);

    try {
        $runtimeBackend = $topology->ssh(
            'gateway',
            'command -v systemctl >/dev/null 2>&1 && systemctl --version',
            timeoutSeconds: 60,
        );

        expect($runtimeBackend->successful())->toBeTrue()->and($runtimeBackend->output())->toContain('systemd');
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-provider-incus', 'e2e-feature-operator_gateway', 'e2e-feature-operator-gateway');
