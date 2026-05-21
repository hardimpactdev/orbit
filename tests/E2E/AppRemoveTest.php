<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;

function appRemoveGrantAccess(E2ETopologyHarness $topology): void
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

\Illuminate\Support\Facades\DB::table('node_access')->updateOrInsert([
    'consumer_node_id' => $nodes->get('control-1'),
    'serving_node_id' => $nodes->get('app-dev-1'),
], [
    'created_at' => now(),
    'updated_at' => now(),
]);

echo 'granted';
PHP;

    $topology->ssh(
        'gateway',
        "cd {$checkout} && php artisan tinker --execute=".escapeshellarg($script),
        timeoutSeconds: 120,
    );
}

it('removes an app from a control caller through the gateway api', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdevAppprod, withGatewayApi: true);
    $name = 'e2e-rm-'.strtolower(bin2hex(random_bytes(3)));
    $path = "/home/orbit/apps/{$name}";

    try {
        $topology->withCurrentCheckout(roles: ['control', 'gateway']);
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        e2eRestartGatewayApi($topology, 'app-remove');
        E2EGatewayApi::waitForGatewayApi($topology->instance('control'), $config->controlUser, $topology->lease()->sshKeyPair(), gatewayIp: $gatewayApiIp);

        appRemoveGrantAccess($topology);

        $create = $topology->ssh(
            'control',
            sprintf(
                'cd %s && php artisan app:new %s --node=app-dev-1 --json',
                escapeshellarg($topology->checkout('control')),
                escapeshellarg($name),
            ),
            timeoutSeconds: 180,
        );

        $createPayload = json_decode(trim($create->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($createPayload['success']['data']['result']['action'])->toBe('created');

        $result = $topology->ssh(
            'control',
            sprintf(
                'cd %s && php artisan app:remove %s --force --json',
                escapeshellarg($topology->checkout('control')),
                escapeshellarg($name),
            ),
            timeoutSeconds: 180,
        );

        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);
        $app = $payload['success']['data']['app'] ?? null;

        expect($app)->toBeArray()
            ->and($payload['success']['data']['result']['action'])->toBe('removed')
            ->and($payload['success']['data']['cleanup']['proxy_routes_removed'])->toBe(0)
            ->and($payload['success']['data']['cleanup']['processes_removed'])->toBe(0)
            ->and($app['name'])->toBe($name)
            ->and($app['node'])->toBe('app-dev-1')
            ->and($app['path'])->toBe($path);

        $gatewayRecord = $topology->ssh(
            'gateway',
            'cd '.escapeshellarg($topology->checkout('gateway')).' && php artisan tinker --execute='.escapeshellarg("echo json_encode([
                'app' => \\App\\Models\\App::query()->where('name', '{$name}')->exists(),
                'routes' => \\App\\Models\\ProxyRoute::query()->where('domain', '{$name}.test')->count(),
            ], JSON_THROW_ON_ERROR);"),
            timeoutSeconds: 120,
        );
        $state = json_decode(trim($gatewayRecord->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($state)->toMatchArray([
            'app' => false,
            'routes' => 0,
        ]);

        $source = $topology->ssh(
            'dev',
            sprintf('test ! -e %s', escapeshellarg($path)),
            timeoutSeconds: 60,
        );

        expect($source->successful())->toBeTrue();
    } finally {
        $topology->ssh('dev', 'sudo rm -rf '.escapeshellarg($path), timeoutSeconds: 60);
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-operator_gateway_app-dev_app-prod', 'e2e-feature-control-gateway-dev-prod');
