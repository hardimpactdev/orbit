<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2ETopologyKind;

it('does not expose app or workspace exec command or api surface', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::OperatorGateway, withGatewayApi: true);
    $passed = false;

    try {
        $topology->withCurrentCheckout(roles: ['operator', 'gateway']);
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        e2eRestartGatewayApi($topology, 'exec-surface-removal');

        E2EGatewayApi::waitForGatewayApi(
            $topology->instance('operator'),
            $config->operatorUser,
            $topology->lease()->sshKeyPair(),
            gatewayIp: $gatewayApiIp,
        );

        $commandListResult = $topology->ssh(
            'operator',
            sprintf(
                'cd %s && orbit list --format=json',
                escapeshellarg($topology->checkout('operator')),
            ),
            timeoutSeconds: 120,
        );

        expect($commandListResult->successful())
            ->toBeTrue(
                "orbit list failed: {$commandListResult->output()}{$commandListResult->errorOutput()}",
            );

        $commandList = json_decode(trim($commandListResult->output()), associative: true, flags: JSON_THROW_ON_ERROR);
        $commandNames = array_column($commandList['commands'] ?? [], 'name');

        expect($commandNames)
            ->not->toContain('app:exec')
            ->not->toContain('workspace:exec');

        $routeListResult = $topology->ssh(
            'gateway',
            sprintf(
                'cd %s && php apps/gateway/artisan route:list --path=api --json',
                escapeshellarg($topology->checkout('gateway')),
            ),
            timeoutSeconds: 120,
        );

        expect($routeListResult->successful())
            ->toBeTrue(
                "gateway route:list failed: {$routeListResult->output()}{$routeListResult->errorOutput()}",
            );

        $routeList = json_decode(trim($routeListResult->output()), associative: true, flags: JSON_THROW_ON_ERROR);
        $routeUris = array_column($routeList, 'uri');

        expect($routeUris)
            ->not->toContain('api/apps/{app}/exec')
            ->not->toContain('api/apps/exec/by-path')
            ->not->toContain('api/workspaces/{name}/exec')
            ->not->toContain('api/workspaces/exec/by-path');

        foreach ([
            'api/apps/example/exec',
            'api/apps/exec/by-path',
            'api/workspaces/example/exec',
            'api/workspaces/exec/by-path',
        ] as $path) {
            $probeResult = $topology->ssh(
                'gateway',
                sprintf(
                    "curl -sS -o /tmp/orbit-exec-removal-probe.out -w '%%{http_code}' -X POST %s -H %s",
                    escapeshellarg("http://{$gatewayApiIp}/{$path}"),
                    escapeshellarg('Accept: application/json'),
                ),
                timeoutSeconds: 120,
            );

            expect($probeResult->successful())
                ->toBeTrue(
                    "exec endpoint probe failed for {$path}: {$probeResult->output()}{$probeResult->errorOutput()}",
                );

            expect(trim($probeResult->output()))->toBe('404');
        }

        $passed = true;
    } finally {
        e2eTopologyCleanup($passed, $topology);
    }
})->group('e2e-feature', 'e2e-provider-incus', 'e2e-feature-operator_gateway', 'e2e-feature-operator-gateway');
