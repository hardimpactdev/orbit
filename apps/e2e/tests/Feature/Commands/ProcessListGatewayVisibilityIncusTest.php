<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;

function processListGatewayVisibilitySeed(E2ETopologyHarness $topology): void
{
    $script = <<<'PHP'
$nodes = \App\Models\Node::query()
    ->whereIn('name', ['operator-1', 'gateway'])
    ->pluck('id', 'name');

foreach (['operator-1', 'gateway'] as $name) {
    if (! $nodes->has($name)) {
        throw new \RuntimeException("Missing prepared node [{$name}].");
    }
}

\Illuminate\Support\Facades\DB::table('process_events')->delete();
\Illuminate\Support\Facades\DB::table('processes')->delete();
\Illuminate\Support\Facades\DB::table('node_access')->delete();
\Illuminate\Support\Facades\DB::table('node_access')->insert([
    'consumer_node_id' => $nodes->get('operator-1'),
    'serving_node_id' => $nodes->get('gateway'),
    'permissions' => json_encode(['process:read'], JSON_THROW_ON_ERROR),
    'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
    'created_at' => now(),
    'updated_at' => now(),
]);

$gateway = \App\Models\Node::query()->where('name', 'gateway')->firstOrFail();
$gateway->processes()->create([
    'node_id' => $gateway->id,
    'name' => 'prometheus',
    'command' => 'prometheus --config.file=/etc/prometheus/prometheus.yml',
    'restart_policy' => \App\Enums\ProcessRestartPolicy::Always,
    'crash_notification' => \App\Enums\ProcessCrashNotification::None,
    'runtime' => \App\Enums\Processes\ProcessRuntime::DockerSwarm,
    'runtime_config' => ['service_name' => 'orbit-prometheus'],
    'sort_order' => 1,
]);

echo 'seeded';
PHP;

    e2eRunInRoleRuntime(
        $topology,
        'gateway',
        'cd '.escapeshellarg($topology->checkout('gateway')).' && php apps/gateway/artisan tinker --execute='.escapeshellarg($script),
        timeoutSeconds: 180,
    );
}

it('lists gateway-owned process intent from an operator caller on Incus', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::OperatorGateway, withGatewayApi: true);

    try {
        $topology->withCurrentCheckout(roles: ['operator', 'gateway']);
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        e2eRestartGatewayApi($topology, 'process-list-gateway-visibility');
        E2EGatewayApi::waitForGatewayApi(
            $topology->instance('operator'),
            $config->operatorUser,
            $topology->lease()->sshKeyPair(),
            gatewayIp: $gatewayApiIp,
        );

        processListGatewayVisibilitySeed($topology);

        $result = $topology->ssh(
            'operator',
            sprintf(
                'cd %s && orbit process:list --node=gateway --json',
                escapeshellarg($topology->checkout('operator')),
            ),
            timeoutSeconds: 120,
        );

        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($result->successful())->toBeTrue($result->output().$result->errorOutput())
            ->and($payload['success']['data']['context'])->toBe(['node' => 'gateway', 'app' => null, 'workspace' => null])
            ->and($payload['success']['data']['processes'][0])->toMatchArray([
                'node' => 'gateway',
                'app' => null,
                'workspace' => null,
                'name' => 'prometheus',
                'runtime' => 'docker-swarm',
                'runtime_unit' => 'orbit-prometheus',
            ]);
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-provider-incus', 'e2e-feature-operator_gateway', 'e2e-feature-operator-gateway');
