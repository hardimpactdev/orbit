<?php

declare(strict_types=1);

use App\E2E\Support\E2ETopologyCapabilities;
use App\E2E\Support\E2ETopologyFactory;
use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;
use App\E2E\Support\E2ETopologyUnavailable;

function e2eVmTopology(E2ETopologyKind $kind): E2ETopologyHarness
{
    try {
        $lease = E2ETopologyFactory::fromEnvironment()
            ->requireCapabilities(E2ETopologyCapabilities::vm())
            ->require($kind);
    } catch (E2ETopologyUnavailable $exception) {
        test()->markTestSkipped($exception->getMessage());
    }

    return new E2ETopologyHarness($lease);
}

it('verifies the VM runtime backend is managed by host init', function (): void {
    $topology = e2eVmTopology(E2ETopologyKind::OperatorGateway);

    try {
        $hostInit = $topology->ssh('gateway', 'systemctl is-active supervisor.service', timeoutSeconds: 60);
        $runtimeBackend = $topology->ssh('gateway', 'sudo supervisorctl status', timeoutSeconds: 60);

        expect(trim($hostInit->output()))->toBe('active')
            ->and($runtimeBackend->successful())->toBeTrue();
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-provider-incus', 'e2e-feature-operator-gateway', 'e2e-feature-control-gateway');
