<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;

pest()->group('e2e-feature', 'e2e-feature-control');

function nodeDefaultSeedLocal(E2ETopologyHarness $topology): void
{
    $checkout = escapeshellarg($topology->checkout('control'));
    $script = <<<'PHP'
\Illuminate\Support\Facades\DB::table('local_node_defaults')->delete();
\Illuminate\Support\Facades\DB::table('local_gateway_settings')->delete();
\Illuminate\Support\Facades\DB::table('node_roles')->delete();
\App\Models\Node::query()->delete();

$nodeId = \Illuminate\Support\Facades\DB::table('nodes')->insertGetId([
    'name' => 'app-dev-1',
    'role' => 'app',
    'host' => '10.6.0.7',
    'wireguard_address' => '10.6.0.7',
    'user' => 'orbit',
    'orbit_path' => '/home/orbit/orbit',
    'status' => 'active',
    'environment' => 'development',
    'platform' => 'ubuntu_24-04',
    'created_at' => now(),
    'updated_at' => now(),
]);

\Illuminate\Support\Facades\DB::table('node_roles')->insert([
    'node_id' => $nodeId,
    'role' => 'app-development',
    'status' => 'active',
    'settings' => json_encode(['tld' => 'test'], JSON_THROW_ON_ERROR),
    'converged_at' => now(),
    'created_at' => now(),
    'updated_at' => now(),
]);

echo 'seeded';
PHP;

    $topology->ssh(
        'control',
        "cd {$checkout} && php artisan tinker --execute=".escapeshellarg($script),
        timeoutSeconds: 120,
    );
}

function nodeDefaultSeedWithDefault(E2ETopologyHarness $topology): void
{
    $checkout = escapeshellarg($topology->checkout('control'));
    $script = <<<'PHP'
\Illuminate\Support\Facades\DB::table('local_node_defaults')->delete();
\Illuminate\Support\Facades\DB::table('local_gateway_settings')->delete();
\Illuminate\Support\Facades\DB::table('node_roles')->delete();
\App\Models\Node::query()->delete();

$nodeId = \Illuminate\Support\Facades\DB::table('nodes')->insertGetId([
    'name' => 'app-dev-1',
    'role' => 'app',
    'host' => '10.6.0.7',
    'wireguard_address' => '10.6.0.7',
    'user' => 'orbit',
    'orbit_path' => '/home/orbit/orbit',
    'status' => 'active',
    'environment' => 'development',
    'platform' => 'ubuntu_24-04',
    'created_at' => now(),
    'updated_at' => now(),
]);

\Illuminate\Support\Facades\DB::table('node_roles')->insert([
    'node_id' => $nodeId,
    'role' => 'app-development',
    'status' => 'active',
    'settings' => json_encode(['tld' => 'test'], JSON_THROW_ON_ERROR),
    'converged_at' => now(),
    'created_at' => now(),
    'updated_at' => now(),
]);

\Illuminate\Support\Facades\DB::table('local_node_defaults')->insert([
    'default_node_name' => 'app-dev-1',
    'created_at' => now(),
    'updated_at' => now(),
]);

echo 'seeded';
PHP;

    $topology->ssh(
        'control',
        "cd {$checkout} && php artisan tinker --execute=".escapeshellarg($script),
        timeoutSeconds: 120,
    );
}

it('shows the current local default from a control node', function (): void {
    $topology = e2eTopology(E2ETopologyKind::Control)
        ->withCurrentCheckout(roles: ['control']);

    try {
        nodeDefaultSeedWithDefault($topology);

        $result = $topology->ssh(
            'control',
            sprintf(
                'cd %s && php artisan node:default --json',
                escapeshellarg($topology->checkout('control')),
            ),
            timeoutSeconds: 60,
            allowFailure: true,
        );

        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($payload['success']['data']['action'])->toBe('show')
            ->and($payload['success']['data']['default_node']['name'])->toBe('app-dev-1')
            ->and($payload['success']['data']['default_node']['role'])->toBe('app')
            ->and($payload['success']['data']['default_node']['environment'])->toBe('development');
    } finally {
        $topology->cleanup();
    }
});

it('shows human output for the current local default from a control node', function (): void {
    $topology = e2eTopology(E2ETopologyKind::Control)
        ->withCurrentCheckout(roles: ['control']);

    try {
        nodeDefaultSeedWithDefault($topology);

        $result = $topology->ssh(
            'control',
            sprintf(
                'cd %s && php artisan node:default',
                escapeshellarg($topology->checkout('control')),
            ),
            timeoutSeconds: 60,
        );

        expect($result->successful())->toBeTrue()
            ->and($result->output())->toContain('app-dev-1');
    } finally {
        $topology->cleanup();
    }
});

it('shows null default when no default is set on a control node', function (): void {
    $topology = e2eTopology(E2ETopologyKind::Control)
        ->withCurrentCheckout(roles: ['control']);

    try {
        nodeDefaultSeedLocal($topology);

        $result = $topology->ssh(
            'control',
            sprintf(
                'cd %s && php artisan node:default --json',
                escapeshellarg($topology->checkout('control')),
            ),
            timeoutSeconds: 60,
        );

        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($payload['success']['data']['action'])->toBe('show')
            ->and($payload['success']['data']['default_node'])->toBeNull();
    } finally {
        $topology->cleanup();
    }
});

it('sets a local default node by name on a control node', function (): void {
    $topology = e2eTopology(E2ETopologyKind::Control)
        ->withCurrentCheckout(roles: ['control']);

    try {
        nodeDefaultSeedLocal($topology);

        $result = $topology->ssh(
            'control',
            sprintf(
                'cd %s && php artisan node:default app-dev-1 --json',
                escapeshellarg($topology->checkout('control')),
            ),
            timeoutSeconds: 60,
        );

        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($payload['success']['data']['action'])->toBe('set')
            ->and($payload['success']['data']['default_node']['name'])->toBe('app-dev-1')
            ->and($payload['success']['data']['default_node']['role'])->toBe('app')
            ->and($payload['success']['data']['default_node']['environment'])->toBe('development');
    } finally {
        $topology->cleanup();
    }
});

it('clears the local default node from a control node with was_set true', function (): void {
    $topology = e2eTopology(E2ETopologyKind::Control)
        ->withCurrentCheckout(roles: ['control']);

    try {
        nodeDefaultSeedWithDefault($topology);

        $result = $topology->ssh(
            'control',
            sprintf(
                'cd %s && php artisan node:default --clear --json',
                escapeshellarg($topology->checkout('control')),
            ),
            timeoutSeconds: 60,
        );

        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($payload['success']['data']['action'])->toBe('clear')
            ->and($payload['success']['data']['default_node'])->toBeNull()
            ->and($payload['success']['meta']['was_set'])->toBeTrue();
    } finally {
        $topology->cleanup();
    }
});

it('clears with was_set false when no default was set on a control node', function (): void {
    $topology = e2eTopology(E2ETopologyKind::Control)
        ->withCurrentCheckout(roles: ['control']);

    try {
        nodeDefaultSeedLocal($topology);

        $result = $topology->ssh(
            'control',
            sprintf(
                'cd %s && php artisan node:default --clear --json',
                escapeshellarg($topology->checkout('control')),
            ),
            timeoutSeconds: 60,
        );

        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($payload['success']['data']['action'])->toBe('clear')
            ->and($payload['success']['data']['default_node'])->toBeNull()
            ->and($payload['success']['meta']['was_set'])->toBeFalse();
    } finally {
        $topology->cleanup();
    }
});

it('rejects name not found on a control node', function (): void {
    $topology = e2eTopology(E2ETopologyKind::Control)
        ->withCurrentCheckout(roles: ['control']);

    try {
        nodeDefaultSeedLocal($topology);

        $result = $topology->ssh(
            'control',
            sprintf(
                'cd %s && php artisan node:default nonexistent --json',
                escapeshellarg($topology->checkout('control')),
            ),
            timeoutSeconds: 60,
            allowFailure: true,
        );

        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($result->successful())->toBeFalse()
            ->and($payload['error']['code'])->toBe('node.not_found');
    } finally {
        $topology->cleanup();
    }
});

it('sets a default node through the gateway api from a control node', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::ControlGatewayDev, withGatewayApi: true);

    try {
        $topology->withCurrentCheckout(roles: ['control', 'gateway']);
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        E2EGatewayApi::restart(
            $topology->instance('gateway'),
            'node-default-set',
            $topology->checkout('gateway'),
            gatewayIp: $gatewayApiIp,
        );
        E2EGatewayApi::waitForGatewayApi($topology->instance('control'), $config->controlUser, $topology->lease()->sshKeyPair(), gatewayIp: $gatewayApiIp);

        $result = $topology->ssh(
            'control',
            sprintf(
                'cd %s && php artisan node:default app-dev-1 --json',
                escapeshellarg($topology->checkout('control')),
            ),
            timeoutSeconds: 120,
        );

        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($payload['success']['data']['action'])->toBe('set')
            ->and($payload['success']['data']['default_node']['name'])->toBe('app-dev-1')
            ->and($payload['success']['data']['default_node']['environment'])->toBe('development');
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-control-gateway-dev');
