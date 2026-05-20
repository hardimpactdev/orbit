<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;

function workspaceHistorySeed(E2ETopologyHarness $topology): void
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

\Illuminate\Support\Facades\DB::table('workspace_run_steps')->delete();
\Illuminate\Support\Facades\DB::table('workspace_runs')->delete();
\Illuminate\Support\Facades\DB::table('workspaces')->delete();
\App\Models\App::query()->delete();
\Illuminate\Support\Facades\DB::table('node_access')->delete();
\Illuminate\Support\Facades\DB::table('node_access')->insert([
    'consumer_node_id' => $nodes->get('control-1'),
    'serving_node_id' => $nodes->get('app-dev-1'),
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

$workspace = \App\Models\Workspace::query()->create([
    'app_id' => $app->id,
    'name' => 'feature-docs',
    'path' => '/srv/docs/.worktrees/feature-docs',
    'lifecycle_status' => \App\Enums\WorkspaceLifecycleStatus::Expected,
]);

\App\Models\WorkspaceRun::query()->create([
    'workspace_id' => $workspace->id,
    'phase' => \App\Enums\WorkspaceLifecyclePhase::Setup,
    'status' => 'completed',
    'started_at' => '2026-05-02 10:00:00',
    'completed_at' => '2026-05-02 10:01:00',
]);

echo 'seeded';
PHP;

    $topology->ssh(
        'gateway',
        "cd {$checkout} && php artisan tinker --execute=".escapeshellarg($script),
        timeoutSeconds: 120,
    );
}

it('reads workspace history from a control caller through the gateway api', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev, withGatewayApi: true);

    try {
        $topology->withCurrentCheckout(roles: ['control', 'gateway']);
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        e2eRestartGatewayApi($topology, 'workspace-history');
        E2EGatewayApi::waitForGatewayApi($topology->instance('control'), $config->controlUser, $topology->lease()->sshKeyPair(), gatewayIp: $gatewayApiIp);

        workspaceHistorySeed($topology);

        $result = $topology->ssh(
            'control',
            sprintf(
                'cd %s && php artisan workspace:history feature-docs --app=docs --json',
                escapeshellarg($topology->checkout('control')),
            ),
            timeoutSeconds: 120,
        );

        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);
        $runs = $payload['success']['data']['runs'] ?? null;

        expect($runs)->toBeArray()
            ->and($runs[0]['workspace'])->toBe('feature-docs')
            ->and($runs[0]['app'])->toBe('docs')
            ->and($runs[0]['status'])->toBe('completed');
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-operator-gateway-appdev', 'e2e-feature-control-gateway-dev');
