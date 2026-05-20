<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;

function activityShowSeed(E2ETopologyHarness $topology): int
{
    $checkout = escapeshellarg($topology->checkout('gateway'));
    $script = <<<'PHP'
$actor = \App\Models\Node::query()->where('name', 'control-1')->first();

if (! $actor instanceof \App\Models\Node) {
    throw new \RuntimeException('Missing prepared control-1 node.');
}

\Spatie\Activitylog\Models\Activity::query()->delete();

$activity = activity('api')
    ->causedBy($actor)
    ->event('node.created')
    ->withProperties([
        'type' => 'write',
        'command' => 'node:new',
        'target_node' => 'app-dev-1',
    ])
    ->log('Recorded node.created');

echo $activity->id;
PHP;

    $result = $topology->ssh(
        'gateway',
        "cd {$checkout} && php artisan tinker --execute=".escapeshellarg($script),
        timeoutSeconds: 120,
    );

    return (int) trim($result->output());
}

it('shows one activity entry on the gateway node as JSON', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev, withGatewayApi: true);

    try {
        $topology->withCurrentCheckout(roles: ['control', 'gateway']);
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        e2eRestartGatewayApi($topology, 'activity-show');
        E2EGatewayApi::waitForGatewayApi($topology->instance('control'), $config->controlUser, $topology->lease()->sshKeyPair(), gatewayIp: $gatewayApiIp);

        $id = activityShowSeed($topology);

        $result = $topology->ssh(
            'control',
            sprintf(
                'cd %s && php artisan activity:show %d --json',
                escapeshellarg($topology->checkout('control')),
                $id,
            ),
            timeoutSeconds: 120,
        );

        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);
        $activity = $payload['success']['data']['activity'] ?? null;

        expect($activity)->toBeArray()
            ->and($activity['id'])->toBe($id)
            ->and($activity['type'])->toBe('node.created')
            ->and($activity['effect'])->toBe('write')
            ->and($payload['success']['data']['related'])->toBeArray()
            ->and($payload['success']['meta']['related_count'])->toBe(0);
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-operator-gateway-appdev', 'e2e-feature-control-gateway-dev');

it('shows one activity entry human output from a control caller', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev, withGatewayApi: true);

    try {
        $topology->withCurrentCheckout(roles: ['control', 'gateway']);
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        e2eRestartGatewayApi($topology, 'activity-show-human');
        E2EGatewayApi::waitForGatewayApi($topology->instance('control'), $config->controlUser, $topology->lease()->sshKeyPair(), gatewayIp: $gatewayApiIp);

        $id = activityShowSeed($topology);

        $result = $topology->ssh(
            'control',
            sprintf(
                'cd %s && php artisan activity:show %d',
                escapeshellarg($topology->checkout('control')),
                $id,
            ),
            timeoutSeconds: 120,
        );

        expect($result->successful())->toBeTrue()
            ->and($result->output())->toContain("Activity: {$id}")
            ->and($result->output())->toContain('Type')
            ->and($result->output())->toContain('node.created')
            ->and($result->output())->toContain('Effect')
            ->and($result->output())->toContain('write');
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-operator-gateway-appdev', 'e2e-feature-control-gateway-dev');

it('returns validation_failed when id is missing from a control caller', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::OperatorGateway);

    try {
        $topology->withCurrentCheckout(roles: ['control']);

        $result = $topology->ssh(
            'control',
            sprintf(
                'cd %s && php artisan activity:show --json',
                escapeshellarg($topology->checkout('control')),
            ),
            timeoutSeconds: 60,
            allowFailure: true,
        );

        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($result->successful())->toBeFalse()
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('id')
            ->and($payload['error']['meta']['reason'])->toBe('missing');
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-operator-gateway', 'e2e-feature-control-gateway');

it('returns validation_failed for an invalid id from a control caller', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::OperatorGateway);

    try {
        $topology->withCurrentCheckout(roles: ['control']);

        $result = $topology->ssh(
            'control',
            sprintf(
                'cd %s && php artisan activity:show nope --json',
                escapeshellarg($topology->checkout('control')),
            ),
            timeoutSeconds: 60,
            allowFailure: true,
        );

        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($result->successful())->toBeFalse()
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['reason'])->toBe('invalid');
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-operator-gateway', 'e2e-feature-control-gateway');
