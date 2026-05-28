<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;

function profileSeed(E2ETopologyHarness $topology, string $gatewayApiIp): void
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

\App\Models\App::query()->delete();
\Illuminate\Support\Facades\DB::table('node_access')->delete();
\Illuminate\Support\Facades\DB::table('node_access')->insert([
    'consumer_node_id' => $nodes->get('operator-1'),
    'serving_node_id' => $nodes->get('app-dev-1'),
    'created_at' => now(),
    'updated_at' => now(),
]);

\App\Models\App::query()->create([
    'name' => 'docs',
    'node_id' => $nodes->get('app-dev-1'),
    'domain' => '__GATEWAY_API_IP__',
    'path' => '/srv/docs',
    'document_root' => 'public',
]);

echo 'seeded';
PHP;

    $script = str_replace('__GATEWAY_API_IP__', $gatewayApiIp, $script);

    $topology->ssh(
        'gateway',
        "cd {$checkout} && orbit tinker --execute=".escapeshellarg($script),
        timeoutSeconds: 120,
    );
}

it('profiles an observable registered app target from a operator caller', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev, withGatewayApi: true);

    try {
        $topology->withCurrentCheckout(roles: ['operator', 'gateway']);
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        e2eRestartGatewayApi($topology, 'profile');
        E2EGatewayApi::waitForGatewayApi($topology->instance('operator'), $config->operatorUser, $topology->lease()->sshKeyPair(), gatewayIp: $gatewayApiIp);

        profileSeed($topology, $gatewayApiIp);

        $result = $topology->ssh(
            'operator',
            sprintf(
                'cd %s && orbit profile docs --uri=/api/me --json',
                escapeshellarg($topology->checkout('operator')),
            ),
            timeoutSeconds: 120,
        );

        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);
        $data = $payload['success']['data'] ?? null;

        expect($data)->toBeArray()
            ->and($data['target']['app'])->toBe('docs')
            ->and($data['target']['node'])->toBe('app-dev-1')
            ->and($data['request']['completed'])->toBeTrue()
            ->and($data['request']['status'])->toBe(200)
            ->and($data['request']['url'])->toBe("https://{$gatewayApiIp}/api/me")
            ->and($data['timings']['total_ms'])->toBeNumeric();
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-canary', 'e2e-feature-operator_gateway_app-dev', 'e2e-feature-operator-gateway-dev');
