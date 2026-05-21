<?php

declare(strict_types=1);

use App\E2E\Support\E2ETopologyKind;

it('verifies the VM runtime backend is managed by host init', function (): void {
    $topology = e2eTopology(E2ETopologyKind::OperatorGateway);

    try {
        $hostInit = $topology->ssh('gateway', 'systemctl is-active supervisor.service', timeoutSeconds: 60);
        $runtimeBackend = $topology->ssh('gateway', 'sudo supervisorctl status', timeoutSeconds: 60);

        expect(trim($hostInit->output()))->toBe('active')
            ->and($runtimeBackend->successful())->toBeTrue();
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-provider-incus', 'e2e-feature-operator_gateway', 'e2e-feature-control-gateway');
