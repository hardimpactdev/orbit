<?php

declare(strict_types=1);

use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;

it('lists and filters registered tools from gateway intent', function (): void {
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev)
        ->withCurrentCheckout(roles: ['gateway']);

    try {
        e2eRestartGatewayApi($topology, 'tool-list');
        toolListSeedGatewayIntent($topology);

        $jsonResult = $topology->ssh(
            'gateway',
            sprintf(
                'cd %s && orbit tool:list --json',
                escapeshellarg($topology->checkout('gateway')),
            ),
            timeoutSeconds: 120,
        );
        $jsonPayload = json_decode(trim($jsonResult->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($jsonResult->successful())
            ->toBeTrue()
            ->and($jsonPayload['success']['data']['tools'])
            ->toBeArray()
            ->and($jsonPayload['success']['data']['tools'])
            ->not
            ->toBeEmpty()
            ->and($jsonPayload['success']['data']['tools'][0])
            ->toHaveKeys(['name', 'node', 'expected_state', 'managed']);

        $humanResult = $topology->ssh(
            'gateway',
            sprintf(
                'cd %s && orbit tool:list',
                escapeshellarg($topology->checkout('gateway')),
            ),
            timeoutSeconds: 120,
        );

        expect($humanResult->successful())
            ->toBeTrue()
            ->and($humanResult->output())
            ->toContain('Node:')
            ->and($humanResult->output())
            ->toContain('hermes');

        $nodeFilterResult = $topology->ssh(
            'gateway',
            sprintf(
                'cd %s && orbit tool:list --node=app-dev-1 --json',
                escapeshellarg($topology->checkout('gateway')),
            ),
            timeoutSeconds: 120,
        );
        $nodeFilterPayload = json_decode(
            trim($nodeFilterResult->output()),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );
        $nodeFilterTools = $nodeFilterPayload['success']['data']['tools'] ?? [];

        expect($nodeFilterResult->successful())
            ->toBeTrue()
            ->and($nodeFilterTools)
            ->toBeArray()
            ->and(array_values(array_unique(array_column($nodeFilterTools, 'node'))))
            ->toBe(['app-dev-1']);

        toolListSeedGatewayIntentWithApp($topology);

        $appFilterResult = $topology->ssh(
            'gateway',
            sprintf(
                'cd %s && orbit tool:list --instance=docs --json',
                escapeshellarg($topology->checkout('gateway')),
            ),
            timeoutSeconds: 120,
        );
        $appFilterPayload = json_decode(
            trim($appFilterResult->output()),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );
        $appFilterTools = $appFilterPayload['success']['data']['tools'] ?? [];

        expect($appFilterResult->successful())
            ->toBeTrue()
            ->and($appFilterTools)
            ->toBeArray()
            ->and(array_column($appFilterTools, 'name'))
            ->toContain('hermes');
    } finally {
        $topology->cleanup();
    }
})->group(
    'e2e-feature',
    'e2e-feature-canary',
    'e2e-feature-operator_gateway_app-dev',
    'e2e-feature-operator-gateway-dev',
);

function toolListSeedGatewayIntent(E2ETopologyHarness $topology): void
{
    $php = <<<'PHP'
        $node = \App\Models\Node::query()->where('name', 'app-dev-1')->firstOrFail();

        \App\Models\NodeTool::query()->updateOrCreate(
            ['node_id' => $node->id, 'name' => 'hermes'],
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

function toolListSeedGatewayIntentWithApp(E2ETopologyHarness $topology): void
{
    $php = <<<'PHP'
        $node = \App\Models\Node::query()->where('name', 'app-dev-1')->firstOrFail();

        \App\Models\Project::query()->updateOrCreate(
            ['name' => 'docs'],
            [
                'node_id' => $node->id,
                'path' => '/srv/docs',
                'document_root' => 'public',
            ],
        );

        \App\Models\NodeTool::query()->updateOrCreate(
            ['node_id' => $node->id, 'name' => 'hermes'],
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
