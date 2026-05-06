<?php

declare(strict_types=1);

use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;

it('installs a docker-managed tool on an app node through the gateway', function (): void {
    $topology = e2eTopology(E2ETopologyKind::ControlGatewayDev)
        ->withCurrentCheckout(roles: ['gateway']);

    try {
        toolInstallPrepareComposeFile($topology);

        $result = $topology->ssh(
            'gateway',
            sprintf(
                'cd %s && php artisan tool:install redis --node=app-dev-1 --status=running --json',
                escapeshellarg($topology->checkout('gateway')),
            ),
            timeoutSeconds: 180,
        );
        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($result->successful())->toBeTrue()
            ->and($payload['success']['data']['tool'])->toMatchArray([
                'name' => 'redis',
                'node' => 'app-dev-1',
                'state' => 'running',
            ]);

        $stored = $topology->ssh(
            'gateway',
            'cd '.escapeshellarg($topology->checkout('gateway')).' && php artisan tinker --execute='.escapeshellarg("echo \\App\\Models\\NodeTool::query()->where('name', 'redis')->value('expected_state');"),
            timeoutSeconds: 120,
        );

        expect(trim($stored->output()))->toBe('running');

        $dockerLog = $topology->ssh('dev', 'cat /tmp/orbit-tool-install-docker.log', timeoutSeconds: 60);

        expect($dockerLog->successful())->toBeTrue()
            ->and($dockerLog->output())->toContain('compose -f /opt/orbit/docker-compose.yml pull redis')
            ->and($dockerLog->output())->toContain('compose -f /opt/orbit/docker-compose.yml up -d redis');
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-control-gateway-dev');

function toolInstallPrepareComposeFile(E2ETopologyHarness $topology): void
{
    $compose = <<<'YAML'
services:
  redis:
    image: redis:7-alpine
YAML;
    $docker = <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >> /tmp/orbit-tool-install-docker.log
BASH;

    $topology->ssh(
        'dev',
        sprintf(
            'sudo install -d -m 0755 /opt/orbit && printf %%s %s | sudo tee /opt/orbit/docker-compose.yml >/dev/null && printf %%s %s | sudo tee /usr/local/bin/docker >/dev/null && sudo chmod 0755 /usr/local/bin/docker && sudo install -m 0666 -o orbit -g orbit /dev/null /tmp/orbit-tool-install-docker.log',
            escapeshellarg($compose),
            escapeshellarg($docker),
        ),
        timeoutSeconds: 120,
    );
}
