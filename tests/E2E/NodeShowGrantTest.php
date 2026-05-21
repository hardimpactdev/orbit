<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;

function nodeShowGrantSeed(E2ETopologyHarness $topology): void
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

\Illuminate\Support\Facades\DB::table('node_access')->delete();
\Illuminate\Support\Facades\DB::table('node_access')->insert([
    'consumer_node_id' => $nodes->get('control-1'),
    'serving_node_id' => $nodes->get('app-dev-1'),
    'created_at' => now(),
    'updated_at' => now(),
]);

echo 'seeded';
PHP;

    $topology->ssh(
        'gateway',
        "cd {$checkout} && php artisan tinker --execute=".escapeshellarg($script),
        timeoutSeconds: 120,
    );
}

/**
 * @return array<string, mixed>
 */
function nodeShowGrantJson(E2ETopologyHarness $topology, string $name): array
{
    $result = $topology->ssh(
        'control',
        sprintf(
            'cd %s && php artisan node:show %s --json',
            escapeshellarg($topology->checkout('control')),
            escapeshellarg($name),
        ),
        timeoutSeconds: 120,
    );

    $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($payload)) {
        throw new RuntimeException("node:show {$name} did not return a JSON object.");
    }

    return $payload;
}

function nodeShowGrantHuman(E2ETopologyHarness $topology, string $name): string
{
    $result = $topology->ssh(
        'control',
        sprintf(
            'cd %s && php artisan node:show %s',
            escapeshellarg($topology->checkout('control')),
            escapeshellarg($name),
        ),
        timeoutSeconds: 120,
    );

    return $result->output();
}

/**
 * @param  array<string, mixed>  $payload
 * @return array<string, mixed>
 */
function nodeShowGrantNode(array $payload): array
{
    $node = $payload['success']['data']['node'] ?? null;

    if (! is_array($node)) {
        throw new RuntimeException('node:show response did not contain success.data.node.');
    }

    return $node;
}

it('shows real grant metadata from a control caller through the gateway api', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdevAppprod, withGatewayApi: true);

    try {
        $topology->withCurrentCheckout(roles: ['control', 'gateway']);
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        e2eRestartGatewayApi($topology, 'node-show-grant');
        E2EGatewayApi::waitForGatewayApi($topology->instance('control'), $config->controlUser, $topology->lease()->sshKeyPair(), gatewayIp: $gatewayApiIp);

        nodeShowGrantSeed($topology);

        $appDevNode = nodeShowGrantNode(nodeShowGrantJson($topology, 'app-dev-1'));
        $controlNode = nodeShowGrantNode(nodeShowGrantJson($topology, 'control-1'));
        $appProdNode = nodeShowGrantNode(nodeShowGrantJson($topology, 'app-prod-1'));
        $appProdHuman = nodeShowGrantHuman($topology, 'app-prod-1');

        expect($appDevNode['name'])->toBe('app-dev-1')
            ->and($appDevNode['grants'])->toBe([
                'consuming_nodes' => [
                    ['name' => 'control-1', 'permissions' => ['*']],
                ],
                'serving_nodes' => [],
            ])
            ->and($controlNode['name'])->toBe('control-1')
            ->and($controlNode['grants'])->toBe([
                'consuming_nodes' => [],
                'serving_nodes' => [
                    ['name' => 'app-dev-1', 'permissions' => ['*']],
                ],
            ])
            ->and($appProdNode['name'])->toBe('app-prod-1')
            ->and($appProdNode['grants'])->toBe([
                'consuming_nodes' => [],
                'serving_nodes' => [],
            ])
            ->and($appProdHuman)->toContain('Consuming')
            ->and($appProdHuman)->toContain('Serving')
            ->and($appProdHuman)->toContain('—');
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-operator_gateway_app-dev_app-prod', 'e2e-feature-control-gateway-dev-prod');
