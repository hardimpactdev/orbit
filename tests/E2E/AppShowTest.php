<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;

function appShowSeed(E2ETopologyHarness $topology): void
{
    $checkout = escapeshellarg($topology->checkout('gateway'));
    $script = <<<'PHP'
$nodes = \App\Models\Node::query()
    ->whereIn('name', ['control-1', 'app-dev-1'])
    ->pluck('id', 'name');

foreach (['control-1', 'app-dev-1'] as $name) {
    if (! $nodes->has($name)) {
        throw new \RuntimeException("Missing prepared node [{$name}].");
    }
}

\App\Models\App::query()->delete();
\Illuminate\Support\Facades\DB::table('node_access')->delete();
\Illuminate\Support\Facades\DB::table('node_access')->insert([
    'consumer_node_id' => $nodes->get('control-1'),
    'serving_node_id' => $nodes->get('app-dev-1'),
    'created_at' => now(),
    'updated_at' => now(),
]);

\App\Models\App::query()->create([
    'name' => 'docs',
    'node_id' => $nodes->get('app-dev-1'),
    'environment' => 'development',
    'path' => '/srv/docs',
    'document_root' => 'public',
]);

echo 'seeded';
PHP;

    $topology->ssh(
        'gateway',
        "cd {$checkout} && php artisan tinker --execute=".escapeshellarg($script),
        timeoutSeconds: 120,
    );
}

it('shows a registered app from a control caller through the gateway api', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev, withGatewayApi: true);

    try {
        $topology->withCurrentCheckout(roles: ['control', 'gateway']);
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        e2eRestartGatewayApi($topology, 'app-show');
        E2EGatewayApi::waitForGatewayApi($topology->instance('control'), $config->controlUser, $topology->lease()->sshKeyPair(), gatewayIp: $gatewayApiIp);

        appShowSeed($topology);

        $result = $topology->ssh(
            'control',
            sprintf(
                'cd %s && php artisan app:show docs --json',
                escapeshellarg($topology->checkout('control')),
            ),
            timeoutSeconds: 120,
        );

        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);
        $app = $payload['success']['data']['app'] ?? null;

        expect($app)->toBeArray()
            ->and($app['name'])->toBe('docs')
            ->and($app['node'])->toBe('app-dev-1');
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-canary', 'e2e-feature-operator-gateway-appdev', 'e2e-feature-control-gateway-dev');
