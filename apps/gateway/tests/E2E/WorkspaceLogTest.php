<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;

/**
 * @return array{completed: int, failed: int}
 */
function workspaceLogSeed(E2ETopologyHarness $topology): array
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
\Illuminate\Support\Facades\DB::table('workspace_steps')->delete();
\Illuminate\Support\Facades\DB::table('workspaces')->delete();
\App\Models\App::query()->delete();
\Illuminate\Support\Facades\DB::table('node_access')->delete();
\Illuminate\Support\Facades\DB::table('node_access')->insert([
    'consumer_node_id' => $nodes->get('operator-1'),
    'serving_node_id' => $nodes->get('app-dev-1'),
    'permissions' => json_encode(['workspace:log'], JSON_THROW_ON_ERROR),
    'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
    'created_at' => now(),
    'updated_at' => now(),
]);

$app = \App\Models\App::query()->create([
    'name' => 'docs',
    'node_id' => $nodes->get('app-dev-1'),
    'path' => '/srv/docs',
    'document_root' => 'public',
]);

$workspace = \App\Models\Workspace::query()->create([
    'app_id' => $app->id,
    'name' => 'feature-docs',
    'path' => '/srv/docs/.worktrees/feature-docs',
    'lifecycle_status' => \App\Enums\WorkspaceLifecycleStatus::Expected,
]);

$step = \App\Models\WorkspaceStep::query()->create([
    'app_id' => $app->id,
    'phase' => \App\Enums\WorkspaceLifecyclePhase::Setup,
    'sort_order' => 1,
    'command' => 'Install dependencies',
    'timeout_seconds' => 600,
]);

$completedRun = \App\Models\WorkspaceRun::query()->create([
    'workspace_id' => $workspace->id,
    'phase' => \App\Enums\WorkspaceLifecyclePhase::Setup,
    'status' => 'completed',
    'started_at' => '2026-05-02 09:00:00',
    'completed_at' => '2026-05-02 09:00:05',
]);

\App\Models\WorkspaceRunStep::query()->create([
    'workspace_run_id' => $completedRun->id,
    'workspace_step_id' => $step->id,
    'command' => 'composer install',
    'exit_code' => 0,
    'output' => 'Dependencies installed',
    'started_at' => '2026-05-02 09:00:01',
    'completed_at' => '2026-05-02 09:00:05',
]);

$failedRun = \App\Models\WorkspaceRun::query()->create([
    'workspace_id' => $workspace->id,
    'phase' => \App\Enums\WorkspaceLifecyclePhase::Setup,
    'status' => 'failed',
    'started_at' => '2026-05-02 10:00:00',
    'completed_at' => '2026-05-02 10:00:12',
]);

\App\Models\WorkspaceRunStep::query()->create([
    'workspace_run_id' => $failedRun->id,
    'workspace_step_id' => $step->id,
    'command' => 'composer install',
    'exit_code' => 1,
    'output' => "Loading repositories\nDependency resolution failed. [TRUNCATED]",
    'started_at' => '2026-05-02 10:00:03',
    'completed_at' => '2026-05-02 10:00:11',
]);

echo json_encode([
    'completed' => $completedRun->id,
    'failed' => $failedRun->id,
], JSON_THROW_ON_ERROR);
PHP;

    $result = $topology->ssh(
        'gateway',
        "cd {$checkout} && php apps/gateway/artisan tinker --execute=".escapeshellarg($script),
        timeoutSeconds: 120,
    );

    $runIds = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);

    return [
        'completed' => (int) $runIds['completed'],
        'failed' => (int) $runIds['failed'],
    ];
}

it('reads workspace run logs from a non-gateway caller through the gateway api', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev, withGatewayApi: true);

    try {
        $topology->withCurrentCheckout(roles: ['operator', 'gateway']);
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        e2eRestartGatewayApi($topology, 'workspace-log');
        E2EGatewayApi::waitForGatewayApi($topology->instance('operator'), $config->operatorUser, $topology->lease()->sshKeyPair(), gatewayIp: $gatewayApiIp);

        $runIds = workspaceLogSeed($topology);

        $completedResult = $topology->ssh(
            'operator',
            sprintf(
                'cd %s && orbit workspace:log %d --json',
                escapeshellarg($topology->checkout('operator')),
                $runIds['completed'],
            ),
            timeoutSeconds: 120,
        );
        $failedResult = $topology->ssh(
            'operator',
            sprintf(
                'cd %s && orbit workspace:log %d --json',
                escapeshellarg($topology->checkout('operator')),
                $runIds['failed'],
            ),
            timeoutSeconds: 120,
        );

        $completedPayload = json_decode(trim($completedResult->output()), associative: true, flags: JSON_THROW_ON_ERROR);
        $failedPayload = json_decode(trim($failedResult->output()), associative: true, flags: JSON_THROW_ON_ERROR);
        $completedRun = $completedPayload['success']['data']['run'] ?? null;
        $failedRun = $failedPayload['success']['data']['run'] ?? null;

        expect($completedRun)->toBeArray()
            ->and($completedRun['id'])->toBe($runIds['completed'])
            ->and($completedRun['status'])->toBe('completed')
            ->and($completedRun['steps'][0]['stdout'])->toContain('Dependencies installed')
            ->and($completedRun['steps'][0]['duration_ms'])->toBe(4000)
            ->and($failedRun)->toBeArray()
            ->and($failedRun['id'])->toBe($runIds['failed'])
            ->and($failedRun['workspace'])->toBe('feature-docs')
            ->and($failedRun['app'])->toBe('docs')
            ->and($failedRun['status'])->toBe('failed')
            ->and($failedRun['steps'][0]['stdout'])->toContain('Dependency resolution failed')
            ->and($failedRun['steps'][0]['stdout_truncated'])->toBeTrue()
            ->and($failedRun['steps'][0]['duration_ms'])->toBe(8000);
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-operator_gateway_app-dev', 'e2e-feature-operator-gateway-dev');
