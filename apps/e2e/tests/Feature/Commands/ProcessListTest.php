<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;

function processListSeed(E2ETopologyHarness $topology): void
{
    $script = <<<'PHP'
$nodes = \App\Models\Node::query()
    ->whereIn('name', ['operator-1', 'app-dev-1'])
    ->pluck('id', 'name');

foreach (['operator-1', 'app-dev-1'] as $name) {
    if (! $nodes->has($name)) {
        throw new \RuntimeException("Missing prepared node [{$name}].");
    }
}

\Illuminate\Support\Facades\DB::table('process_events')->delete();
\Illuminate\Support\Facades\DB::table('processes')->delete();
\Illuminate\Support\Facades\DB::table('workspaces')->delete();
\App\Models\App::query()->delete();
\Illuminate\Support\Facades\DB::table('node_access')->delete();
\Illuminate\Support\Facades\DB::table('node_access')->insert([
    'consumer_node_id' => $nodes->get('operator-1'),
    'serving_node_id' => $nodes->get('app-dev-1'),
    'permissions' => json_encode(['process:read'], JSON_THROW_ON_ERROR),
    'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
    'created_at' => now(),
    'updated_at' => now(),
]);

\App\Models\NodeRoleAssignment::query()->updateOrCreate(
    [
        'node_id' => $nodes->get('app-dev-1'),
        'role' => 'app-dev',
    ],
    [
        'status' => 'active',
        'settings' => [],
        'last_error' => null,
        'converged_at' => now(),
    ],
);

$app = \App\Models\App::query()->create([
    'name' => 'docs',
    'node_id' => $nodes->get('app-dev-1'),
    'path' => '/srv/docs',
    'document_root' => 'public',
]);

$app->processes()->create([
    'node_id' => $nodes->get('app-dev-1'),
    'name' => 'queue',
    'command' => 'orbit queue:work',
    'restart_policy' => \App\Enums\ProcessRestartPolicy::Always,
    'crash_notification' => \App\Enums\ProcessCrashNotification::None,
    'runtime' => \App\Enums\Processes\ProcessRuntime::Docker,
    'runtime_config' => [],
    'sort_order' => 20,
]);

$app->processes()->create([
    'node_id' => $nodes->get('app-dev-1'),
    'name' => 'vite',
    'command' => 'npm run dev',
    'restart_policy' => \App\Enums\ProcessRestartPolicy::Never,
    'crash_notification' => \App\Enums\ProcessCrashNotification::None,
    'runtime' => \App\Enums\Processes\ProcessRuntime::Docker,
    'runtime_config' => [],
    'sort_order' => 10,
]);

$workspace = \App\Models\Workspace::query()->create([
    'app_id' => $app->id,
    'name' => 'feature-docs',
    'path' => '/srv/docs/.worktrees/feature-docs',
    'lifecycle_status' => \App\Enums\WorkspaceLifecycleStatus::Expected,
]);

$workspace->processes()->create([
    'node_id' => $nodes->get('app-dev-1'),
    'name' => 'frankenphp-docs-feature-docs',
    'command' => 'frankenphp run',
    'restart_policy' => \App\Enums\ProcessRestartPolicy::Always,
    'crash_notification' => \App\Enums\ProcessCrashNotification::None,
    'runtime' => \App\Enums\Processes\ProcessRuntime::Docker,
    'runtime_config' => ['container_name' => 'orbit-ws-docs-feature-docs'],
    'sort_order' => 1,
]);

echo 'seeded';
PHP;

    processListRunGatewayTinker($topology, $script);
}

function processListRunGatewayTinker(E2ETopologyHarness $topology, string $script): void
{
    e2eRunInRoleRuntime(
        $topology,
        'gateway',
        'cd '.escapeshellarg($topology->checkout('gateway')).' && php apps/gateway/artisan tinker --execute='.escapeshellarg($script),
        timeoutSeconds: 180,
    );
}

it('lists app processes from a operator caller through the gateway api', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev, withGatewayApi: true);

    try {
        $topology->withCurrentCheckout(roles: ['operator', 'gateway']);
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        e2eRestartGatewayApi($topology, 'process-list');
        E2EGatewayApi::waitForGatewayApi($topology->instance('operator'), $config->operatorUser, $topology->lease()->sshKeyPair(), gatewayIp: $gatewayApiIp);

        processListSeed($topology);

        // Human output happy path
        $humanResult = $topology->ssh(
            'operator',
            sprintf(
                'cd %s && orbit process:list --app=docs',
                escapeshellarg($topology->checkout('operator')),
            ),
            timeoutSeconds: 120,
        );

        expect($humanResult->successful())->toBeTrue()
            ->and($humanResult->output())->toContain('docs')
            ->and($humanResult->output())->toContain('queue')
            ->and($humanResult->output())->toContain('vite');

        // JSON output happy path
        $jsonResult = $topology->ssh(
            'operator',
            sprintf(
                'cd %s && orbit process:list --app=docs --json',
                escapeshellarg($topology->checkout('operator')),
            ),
            timeoutSeconds: 120,
        );

        $payload = json_decode(trim($jsonResult->output()), associative: true, flags: JSON_THROW_ON_ERROR);
        $processes = $payload['success']['data']['processes'] ?? null;
        $context = $payload['success']['data']['context'] ?? null;

        expect($processes)->toBeArray()
            ->and($context)->toBe(['node' => 'app-dev-1', 'app' => 'docs', 'workspace' => null])
            ->and(array_column($processes, 'name'))->toBe(['vite', 'queue'])
            ->and($processes[0])->toHaveKeys(['node', 'app', 'workspace', 'name', 'command', 'restart_policy', 'crash_notification', 'runtime', 'tool', 'runtime_unit', 'last_event'])
            ->and($processes[0]['runtime_unit'])->toBe('orbit_docs_main_vite')
            ->and($processes[0]['last_event'])->toBeNull();

        // Workspace filter
        $workspaceResult = $topology->ssh(
            'operator',
            sprintf(
                'cd %s && orbit process:list --app=docs --workspace=feature-docs --json',
                escapeshellarg($topology->checkout('operator')),
            ),
            timeoutSeconds: 120,
        );

        $workspacePayload = json_decode(trim($workspaceResult->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($workspacePayload['success']['data']['context'])->toBe(['node' => 'app-dev-1', 'app' => 'docs', 'workspace' => 'feature-docs'])
            ->and(array_column($workspacePayload['success']['data']['processes'], 'name'))->toBe(['frankenphp-docs-feature-docs', 'vite', 'queue'])
            ->and($workspacePayload['success']['data']['processes'][0]['runtime_unit'])->toBe('orbit-ws-docs-feature-docs')
            ->and($workspacePayload['success']['data']['processes'][1]['runtime_unit'])->toBe('orbit_docs_feature-docs_vite');

        // Empty state: app with no processes — clear processes on gateway then re-list
        processListRunGatewayTinker($topology, "\\App\\Models\\Process::query()->delete(); echo 'cleared';");

        $emptyListResult = $topology->ssh(
            'operator',
            sprintf(
                'cd %s && orbit process:list --app=docs --json',
                escapeshellarg($topology->checkout('operator')),
            ),
            timeoutSeconds: 120,
        );

        $emptyPayload = json_decode(trim($emptyListResult->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($emptyListResult->successful())->toBeTrue()
            ->and($emptyPayload['success']['data']['processes'])->toBe([])
            ->and($emptyPayload['success']['data']['context']['app'])->toBe('docs');
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-canary', 'e2e-feature-operator_gateway_app-dev', 'e2e-feature-operator-gateway-dev');
