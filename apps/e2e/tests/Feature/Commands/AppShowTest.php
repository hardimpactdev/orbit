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
            ->whereIn('name', ['operator-1', 'app-dev-1'])
            ->pluck('id', 'name');

        foreach (['operator-1', 'app-dev-1'] as $name) {
            if (! $nodes->has($name)) {
                throw new \RuntimeException("Missing prepared node [{$name}].");
            }
        }

        \App\Models\Project::query()->delete();
        \Illuminate\Support\Facades\DB::table('node_access')->delete();
        \Illuminate\Support\Facades\DB::table('node_access')->insert([
            'consumer_node_id' => $nodes->get('operator-1'),
            'serving_node_id' => $nodes->get('app-dev-1'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $project = \App\Models\Project::query()->create([
            'name' => 'docs',
        ]);

        \App\Models\AppInstance::factory()->for($project, 'app')->create([
            'driver_config' => [
                'node' => 'app-dev-1',
                'path' => '/srv/docs',
                'document_root' => 'public',
            ],
        ]);

        echo 'seeded';
        PHP;

    $topology->ssh(
        'gateway',
        "cd {$checkout} && php apps/gateway/artisan tinker --execute=".escapeshellarg($script),
        timeoutSeconds: 120,
    );
}

it('shows a registered app from a operator caller through the gateway api', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev, withGatewayApi: true);

    try {
        $topology->withCurrentCheckout(roles: ['operator', 'gateway']);
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        e2eRestartGatewayApi($topology, 'app-show');
        E2EGatewayApi::waitForGatewayApi(
            $topology->instance('operator'),
            $config->operatorUser,
            $topology->lease()->sshKeyPair(),
            gatewayIp: $gatewayApiIp,
        );

        appShowSeed($topology);

        $result = $topology->ssh(
            'operator',
            sprintf(
                'cd %s && orbit project:show docs --json',
                escapeshellarg($topology->checkout('operator')),
            ),
            timeoutSeconds: 120,
        );

        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);
        $project = $payload['success']['data']['project'] ?? null;
        $instance = $payload['success']['data']['details']['instances'][0] ?? null;

        expect($project)
            ->toBeArray()
            ->and($instance)
            ->toBeArray()
            ->and($project['name'])
            ->toBe('docs')
            ->and($instance['node'])
            ->toBe('app-dev-1');
    } finally {
        $topology->cleanup();
    }
})->group(
    'e2e-feature',
    'e2e-feature-canary',
    'e2e-feature-operator_gateway_app-dev',
    'e2e-feature-operator-gateway-dev',
);
