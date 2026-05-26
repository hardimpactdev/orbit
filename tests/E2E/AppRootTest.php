<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;

function appRootGrantAccess(E2ETopologyHarness $topology): void
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
        "cd {$checkout} && orbit tinker --execute=".escapeshellarg($script),
        timeoutSeconds: 120,
    );
}

it('updates an app root from a control caller through the gateway api', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev, withGatewayApi: true);
    $name = 'e2e-root-'.strtolower(bin2hex(random_bytes(3)));
    $path = "/home/orbit/apps/{$name}";

    try {
        $topology->withCurrentCheckout(roles: ['control', 'gateway']);
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        e2eRestartGatewayApi($topology, 'app-root');
        E2EGatewayApi::waitForGatewayApi($topology->instance('control'), $config->controlUser, $topology->lease()->sshKeyPair(), gatewayIp: $gatewayApiIp);

        appRootGrantAccess($topology);

        $topology->ssh(
            'dev',
            sprintf('mkdir -p %s %s', escapeshellarg("{$path}/public"), escapeshellarg("{$path}/web")),
            timeoutSeconds: 60,
        );

        $register = $topology->ssh(
            'control',
            sprintf(
                'cd %s && orbit app:register %s --node=app-dev-1 --path=%s --json',
                escapeshellarg($topology->checkout('control')),
                escapeshellarg($name),
                escapeshellarg($path),
            ),
            timeoutSeconds: 180,
        );

        $registrationPayload = json_decode(trim($register->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($registrationPayload['success']['data']['result']['action'])->toBe('adopted');

        $result = $topology->ssh(
            'control',
            sprintf(
                'cd %s && orbit app:root %s web --json',
                escapeshellarg($topology->checkout('control')),
                escapeshellarg($name),
            ),
            timeoutSeconds: 180,
        );

        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);
        $app = $payload['success']['data']['app'] ?? null;

        expect($app)->toBeArray()
            ->and($payload['success']['data']['result']['changed'])->toBeTrue()
            ->and($payload['success']['meta']['node'])->toBe('app-dev-1')
            ->and($payload['success']['meta']['artifacts_reenacted'])->toBeTrue()
            ->and($app['name'])->toBe($name)
            ->and($app['node'])->toBe('app-dev-1')
            ->and($app['path'])->toBe($path)
            ->and($app['root'])->toBe('web');

        $gatewayRecord = $topology->ssh(
            'gateway',
            'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg("echo json_encode([
                'root' => \\App\\Models\\App::query()->where('name', '{$name}')->value('document_root'),
            ], JSON_THROW_ON_ERROR);"),
            timeoutSeconds: 120,
        );
        $state = json_decode(trim($gatewayRecord->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($state)->toMatchArray([
            'root' => 'web',
        ]);
    } finally {
        $topology->ssh('dev', 'sudo rm -rf '.escapeshellarg($path), timeoutSeconds: 60);
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-operator_gateway_app-dev', 'e2e-feature-control-gateway-dev');
