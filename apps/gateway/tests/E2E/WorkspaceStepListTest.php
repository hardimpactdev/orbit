<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;

function workspaceStepListSeed(E2ETopologyHarness $topology): void
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
\Illuminate\Support\Facades\DB::table('workspace_steps')->delete();
\Illuminate\Support\Facades\DB::table('workspaces')->delete();
\App\Models\App::query()->delete();
\Illuminate\Support\Facades\DB::table('node_access')->delete();
\Illuminate\Support\Facades\DB::table('node_access')->insert([
    'consumer_node_id' => $nodes->get('control-1'),
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

\App\Models\WorkspaceStep::query()->create([
    'app_id' => $app->id,
    'phase' => \App\Enums\WorkspaceLifecyclePhase::Setup,
    'sort_order' => 2,
    'command' => 'npm install',
    'timeout_seconds' => 300,
]);
\App\Models\WorkspaceStep::query()->create([
    'app_id' => $app->id,
    'phase' => \App\Enums\WorkspaceLifecyclePhase::Setup,
    'sort_order' => 1,
    'command' => 'composer install',
    'timeout_seconds' => 600,
]);
\App\Models\WorkspaceStep::query()->create([
    'app_id' => $app->id,
    'phase' => \App\Enums\WorkspaceLifecyclePhase::Teardown,
    'sort_order' => 1,
    'command' => 'dropdb docs',
    'timeout_seconds' => 60,
]);

echo 'seeded';
PHP;

    $topology->ssh(
        'gateway',
        "cd {$checkout} && orbit tinker --execute=".escapeshellarg($script),
        timeoutSeconds: 120,
    );
}

it('reads workspace setup and teardown step policy from a non-gateway caller through the gateway api', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev, withGatewayApi: true);

    try {
        $topology->withCurrentCheckout(roles: ['control', 'gateway']);
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        e2eRestartGatewayApi($topology, 'workspace-step-list');
        E2EGatewayApi::waitForGatewayApi($topology->instance('control'), $config->controlUser, $topology->lease()->sshKeyPair(), gatewayIp: $gatewayApiIp);

        workspaceStepListSeed($topology);

        $setupResult = $topology->ssh(
            'control',
            sprintf(
                'cd %s && orbit workspace-setup-step:list --app=docs --json',
                escapeshellarg($topology->checkout('control')),
            ),
            timeoutSeconds: 120,
        );
        $teardownResult = $topology->ssh(
            'control',
            sprintf(
                'cd %s && orbit workspace-teardown-step:list --app=docs --json',
                escapeshellarg($topology->checkout('control')),
            ),
            timeoutSeconds: 120,
        );

        $setupPayload = json_decode(trim($setupResult->output()), associative: true, flags: JSON_THROW_ON_ERROR);
        $teardownPayload = json_decode(trim($teardownResult->output()), associative: true, flags: JSON_THROW_ON_ERROR);
        $setupSteps = $setupPayload['success']['data']['steps'] ?? null;
        $teardownSteps = $teardownPayload['success']['data']['steps'] ?? null;

        expect($setupSteps)->toBeArray()
            ->and(array_column($setupSteps, 'command'))->toBe(['composer install', 'npm install'])
            ->and($setupSteps[0]['phase'])->toBe('setup')
            ->and($setupSteps[0]['timeout_seconds'])->toBe(600)
            ->and($teardownSteps)->toBeArray()
            ->and($teardownSteps[0]['command'])->toBe('dropdb docs')
            ->and($teardownSteps[0]['phase'])->toBe('teardown');
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-operator_gateway_app-dev', 'e2e-feature-control-gateway-dev');
