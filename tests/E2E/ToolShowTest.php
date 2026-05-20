<?php

declare(strict_types=1);

use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;

it('shows a registered tool from gateway intent as JSON', function (): void {
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev)
        ->withCurrentCheckout(roles: ['gateway']);

    try {
        toolShowSeedGatewayIntent($topology);

        $result = $topology->ssh(
            'gateway',
            sprintf(
                'cd %s && php artisan tool:show redis --node=app-dev-1 --json',
                escapeshellarg($topology->checkout('gateway')),
            ),
            timeoutSeconds: 120,
            allowFailure: true,
        );
        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($result->successful())->toBeTrue()
            ->and($payload['success']['data']['tool'])->toMatchArray([
                'name' => 'redis',
                'node' => 'app-dev-1',
                'expected_state' => 'running',
            ])
            ->and($payload['success']['data']['tool'])->toHaveKeys(['name', 'node', 'expected_state', 'managed']);
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-canary', 'e2e-feature-operator-gateway-appdev', 'e2e-feature-control-gateway-dev');

it('shows a registered tool from gateway intent as human output', function (): void {
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev)
        ->withCurrentCheckout(roles: ['gateway']);

    try {
        toolShowSeedGatewayIntent($topology);

        $result = $topology->ssh(
            'gateway',
            sprintf(
                'cd %s && php artisan tool:show redis --node=app-dev-1',
                escapeshellarg($topology->checkout('gateway')),
            ),
            timeoutSeconds: 120,
            allowFailure: true,
        );

        expect($result->successful())->toBeTrue()
            ->and($result->output())->toContain('Tool: redis')
            ->and($result->output())->toContain('Node')
            ->and($result->output())->toContain('app-dev-1');
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-canary', 'e2e-feature-operator-gateway-appdev', 'e2e-feature-control-gateway-dev');

it('returns tool.not_found error for unknown tool name in the gateway registry', function (): void {
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev)
        ->withCurrentCheckout(roles: ['gateway']);

    try {
        $result = $topology->ssh(
            'gateway',
            sprintf(
                'cd %s && php artisan tool:show redis --node=app-dev-1 --json',
                escapeshellarg($topology->checkout('gateway')),
            ),
            timeoutSeconds: 120,
            allowFailure: true,
        );
        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($result->successful())->toBeFalse()
            ->and($payload['error']['code'])->toBe('tool.not_found');
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-canary', 'e2e-feature-operator-gateway-appdev', 'e2e-feature-control-gateway-dev');

it('returns tool.unsupported_action error for an unsupported tool catalog name', function (): void {
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev)
        ->withCurrentCheckout(roles: ['gateway']);

    try {
        $result = $topology->ssh(
            'gateway',
            sprintf(
                'cd %s && php artisan tool:show not-a-real-tool --node=app-dev-1 --json',
                escapeshellarg($topology->checkout('gateway')),
            ),
            timeoutSeconds: 120,
            allowFailure: true,
        );
        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($result->successful())->toBeFalse()
            ->and($payload['error']['code'])->toBe('tool.unsupported_action')
            ->and($payload['error']['meta']['tool'])->toBe('not-a-real-tool');
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-canary', 'e2e-feature-operator-gateway-appdev', 'e2e-feature-control-gateway-dev');

it('includes live key in JSON output when --live flag is passed', function (): void {
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev)
        ->withCurrentCheckout(roles: ['gateway']);

    try {
        toolShowSeedGatewayIntent($topology);

        $result = $topology->ssh(
            'gateway',
            sprintf(
                'cd %s && php artisan tool:show redis --node=app-dev-1 --live --json',
                escapeshellarg($topology->checkout('gateway')),
            ),
            timeoutSeconds: 180,
        );
        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        // --live requests live node inspection; the success path returns the
        // normal tool envelope plus observed fields.
        expect($payload)->toBeArray()
            ->and($payload)->toHaveKey('success');

        if (isset($payload['success'])) {
            expect($payload['success']['data']['tool'])->toHaveKeys(['observed_state', 'observed_version']);
        }
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-canary', 'e2e-feature-operator-gateway-appdev', 'e2e-feature-control-gateway-dev');

function toolShowSeedGatewayIntent(E2ETopologyHarness $topology): void
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
        'cd '.escapeshellarg($topology->checkout('gateway')).' && php artisan tinker --execute='.escapeshellarg($php),
        timeoutSeconds: 120,
    );
}
