<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;

function appListSeed(E2ETopologyHarness $topology): void
{
    $checkout = escapeshellarg($topology->checkout('gateway'));
    $script = <<<'PHP'
$nodes = \App\Models\Node::query()
    ->whereIn('name', ['control-1', 'app-dev-1', 'app-prod-1'])
    ->pluck('id', 'name');

foreach (['control-1', 'app-dev-1', 'app-prod-1'] as $name) {
    if (! $nodes->has($name)) {
        throw new \RuntimeException("Missing prepared node [{$name}].");
    }
}

\App\Models\App::query()->delete();
\Illuminate\Support\Facades\DB::table('node_access')->delete();
\Illuminate\Support\Facades\DB::table('node_access')->insert([
    [
        'consumer_node_id' => $nodes->get('control-1'),
        'serving_node_id' => $nodes->get('app-dev-1'),
        'created_at' => now(),
        'updated_at' => now(),
    ],
    [
        'consumer_node_id' => $nodes->get('control-1'),
        'serving_node_id' => $nodes->get('app-prod-1'),
        'created_at' => now(),
        'updated_at' => now(),
    ],
]);

\App\Models\App::query()->create([
    'name' => 'docs',
    'node_id' => $nodes->get('app-dev-1'),
    'environment' => 'development',
    'path' => '/srv/docs',
    'document_root' => 'public',
]);

\App\Models\App::query()->create([
    'name' => 'site',
    'node_id' => $nodes->get('app-prod-1'),
    'environment' => 'production',
    'domain' => 'site.example.com',
    'path' => '/srv/site',
    'document_root' => 'public',
]);

echo 'seeded';
PHP;

    $topology->ssh(
        'gateway',
        "cd {$checkout} && orbit tinker --execute=".escapeshellarg($script),
        timeoutSeconds: 120,
    );
}

it('lists registered apps from a control caller through the gateway api', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdevAppprod, withGatewayApi: true);

    try {
        $topology->withCurrentCheckout(roles: ['control', 'gateway']);
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        e2eRestartGatewayApi($topology, 'app-list');
        E2EGatewayApi::waitForGatewayApi($topology->instance('control'), $config->controlUser, $topology->lease()->sshKeyPair(), gatewayIp: $gatewayApiIp);

        appListSeed($topology);

        $result = $topology->ssh(
            'control',
            sprintf(
                'cd %s && orbit app:list --json',
                escapeshellarg($topology->checkout('control')),
            ),
            timeoutSeconds: 120,
        );

        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);
        $apps = $payload['success']['data']['apps'] ?? null;

        expect($apps)->toBeArray()
            ->and(array_column($apps, 'name'))->toBe(['docs', 'site']);
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-canary', 'e2e-feature-operator_gateway_app-dev_app-prod', 'e2e-feature-control-gateway-dev-prod');
