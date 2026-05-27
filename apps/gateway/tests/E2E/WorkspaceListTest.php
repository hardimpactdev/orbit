<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;

function workspaceListSeed(E2ETopologyHarness $topology): void
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

\Illuminate\Support\Facades\DB::table('workspace_run_steps')->delete();
\Illuminate\Support\Facades\DB::table('workspace_runs')->delete();
\Illuminate\Support\Facades\DB::table('workspaces')->delete();
\App\Models\App::query()->delete();
\Illuminate\Support\Facades\DB::table('node_access')->delete();
\Illuminate\Support\Facades\DB::table('node_access')->insert([
    'consumer_node_id' => $nodes->get('operator-1'),
    'serving_node_id' => $nodes->get('app-dev-1'),
    'permissions' => json_encode(['workspace:read'], JSON_THROW_ON_ERROR),
    'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
    'created_at' => now(),
    'updated_at' => now(),
]);

$app = \App\Models\App::query()->create([
    'name' => 'docs',
    'node_id' => $nodes->get('app-dev-1'),
    'environment' => 'development',
    'path' => '/srv/docs',
    'document_root' => 'public',
]);

\App\Models\Workspace::query()->create([
    'app_id' => $app->id,
    'name' => 'feature-alpha',
    'path' => '/srv/docs/.worktrees/feature-alpha',
    'lifecycle_status' => \App\Enums\WorkspaceLifecycleStatus::Expected,
]);

\App\Models\Workspace::query()->create([
    'app_id' => $app->id,
    'name' => 'feature-beta',
    'path' => '/srv/docs/.worktrees/feature-beta',
    'lifecycle_status' => \App\Enums\WorkspaceLifecycleStatus::Active,
]);

echo 'seeded';
PHP;

    $topology->ssh(
        'gateway',
        "cd {$checkout} && orbit tinker --execute=".escapeshellarg($script),
        timeoutSeconds: 120,
    );
}

it('lists workspaces from a non-gateway caller through the gateway api', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev, withGatewayApi: true);

    try {
        $topology->withCurrentCheckout(roles: ['operator', 'gateway']);
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        e2eRestartGatewayApi($topology, 'workspace-list');
        E2EGatewayApi::waitForGatewayApi($topology->instance('operator'), $config->operatorUser, $topology->lease()->sshKeyPair(), gatewayIp: $gatewayApiIp);

        workspaceListSeed($topology);

        // Human output happy path
        $humanResult = $topology->ssh(
            'operator',
            sprintf(
                'cd %s && orbit workspace:list',
                escapeshellarg($topology->checkout('operator')),
            ),
            timeoutSeconds: 120,
        );

        expect($humanResult->successful())->toBeTrue()
            ->and($humanResult->output())->toContain('feature-alpha')
            ->and($humanResult->output())->toContain('feature-beta');

        // JSON output happy path
        $jsonResult = $topology->ssh(
            'operator',
            sprintf(
                'cd %s && orbit workspace:list --json',
                escapeshellarg($topology->checkout('operator')),
            ),
            timeoutSeconds: 120,
        );

        $payload = json_decode(trim($jsonResult->output()), associative: true, flags: JSON_THROW_ON_ERROR);
        $workspaces = $payload['success']['data']['workspaces'] ?? null;

        expect($workspaces)->toBeArray()
            ->and(array_column($workspaces, 'name'))->toContain('feature-alpha')
            ->and(array_column($workspaces, 'name'))->toContain('feature-beta')
            ->and($workspaces[0])->toHaveKeys(['name', 'app', 'node', 'url', 'lifecycle_status']);

        // Filter by app
        $filteredResult = $topology->ssh(
            'operator',
            sprintf(
                'cd %s && orbit workspace:list --app=docs --json',
                escapeshellarg($topology->checkout('operator')),
            ),
            timeoutSeconds: 120,
        );

        $filteredPayload = json_decode(trim($filteredResult->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($filteredPayload['success']['data']['workspaces'])->toHaveCount(2)
            ->and($filteredPayload['success']['data']['workspaces'][0]['app'])->toBe('docs');

        // Filter by node
        $nodeResult = $topology->ssh(
            'operator',
            sprintf(
                'cd %s && orbit workspace:list --node=app-dev-1 --json',
                escapeshellarg($topology->checkout('operator')),
            ),
            timeoutSeconds: 120,
        );

        $nodePayload = json_decode(trim($nodeResult->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($nodePayload['success']['data']['workspaces'])->toHaveCount(2)
            ->and($nodePayload['success']['data']['workspaces'][0]['node'])->toBe('app-dev-1');

        // Empty state: seed an app with no workspaces, then list with that app filter
        $topology->ssh(
            'gateway',
            'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg(implode("\n", [
                '$nodes = \App\Models\Node::query()->whereIn(\'name\', [\'app-dev-1\'])->pluck(\'id\', \'name\');',
                '\App\Models\App::query()->create([\'name\' => \'empty-app\', \'node_id\' => $nodes->get(\'app-dev-1\'), \'environment\' => \'development\', \'path\' => \'/srv/empty\', \'document_root\' => \'public\']);',
                'echo \'seeded-empty\';',
            ])),
            timeoutSeconds: 120,
        );

        $emptyResult = $topology->ssh(
            'operator',
            sprintf(
                'cd %s && orbit workspace:list --app=empty-app --json',
                escapeshellarg($topology->checkout('operator')),
            ),
            timeoutSeconds: 120,
        );

        $emptyPayload = json_decode(trim($emptyResult->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($emptyResult->successful())->toBeTrue()
            ->and($emptyPayload['success']['data']['workspaces'])->toBe([]);
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-canary', 'e2e-feature-operator_gateway_app-dev', 'e2e-feature-operator-gateway-dev');
