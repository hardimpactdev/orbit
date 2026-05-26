<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;

pest()->group('e2e-feature', 'e2e-feature-operator_gateway_app-dev', 'e2e-feature-control-gateway-dev');

function appPruneGrantAccess(E2ETopologyHarness $topology): void
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

it('dry-run --json returns planned stale workspace set without mutation', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev, withGatewayApi: true);
    $name = 'e2e-prune-'.strtolower(bin2hex(random_bytes(3)));
    $path = "/home/orbit/apps/{$name}";

    try {
        $topology->withCurrentCheckout(roles: ['control', 'gateway']);
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        e2eRestartGatewayApi($topology, 'app-prune');
        E2EGatewayApi::waitForGatewayApi(
            $topology->instance('control'),
            $config->controlUser,
            $topology->lease()->sshKeyPair(),
            gatewayIp: $gatewayApiIp,
        );

        appPruneGrantAccess($topology);

        // Create app with opencode adapter so prune can query it.
        $create = $topology->ssh(
            'control',
            sprintf(
                'cd %s && orbit app:new %s --node=app-dev-1 --json',
                escapeshellarg($topology->checkout('control')),
                escapeshellarg($name),
            ),
            timeoutSeconds: 180,
        );

        $createPayload = json_decode(trim($create->output()), associative: true, flags: JSON_THROW_ON_ERROR);
        expect($createPayload['success']['data']['result']['action'])->toBe('created');

        // Set opencode adapter so the app has a configured agent IDE.
        $topology->ssh(
            'control',
            sprintf(
                'cd %s && orbit app:agent-ide %s opencode --json',
                escapeshellarg($topology->checkout('control')),
                escapeshellarg($name),
            ),
            timeoutSeconds: 120,
        );

        $result = $topology->ssh(
            'control',
            sprintf(
                'cd %s && orbit app:prune %s --dry-run --json',
                escapeshellarg($topology->checkout('control')),
                escapeshellarg($name),
            ),
            timeoutSeconds: 120,
        );

        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($result->successful())->toBeTrue()
            ->and($payload)->toHaveKey('success')
            ->and($payload['success']['data'])->toHaveKey('app')
            ->and($payload['success']['data'])->toHaveKey('stale_workspaces')
            ->and($payload['success']['data']['dry_run'])->toBeTrue();
    } finally {
        $topology->ssh('dev', 'sudo rm -rf '.escapeshellarg($path), timeoutSeconds: 60);
        $topology->cleanup();
    }
});

it('--force --json prunes stale workspaces and reports pruned list', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev, withGatewayApi: true);
    $name = 'e2e-prune-'.strtolower(bin2hex(random_bytes(3)));
    $path = "/home/orbit/apps/{$name}";

    try {
        $topology->withCurrentCheckout(roles: ['control', 'gateway']);
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        e2eRestartGatewayApi($topology, 'app-prune-force');
        E2EGatewayApi::waitForGatewayApi(
            $topology->instance('control'),
            $config->controlUser,
            $topology->lease()->sshKeyPair(),
            gatewayIp: $gatewayApiIp,
        );

        appPruneGrantAccess($topology);

        $create = $topology->ssh(
            'control',
            sprintf(
                'cd %s && orbit app:new %s --node=app-dev-1 --json',
                escapeshellarg($topology->checkout('control')),
                escapeshellarg($name),
            ),
            timeoutSeconds: 180,
        );

        $createPayload = json_decode(trim($create->output()), associative: true, flags: JSON_THROW_ON_ERROR);
        expect($createPayload['success']['data']['result']['action'])->toBe('created');

        $topology->ssh(
            'control',
            sprintf(
                'cd %s && orbit app:agent-ide %s opencode --json',
                escapeshellarg($topology->checkout('control')),
                escapeshellarg($name),
            ),
            timeoutSeconds: 120,
        );

        $result = $topology->ssh(
            'control',
            sprintf(
                'cd %s && orbit app:prune %s --force --json',
                escapeshellarg($topology->checkout('control')),
                escapeshellarg($name),
            ),
            timeoutSeconds: 180,
        );

        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($result->successful())->toBeTrue()
            ->and($payload)->toHaveKey('success')
            ->and($payload['success']['data'])->toHaveKey('app')
            ->and($payload['success']['data'])->toHaveKey('stale_workspaces')
            ->and($payload['success']['data']['dry_run'])->toBeFalse();

        foreach ($payload['success']['data']['stale_workspaces'] as $workspace) {
            expect($workspace)->toHaveKey('name')
                ->and($workspace)->toHaveKey('status')
                ->and($workspace)->toHaveKey('database')
                ->and($workspace['database'])->toBe('skipped');
        }
    } finally {
        $topology->ssh('dev', 'sudo rm -rf '.escapeshellarg($path), timeoutSeconds: 60);
        $topology->cleanup();
    }
});
