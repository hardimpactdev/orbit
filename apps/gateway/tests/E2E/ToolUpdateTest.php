<?php

declare(strict_types=1);

use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;

it('updates a docker-managed tool on an app node through the gateway', function (): void {
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev)
        ->withCurrentCheckout(roles: ['gateway']);

    try {
        toolUpdatePrepareComposeFile($topology);
        toolUpdateSeedGatewayIntent($topology);

        $result = $topology->ssh(
            'gateway',
            sprintf(
                'cd %s && orbit tool:update redis --node=app-dev-1 --expected-version=7.2 --json',
                escapeshellarg($topology->checkout('gateway')),
            ),
            timeoutSeconds: 180,
        );
        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($result->successful())->toBeTrue()
            ->and($payload['success']['data']['tool'])->toMatchArray([
                'name' => 'redis',
                'node' => 'app-dev-1',
                'version' => '7.2',
            ]);

        $stored = $topology->ssh(
            'gateway',
            'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg("echo \\App\\Models\\NodeTool::query()->where('name', 'redis')->value('expected_version');"),
            timeoutSeconds: 120,
        );

        expect(trim($stored->output()))->toBe('7.2');

        $dockerLog = $topology->ssh('dev', 'cat /tmp/orbit-tool-update-docker.log', timeoutSeconds: 60);

        expect($dockerLog->successful())->toBeTrue()
            ->and($dockerLog->output())->toContain('compose -f /opt/orbit/tool-update-compose.yml pull redis')
            ->and($dockerLog->output())->toContain('compose -f /opt/orbit/tool-update-compose.yml up -d redis');
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-operator_gateway_app-dev', 'e2e-feature-operator-gateway-dev');

function toolUpdatePrepareComposeFile(E2ETopologyHarness $topology): void
{
    $compose = <<<'YAML'
services:
  redis:
    image: redis:7-alpine
YAML;
    $docker = <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >> /tmp/orbit-tool-update-docker.log
BASH;

    $topology->ssh(
        'dev',
        sprintf(
            'sudo install -d -m 0755 /opt/orbit && printf %%s %s | sudo tee /opt/orbit/tool-update-compose.yml >/dev/null && printf %%s %s | sudo tee /usr/local/bin/docker >/dev/null && sudo chmod 0755 /usr/local/bin/docker && sudo install -m 0666 -o orbit -g orbit /dev/null /tmp/orbit-tool-update-docker.log',
            escapeshellarg($compose),
            escapeshellarg($docker),
        ),
        timeoutSeconds: 120,
    );
}

function toolUpdateSeedGatewayIntent(E2ETopologyHarness $topology): void
{
    $php = <<<'PHP'
$node = \App\Models\Node::query()->where('name', 'app-dev-1')->firstOrFail();

\App\Models\NodeTool::query()->updateOrCreate(
    ['node_id' => $node->id, 'name' => 'redis'],
    [
        'expected_state' => 'running',
        'expected_version' => '6.0',
        'config' => ['compose_path' => '/opt/orbit/tool-update-compose.yml'],
        'credentials' => null,
    ],
);

echo 'seeded';
PHP;

    $topology->ssh(
        'gateway',
        'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg($php),
        timeoutSeconds: 120,
    );
}
