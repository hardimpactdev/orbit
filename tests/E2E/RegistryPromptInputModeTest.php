<?php

declare(strict_types=1);

use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;

function registryPromptE2ESeed(E2ETopologyHarness $topology): void
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

\Illuminate\Support\Facades\DB::table('schedule_runs')->delete();
\Illuminate\Support\Facades\DB::table('schedule_locks')->delete();
\App\Models\Schedule::query()->delete();
\Illuminate\Support\Facades\DB::table('workspace_run_steps')->delete();
\Illuminate\Support\Facades\DB::table('workspace_runs')->delete();
\Illuminate\Support\Facades\DB::table('workspace_steps')->delete();
\Illuminate\Support\Facades\DB::table('workspaces')->delete();
\Illuminate\Support\Facades\DB::table('proxy_routes')->delete();
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
    'path' => '/home/orbit/apps/docs',
    'document_root' => 'public',
    'php_version' => '8.5',
    'adopted' => true,
]);

\App\Models\Workspace::query()->create([
    'app_id' => $app->id,
    'name' => 'feature-docs',
    'path' => '/home/orbit/apps/docs/.worktrees/feature-docs',
    'php_version' => null,
    'lifecycle_status' => \App\Enums\WorkspaceLifecycleStatus::Expected,
]);

\App\Models\Schedule::query()->create([
    'schedule_key' => 'app:docs:daily-docs',
    'name' => 'daily-docs',
    'scope' => 'app',
    'app_id' => $app->id,
    'node_id' => null,
    'target_name' => 'docs',
    'interval' => 'daily',
    'timezone' => 'UTC',
    'execution_type' => 'command',
    'execution_value' => 'php artisan docs:build',
    'enabled' => true,
    'status' => 'expected',
]);

echo 'seeded';
PHP;

    $topology->ssh(
        'gateway',
        "cd {$checkout} && php artisan tinker --execute=".escapeshellarg($script),
        timeoutSeconds: 120,
    );
}

function registryPromptE2ECapture(E2ETopologyHarness $topology, string $artisanCommand, string $label): string
{
    $checkout = escapeshellarg($topology->checkout('gateway'));
    $transcript = '/tmp/orbit-registry-prompt-'.$label.'-'.strtolower(bin2hex(random_bytes(3))).'.log';
    $command = sprintf('cd %s && ORBIT_IS_GATEWAY=1 %s', $checkout, $artisanCommand);
    $transcriptArgument = escapeshellarg($transcript);

    $result = $topology->ssh(
        'gateway',
        sprintf(
            'if ! command -v script >/dev/null 2>&1 || ! script --version >/dev/null 2>&1 || ! command -v timeout >/dev/null 2>&1; then echo "__ORBIT_PTY_CAPTURE_UNAVAILABLE__"; exit 0; fi; rm -f %1$s; printf "\n" | timeout 20s script -q -e -c %2$s %1$s >/dev/null; code=$?; cat %1$s; rm -f %1$s; exit $code',
            $transcriptArgument,
            escapeshellarg($command),
        ),
        timeoutSeconds: 60,
    );

    if (str_contains($result->output(), '__ORBIT_PTY_CAPTURE_UNAVAILABLE__')) {
        test()->markTestSkipped('The remote E2E node cannot capture pseudo-TTY prompt output.');
    }

    return $result->output();
}

it('renders finite registry prompts as data tables in a real terminal session', function (): void {
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev);

    try {
        $topology->withCurrentCheckout(roles: ['gateway']);

        registryPromptE2ESeed($topology);

        $appPrompt = registryPromptE2ECapture($topology, 'php artisan app:show', 'app');
        $nodePrompt = registryPromptE2ECapture($topology, 'php artisan node:show', 'node');
        $workspacePrompt = registryPromptE2ECapture($topology, 'php artisan workspace:show --app=docs', 'workspace');
        $schedulePrompt = registryPromptE2ECapture($topology, 'php artisan schedule:show --app=docs', 'schedule');

        expect($appPrompt)
            ->toContain('Select an app')
            ->toContain('App')
            ->toContain('Host')
            ->toContain('docs')
            ->toContain('app-dev-1')
            ->not->toContain('App name or hostname');

        expect($nodePrompt)
            ->toContain('Select a node')
            ->toContain('Node')
            ->toContain('Role')
            ->toContain('app-dev-1')
            ->not->toContain('Node name or hostname');

        expect($workspacePrompt)
            ->toContain('Select a workspace')
            ->toContain('Workspace')
            ->toContain('feature-docs')
            ->toContain('docs')
            ->not->toContain('Workspace name');

        expect($schedulePrompt)
            ->toContain('Select a schedule')
            ->toContain('Schedule')
            ->toContain('daily-docs')
            ->toContain('docs')
            ->not->toContain('Schedule name');
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-canary', 'e2e-feature-operator-gateway-appdev', 'e2e-feature-control-gateway-dev');
