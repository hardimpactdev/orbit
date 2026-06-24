<?php

declare(strict_types=1);

use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;

it('shows registered tools and tool errors from gateway intent', function (): void {
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev)
        ->withCurrentCheckout(roles: ['gateway']);

    try {
        e2eRestartGatewayApi($topology, 'tool-show');
        toolShowSeedGatewayIntent($topology);

        $jsonResult = $topology->ssh(
            'gateway',
            sprintf(
                'cd %s && orbit tool:show opencode-server --node=app-dev-1 --json',
                escapeshellarg($topology->checkout('gateway')),
            ),
            timeoutSeconds: 120,
            allowFailure: true,
        );
        $jsonPayload = json_decode(trim($jsonResult->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($jsonResult->successful())
            ->toBeTrue()
            ->and($jsonPayload['success']['data']['tool'])
            ->toMatchArray([
                'name' => 'opencode-server',
                'node' => 'app-dev-1',
                'expected_state' => 'running',
            ])
            ->and($jsonPayload['success']['data']['tool'])
            ->toHaveKeys(['name', 'node', 'expected_state', 'managed']);

        $humanResult = $topology->ssh(
            'gateway',
            sprintf(
                'cd %s && orbit tool:show opencode-server --node=app-dev-1',
                escapeshellarg($topology->checkout('gateway')),
            ),
            timeoutSeconds: 120,
            allowFailure: true,
        );

        expect($humanResult->successful())
            ->toBeTrue()
            ->and($humanResult->output())
            ->toContain('Tool: opencode-server')
            ->and($humanResult->output())
            ->toContain('Node')
            ->and($humanResult->output())
            ->toContain('app-dev-1');

        $notFoundResult = $topology->ssh(
            'gateway',
            sprintf(
                'cd %s && orbit tool:show mailpit --node=app-dev-1 --json',
                escapeshellarg($topology->checkout('gateway')),
            ),
            timeoutSeconds: 120,
            allowFailure: true,
        );
        $notFoundPayload = json_decode(trim($notFoundResult->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($notFoundResult->successful())
            ->toBeFalse()
            ->and($notFoundPayload['error']['code'])
            ->toBe('tool.not_found');

        $unsupportedResult = $topology->ssh(
            'gateway',
            sprintf(
                'cd %s && orbit tool:show not-a-real-tool --node=app-dev-1 --json',
                escapeshellarg($topology->checkout('gateway')),
            ),
            timeoutSeconds: 120,
            allowFailure: true,
        );
        $unsupportedPayload = json_decode(
            trim($unsupportedResult->output()),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($unsupportedResult->successful())
            ->toBeFalse()
            ->and($unsupportedPayload['error']['code'])
            ->toBe('tool.unsupported_action')
            ->and($unsupportedPayload['error']['meta']['tool'])
            ->toBe('not-a-real-tool');

        toolShowPrepareOpencodeBinary($topology);

        $liveResult = $topology->ssh(
            'gateway',
            sprintf(
                'cd %s && orbit tool:show opencode-server --node=app-dev-1 --live --json',
                escapeshellarg($topology->checkout('gateway')),
            ),
            timeoutSeconds: 180,
        );
        $livePayload = json_decode(trim($liveResult->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($liveResult->successful())
            ->toBeTrue()
            ->and($livePayload)
            ->toBeArray()
            ->and($livePayload)
            ->toHaveKey('success');

        if (isset($livePayload['success'])) {
            expect($livePayload['success']['data']['tool'])->toHaveKeys(['observed_state', 'observed_version']);
        }
    } finally {
        $topology->cleanup();
    }
})->group(
    'e2e-feature',
    'e2e-feature-canary',
    'e2e-feature-operator_gateway_app-dev',
    'e2e-feature-operator-gateway-dev',
);

function toolShowSeedGatewayIntent(E2ETopologyHarness $topology): void
{
    $php = <<<'PHP'
        $node = \App\Models\Node::query()->where('name', 'app-dev-1')->firstOrFail();

        \App\Models\NodeTool::query()->updateOrCreate(
            ['node_id' => $node->id, 'name' => 'opencode-server'],
            [
                'expected_state' => 'running',
                'expected_version' => null,
                'config' => null,
                'credentials' => null,
            ],
        );

        echo 'seeded';
        PHP;

    $topology->ssh(
        'gateway',
        'cd '.escapeshellarg($topology->checkout('gateway')).' && php apps/gateway/artisan tinker --execute='
            .escapeshellarg($php),
        timeoutSeconds: 120,
    );
}

function toolShowPrepareOpencodeBinary(E2ETopologyHarness $topology): void
{
    $opencode = <<<'BASH'
        #!/usr/bin/env bash
        set -euo pipefail
        echo "opencode 1.0.0"
        BASH;

    $topology->ssh(
        'dev',
        sprintf(
            'printf %%s %s | sudo tee /usr/local/bin/opencode >/dev/null && sudo chmod 0755 /usr/local/bin/opencode',
            escapeshellarg($opencode),
        ),
        timeoutSeconds: 120,
    );
}
