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
            ->whereIn('name', ['operator-1', 'app-dev-1'])
            ->pluck('id', 'name');

        foreach (['operator-1', 'app-dev-1'] as $name) {
            if (! $nodes->has($name)) {
                throw new \RuntimeException("Missing prepared node [{$name}].");
            }
        }

        \Illuminate\Support\Facades\DB::table('node_access')->updateOrInsert([
            'consumer_node_id' => $nodes->get('operator-1'),
            'serving_node_id' => $nodes->get('app-dev-1'),
        ], [
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        echo 'granted';
        PHP;

    $topology->ssh(
        'gateway',
        "cd {$checkout} && php apps/gateway/artisan tinker --execute=".escapeshellarg($script),
        timeoutSeconds: 120,
    );
}

it('updates an app root from a operator caller through the gateway api', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev, withGatewayApi: true);
    $name = 'e2e-root-'.strtolower(bin2hex(random_bytes(3)));
    $path = "/home/orbit/apps/{$name}";

    try {
        $topology->withCurrentCheckout(roles: ['operator', 'gateway']);
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        e2eRestartGatewayApi($topology, 'app-root');
        E2EGatewayApi::waitForGatewayApi(
            $topology->instance('operator'),
            $config->operatorUser,
            $topology->lease()->sshKeyPair(),
            gatewayIp: $gatewayApiIp,
        );

        appRootGrantAccess($topology);

        $topology->ssh(
            'dev',
            sprintf('mkdir -p %s %s', escapeshellarg("{$path}/public"), escapeshellarg("{$path}/web")),
            timeoutSeconds: 60,
        );

        $register = $topology->ssh(
            'operator',
            sprintf(
                'cd %s && orbit instance:register %s --node=app-dev-1 --path=%s --json',
                escapeshellarg($topology->checkout('operator')),
                escapeshellarg($name.'.development'),
                escapeshellarg($path),
            ),
            timeoutSeconds: 180,
        );

        $registrationPayload = json_decode(trim($register->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($registrationPayload['success']['data']['result']['action'])->toBe('adopted');

        $result = $topology->ssh(
            'operator',
            sprintf(
                'cd %s && orbit instance:root %s web --json',
                escapeshellarg($topology->checkout('operator')),
                escapeshellarg($name),
            ),
            timeoutSeconds: 180,
        );

        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);
        $app = $payload['success']['data']['app'] ?? null;
        $instance = $payload['success']['data']['instance'] ?? null;

        expect($app)
            ->toBeArray()
            ->and($instance)
            ->toBeArray()
            ->and($payload['success']['data']['result']['changed'])
            ->toBeTrue()
            ->and($payload['success']['meta']['node'])
            ->toBe('app-dev-1')
            ->and($payload['success']['meta']['artifacts_reenacted'])
            ->toBeTrue()
            ->and($app['name'])
            ->toBe($name)
            ->and($instance['node'])
            ->toBe('app-dev-1')
            ->and($instance['path'])
            ->toBe($path)
            ->and($instance['root'])
            ->toBe('web');

        $gatewayRecord = $topology->ssh(
            'gateway',
            'cd '.escapeshellarg($topology->checkout('gateway')).' && php apps/gateway/artisan tinker --execute='
                .escapeshellarg("echo json_encode([
                'root' => \\App\\Models\\Instance::query()
                    ->whereHas('project', fn (\$query) => \$query->where('name', '{$name}'))
                    ->where('name', 'development')
                    ->firstOrFail()
                    ->driver_config
                    ->document_root,
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
})->group('e2e-feature', 'e2e-feature-operator_gateway_app-dev', 'e2e-feature-operator-gateway-dev');
