<?php

declare(strict_types=1);

use App\E2E\Support\E2ETopologyKind;

it('verifies Docker runtime backend and scheduler liveness', function (): void {
    $topology = e2eTopology(E2ETopologyKind::OperatorGateway);

    try {
        $runtimeContainer = e2eRuntimeContainerName($topology, 'gateway');
        $inspection = $topology->ssh(
            'gateway',
            sprintf(
                "sudo docker inspect --format '{{.State.Running}} {{.HostConfig.RestartPolicy.Name}} {{range .Config.Env}}{{println .}}{{end}}' %s",
                escapeshellarg($runtimeContainer),
            ),
            timeoutSeconds: 60,
        );
        $schedulerTick = e2eRunInRoleRuntime($topology, 'gateway', 'orbit orbit-scheduler --once', timeoutSeconds: 60);

        expect($inspection->successful())->toBeTrue()
            ->and($inspection->output())
            ->toContain('true')
            ->toContain('unless-stopped')
            ->and($schedulerTick->successful())->toBeTrue()
            ->and($schedulerTick->output())->toContain('Orbit Scheduler tick completed');
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-operator_gateway', 'e2e-feature-operator-gateway');
