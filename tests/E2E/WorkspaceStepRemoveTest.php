<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;

function workspaceStepRemoveSeed(E2ETopologyHarness $topology): void
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
    'permissions' => json_encode(['workspace:write'], JSON_THROW_ON_ERROR),
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
    'sort_order' => 1,
    'command' => 'composer install',
    'timeout_seconds' => 600,
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

it('removes workspace setup and teardown steps from a non-gateway caller through the gateway api', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev, withGatewayApi: true);

    try {
        $topology->withCurrentCheckout(roles: ['control', 'gateway']);
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        e2eRestartGatewayApi($topology, 'workspace-step-remove');
        E2EGatewayApi::waitForGatewayApi($topology->instance('control'), $config->controlUser, $topology->lease()->sshKeyPair(), gatewayIp: $gatewayApiIp);

        workspaceStepRemoveSeed($topology);

        $checkout = escapeshellarg($topology->checkout('control'));
        $setupListResult = $topology->ssh(
            'control',
            "cd {$checkout} && orbit workspace-setup-step:list --app=docs --json",
            timeoutSeconds: 120,
        );
        $setupListPayload = json_decode(trim($setupListResult->output()), associative: true, flags: JSON_THROW_ON_ERROR);
        $setupSteps = $setupListPayload['success']['data']['steps'];
        $setupRemoveId = $setupSteps[0]['id'];

        $removeSetupResult = $topology->ssh(
            'control',
            "cd {$checkout} && orbit workspace-setup-step:remove --app=docs --step={$setupRemoveId} --force --json",
            timeoutSeconds: 120,
        );
        $removeSetupPayload = json_decode(trim($removeSetupResult->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        $teardownListBeforeResult = $topology->ssh(
            'control',
            "cd {$checkout} && orbit workspace-teardown-step:list --app=docs --json",
            timeoutSeconds: 120,
        );
        $teardownListBeforePayload = json_decode(trim($teardownListBeforeResult->output()), associative: true, flags: JSON_THROW_ON_ERROR);
        $teardownRemoveId = $teardownListBeforePayload['success']['data']['steps'][0]['id'];

        $removeTeardownResult = $topology->ssh(
            'control',
            "cd {$checkout} && orbit workspace-teardown-step:remove --app=docs --step={$teardownRemoveId} --force --json",
            timeoutSeconds: 120,
        );
        $removeTeardownPayload = json_decode(trim($removeTeardownResult->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        $teardownListResult = $topology->ssh(
            'control',
            "cd {$checkout} && orbit workspace-teardown-step:list --app=docs --json",
            timeoutSeconds: 120,
        );
        $teardownListPayload = json_decode(trim($teardownListResult->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($removeSetupPayload['success']['data']['result'])->toBe(['action' => 'removed'])
            ->and($removeSetupPayload['success']['data']['step']['command'])->toBe('composer install')
            ->and($removeSetupPayload['success']['meta']['remaining_step_count'])->toBe(1)
            ->and($removeTeardownPayload['success']['data']['step']['command'])->toBe('dropdb docs')
            ->and($removeTeardownPayload['success']['meta']['remaining_step_count'])->toBe(0)
            ->and($teardownListPayload['success']['data']['steps'])->toBe([]);
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-operator_gateway_app-dev', 'e2e-feature-control-gateway-dev');
