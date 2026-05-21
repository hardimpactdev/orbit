<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;

function workspaceStepAddSeed(E2ETopologyHarness $topology): void
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

\App\Models\App::query()->create([
    'name' => 'docs',
    'node_id' => $nodes->get('app-dev-1'),
    'environment' => 'development',
    'path' => '/srv/docs',
    'document_root' => 'public',
]);

echo 'seeded';
PHP;

    $topology->ssh(
        'gateway',
        "cd {$checkout} && php artisan tinker --execute=".escapeshellarg($script),
        timeoutSeconds: 120,
    );
}

it('adds workspace setup and teardown steps from a non-gateway caller through the gateway api', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev, withGatewayApi: true);

    try {
        $topology->withCurrentCheckout(roles: ['control', 'gateway']);
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        e2eRestartGatewayApi($topology, 'workspace-step-add');
        E2EGatewayApi::waitForGatewayApi($topology->instance('control'), $config->controlUser, $topology->lease()->sshKeyPair(), gatewayIp: $gatewayApiIp);

        workspaceStepAddSeed($topology);

        $checkout = escapeshellarg($topology->checkout('control'));

        $composerResult = $topology->ssh(
            'control',
            "cd {$checkout} && php artisan workspace-setup-step:add --app=docs --command='composer install' --timeout=600 --json",
            timeoutSeconds: 120,
        );
        $composerPayload = json_decode(trim($composerResult->output()), associative: true, flags: JSON_THROW_ON_ERROR);
        $composerStep = $composerPayload['success']['data']['step'];

        $topology->ssh(
            'control',
            "cd {$checkout} && php artisan workspace-setup-step:add --app=docs --command='npm install' --timeout=300 --after={$composerStep['id']} --json",
            timeoutSeconds: 120,
        );

        $teardownResult = $topology->ssh(
            'control',
            "cd {$checkout} && php artisan workspace-teardown-step:add --app=docs --command='dropdb docs' --timeout=60 --json",
            timeoutSeconds: 120,
        );
        $teardownPayload = json_decode(trim($teardownResult->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        $setupListResult = $topology->ssh(
            'control',
            "cd {$checkout} && php artisan workspace-setup-step:list --app=docs --json",
            timeoutSeconds: 120,
        );
        $setupListPayload = json_decode(trim($setupListResult->output()), associative: true, flags: JSON_THROW_ON_ERROR);
        $setupSteps = $setupListPayload['success']['data']['steps'] ?? null;

        expect($composerPayload['success']['data']['result'])->toBe(['action' => 'added'])
            ->and($composerStep['app'])->toBe('docs')
            ->and($composerStep['command'])->toBe('composer install')
            ->and($composerStep['phase'])->toBe('setup')
            ->and($composerStep['timeout_seconds'])->toBe(600)
            ->and($teardownPayload['success']['data']['step']['phase'])->toBe('teardown')
            ->and($teardownPayload['success']['data']['step']['command'])->toBe('dropdb docs')
            ->and($setupSteps)->toBeArray()
            ->and(array_column($setupSteps, 'command'))->toBe(['composer install', 'npm install'])
            ->and(array_column($setupSteps, 'order'))->toBe([1, 2]);
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-operator-gateway-appdev', 'e2e-feature-control-gateway-dev');
