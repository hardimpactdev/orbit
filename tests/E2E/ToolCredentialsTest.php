<?php

declare(strict_types=1);

use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;

it('reads managed tool credentials from gateway intent', function (): void {
    $topology = e2eTopology(E2ETopologyKind::ControlGatewayDev)
        ->withCurrentCheckout(roles: ['gateway']);

    try {
        toolCredentialsSeedGatewayIntent($topology);

        $result = $topology->ssh(
            'gateway',
            sprintf(
                'cd %s && php artisan tool:credentials redis --node=app-dev-1 --json',
                escapeshellarg($topology->checkout('gateway')),
            ),
            timeoutSeconds: 180,
        );
        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($result->successful())->toBeTrue()
            ->and($payload['success']['data']['credentials'])->toMatchArray([
                'tool' => 'redis',
                'node' => 'app-dev-1',
                'fields' => [
                    'host' => 'redis.app-dev-1.test',
                    'port' => 6379,
                    'username' => 'orbit',
                    'password' => 'secret123',
                ],
            ]);
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-control-gateway-dev');

it('reads opencode-server credentials from gateway intent', function (): void {
    $topology = e2eTopology(E2ETopologyKind::ControlGatewayDev)
        ->withCurrentCheckout(roles: ['gateway']);

    try {
        toolCredentialsSeedOpencodeServerGatewayIntent($topology);

        $result = $topology->ssh(
            'gateway',
            sprintf(
                'cd %s && php artisan tool:credentials opencode-server --node=app-dev-1 --json',
                escapeshellarg($topology->checkout('gateway')),
            ),
            timeoutSeconds: 180,
        );
        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($result->successful())->toBeTrue()
            ->and($payload['success']['data']['credentials'])->toMatchArray([
                'tool' => 'opencode-server',
                'node' => 'app-dev-1',
                'fields' => [
                    'host' => '127.0.0.1',
                    'port' => 4096,
                    'url' => 'https://opencode.app-dev-1.test',
                    'username' => 'orbit',
                    'password' => 'generated-secret',
                ],
            ]);
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-control-gateway-dev');

function toolCredentialsSeedGatewayIntent(E2ETopologyHarness $topology): void
{
    $php = <<<'PHP'
$node = \App\Models\Node::query()->where('name', 'app-dev-1')->firstOrFail();

\App\Models\NodeTool::query()->updateOrCreate(
    ['node_id' => $node->id, 'name' => 'redis'],
    [
        'expected_state' => 'running',
        'expected_version' => null,
        'config' => null,
        'credentials' => [
            'fields' => [
                'host' => 'redis.app-dev-1.test',
                'port' => 6379,
                'username' => 'orbit',
                'password' => 'secret123',
            ],
        ],
    ],
);

echo 'seeded';
PHP;

    $topology->ssh(
        'gateway',
        'cd '.escapeshellarg($topology->checkout('gateway')).' && php artisan tinker --execute='.escapeshellarg($php),
        timeoutSeconds: 120,
    );
}

function toolCredentialsSeedOpencodeServerGatewayIntent(E2ETopologyHarness $topology): void
{
    $php = <<<'PHP'
$node = \App\Models\Node::query()->where('name', 'app-dev-1')->firstOrFail();

\App\Models\NodeTool::query()->updateOrCreate(
    ['node_id' => $node->id, 'name' => 'opencode-server'],
    [
        'expected_state' => 'running',
        'expected_version' => null,
        'config' => null,
        'credentials' => [
            'fields' => [
                'host' => '127.0.0.1',
                'port' => 4096,
                'url' => 'https://opencode.app-dev-1.test',
                'username' => 'orbit',
                'password' => 'generated-secret',
            ],
        ],
    ],
);

echo 'seeded';
PHP;

    $topology->ssh(
        'gateway',
        'cd '.escapeshellarg($topology->checkout('gateway')).' && php artisan tinker --execute='.escapeshellarg($php),
        timeoutSeconds: 120,
    );
}
