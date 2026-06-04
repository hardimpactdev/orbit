<?php

declare(strict_types=1);

use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;

it('removes a host-level tool from an app node through the gateway', function (): void {
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev)
        ->withCurrentCheckout(roles: ['gateway']);

    try {
        e2eRestartGatewayApi($topology, 'tool-remove');
        toolRemovePrepareComposer($topology);
        toolRemoveSeedGatewayIntent($topology);

        $result = $topology->ssh(
            'gateway',
            sprintf(
                'cd %s && orbit tool:remove laravel-installer --node=app-dev-1 --force --json',
                escapeshellarg($topology->checkout('gateway')),
            ),
            timeoutSeconds: 180,
        );
        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($result->successful())->toBeTrue()
            ->and($payload['success']['data']['tool'])->toMatchArray([
                'name' => 'laravel-installer',
                'node' => 'app-dev-1',
            ]);

        $remaining = $topology->ssh(
            'gateway',
            'cd '.escapeshellarg($topology->checkout('gateway')).' && php apps/gateway/artisan tinker --execute='.escapeshellarg("echo \\App\\Models\\NodeTool::query()->where('name', 'laravel-installer')->count();"),
            timeoutSeconds: 120,
        );

        expect(trim($remaining->output()))->toBe('0');

        $composerLog = $topology->ssh('dev', 'cat /tmp/orbit-tool-remove-composer.log', timeoutSeconds: 60);

        expect($composerLog->successful())->toBeTrue()
            ->and($composerLog->output())->toContain('global remove laravel/installer');
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-operator_gateway_app-dev', 'e2e-feature-operator-gateway-dev');

function toolRemovePrepareComposer(E2ETopologyHarness $topology): void
{
    $composer = <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >> /tmp/orbit-tool-remove-composer.log
BASH;

    $topology->ssh(
        'dev',
        sprintf(
            'printf %%s %s | sudo tee /usr/local/bin/composer >/dev/null && sudo chmod 0755 /usr/local/bin/composer && sudo install -m 0666 -o orbit -g orbit /dev/null /tmp/orbit-tool-remove-composer.log',
            escapeshellarg($composer),
        ),
        timeoutSeconds: 120,
    );
}

function toolRemoveSeedGatewayIntent(E2ETopologyHarness $topology): void
{
    $php = <<<'PHP'
$node = \App\Models\Node::query()->where('name', 'app-dev-1')->firstOrFail();

\App\Models\NodeTool::query()->updateOrCreate(
    ['node_id' => $node->id, 'name' => 'laravel-installer'],
    [
        'expected_state' => 'installed',
        'expected_version' => null,
        'config' => null,
        'credentials' => ['fields' => ['password' => 'secret']],
    ],
);

echo 'seeded';
PHP;

    $topology->ssh(
        'gateway',
        'cd '.escapeshellarg($topology->checkout('gateway')).' && php apps/gateway/artisan tinker --execute='.escapeshellarg($php),
        timeoutSeconds: 120,
    );
}
