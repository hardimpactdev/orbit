<?php

declare(strict_types=1);

use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;

it('installs a host-level tool on an app node through the gateway', function (): void {
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev)
        ->withCurrentCheckout(roles: ['gateway']);

    try {
        e2eRestartGatewayApi($topology, 'tool-install');
        toolInstallPrepareComposer($topology);

        $result = $topology->ssh(
            'gateway',
            sprintf(
                'cd %s && orbit tool:install laravel-installer --node=app-dev-1 --json',
                escapeshellarg($topology->checkout('gateway')),
            ),
            timeoutSeconds: 180,
        );
        $data = e2eJsonCommandData(e2eJsonCommandPayload($result->output()));

        expect($result->successful())
            ->toBeTrue()
            ->and($data['tool'])
            ->toMatchArray([
                'name' => 'laravel-installer',
                'node' => 'app-dev-1',
                'state' => 'installed',
            ]);

        $stored = $topology->ssh(
            'gateway',
            'cd '.escapeshellarg($topology->checkout('gateway')).' && php apps/gateway/artisan tinker --execute='
                .escapeshellarg(
                    "echo \\App\\Models\\NodeTool::query()->where('name', 'laravel-installer')->value('expected_state');",
                ),
            timeoutSeconds: 120,
        );

        expect(trim($stored->output()))->toBe('installed');

        $composerLog = $topology->ssh('dev', 'cat /tmp/orbit-tool-install-composer.log', timeoutSeconds: 60);

        expect($composerLog->successful())
            ->toBeTrue()
            ->and($composerLog->output())
            ->toContain('global require laravel/installer');
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-operator_gateway_app-dev', 'e2e-feature-operator-gateway-dev');

function toolInstallPrepareComposer(E2ETopologyHarness $topology): void
{
    $composer = <<<'BASH'
        #!/usr/bin/env bash
        set -euo pipefail
        printf '%s\n' "$*" >> /tmp/orbit-tool-install-composer.log
        BASH;

    $topology->ssh(
        'dev',
        sprintf(
            'printf %%s %s | sudo tee /usr/local/bin/composer >/dev/null && sudo chmod 0755 /usr/local/bin/composer && sudo install -m 0666 -o orbit -g orbit /dev/null /tmp/orbit-tool-install-composer.log',
            escapeshellarg($composer),
        ),
        timeoutSeconds: 120,
    );
}
