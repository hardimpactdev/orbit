<?php

declare(strict_types=1);

use App\E2E\Support\E2ETopologyKind;

it('verifies Docker runtime backend and scheduler liveness', function (): void {
    $topology = e2eTopology(E2ETopologyKind::OperatorGateway);

    try {
        $result = $topology->ssh('gateway', 'sudo supervisorctl status', timeoutSeconds: 60);

        expect($result->successful())->toBeTrue();

        $status = $result->output();

        expect($status)
            ->toContain('sshd')
            ->toContain('orbit_scheduler')
            ->toContain('RUNNING');
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-operator_gateway', 'e2e-feature-control-gateway');
