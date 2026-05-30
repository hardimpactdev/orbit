<?php

declare(strict_types=1);

use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;

it('lists registered tools from gateway intent as JSON', function (): void {
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev)
        ->withCurrentCheckout(roles: ['gateway']);

    try {
        e2eRestartGatewayApi($topology, 'tool-list-json');
        toolListSeedGatewayIntent($topology);

        $result = $topology->ssh(
            'gateway',
            sprintf(
                'cd %s && orbit tool:list --json',
                escapeshellarg($topology->checkout('gateway')),
            ),
            timeoutSeconds: 120,
        );
        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($result->successful())->toBeTrue()
            ->and($payload['success']['data']['tools'])->toBeArray()
            ->and($payload['success']['data']['tools'])->not->toBeEmpty()
            ->and($payload['success']['data']['tools'][0])->toHaveKeys(['name', 'node', 'expected_state', 'managed']);
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-canary', 'e2e-feature-operator_gateway_app-dev', 'e2e-feature-operator-gateway-dev');

it('lists registered tools from gateway intent as human output', function (): void {
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev)
        ->withCurrentCheckout(roles: ['gateway']);

    try {
        e2eRestartGatewayApi($topology, 'tool-list-human');
        toolListSeedGatewayIntent($topology);

        $result = $topology->ssh(
            'gateway',
            sprintf(
                'cd %s && orbit tool:list',
                escapeshellarg($topology->checkout('gateway')),
            ),
            timeoutSeconds: 120,
        );

        expect($result->successful())->toBeTrue()
            ->and($result->output())->toContain('Node:')
            ->and($result->output())->toContain('redis');
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-canary', 'e2e-feature-operator_gateway_app-dev', 'e2e-feature-operator-gateway-dev');

it('filters tool list by node', function (): void {
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev)
        ->withCurrentCheckout(roles: ['gateway']);

    try {
        e2eRestartGatewayApi($topology, 'tool-list-node');
        toolListSeedGatewayIntent($topology);

        $result = $topology->ssh(
            'gateway',
            sprintf(
                'cd %s && orbit tool:list --node=app-dev-1 --json',
                escapeshellarg($topology->checkout('gateway')),
            ),
            timeoutSeconds: 120,
        );
        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);
        $tools = $payload['success']['data']['tools'] ?? [];

        expect($result->successful())->toBeTrue()
            ->and($tools)->toBeArray()
            ->and(array_unique(array_column($tools, 'node')))->toBe(['app-dev-1']);
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-canary', 'e2e-feature-operator_gateway_app-dev', 'e2e-feature-operator-gateway-dev');

it('filters tool list by app', function (): void {
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev)
        ->withCurrentCheckout(roles: ['gateway']);

    try {
        e2eRestartGatewayApi($topology, 'tool-list-app');
        toolListSeedGatewayIntentWithApp($topology);

        $result = $topology->ssh(
            'gateway',
            sprintf(
                'cd %s && orbit tool:list --app=docs --json',
                escapeshellarg($topology->checkout('gateway')),
            ),
            timeoutSeconds: 120,
        );
        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);
        $tools = $payload['success']['data']['tools'] ?? [];

        expect($result->successful())->toBeTrue()
            ->and($tools)->toBeArray()
            ->and(array_column($tools, 'name'))->toContain('redis');
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-canary', 'e2e-feature-operator_gateway_app-dev', 'e2e-feature-operator-gateway-dev');

function toolListSeedGatewayIntent(E2ETopologyHarness $topology): void
{
    $php = <<<'PHP'
$node = \App\Models\Node::query()->where('name', 'app-dev-1')->firstOrFail();

\App\Models\NodeTool::query()->updateOrCreate(
    ['node_id' => $node->id, 'name' => 'redis'],
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
        'cd '.escapeshellarg($topology->checkout('gateway')).' && php apps/gateway/artisan tinker --execute='.escapeshellarg($php),
        timeoutSeconds: 120,
    );
}

function toolListSeedGatewayIntentWithApp(E2ETopologyHarness $topology): void
{
    $php = <<<'PHP'
$node = \App\Models\Node::query()->where('name', 'app-dev-1')->firstOrFail();

\App\Models\App::query()->updateOrCreate(
    ['name' => 'docs'],
    [
        'node_id' => $node->id,
        'path' => '/srv/docs',
        'document_root' => 'public',
    ],
);

\App\Models\NodeTool::query()->updateOrCreate(
    ['node_id' => $node->id, 'name' => 'redis'],
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
        'cd '.escapeshellarg($topology->checkout('gateway')).' && php apps/gateway/artisan tinker --execute='.escapeshellarg($php),
        timeoutSeconds: 120,
    );
}
