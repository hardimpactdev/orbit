<?php

declare(strict_types=1);

use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;

function registryPromptE2ESeed(E2ETopologyHarness $topology): void
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
            'consumer_node_id' => $nodes->get('operator-1'),
            'serving_node_id' => $nodes->get('app-dev-1'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $app = \App\Models\App::query()->create([
            'name' => 'docs',
            'php_version' => '8.5',
        ]);

        $instance = \App\Models\Instance::factory()->for($app, 'app')->create([
            'name' => 'development',
            'php_version' => $app->php_version,
            'adopted' => true,
            'driver_config' => new \App\Data\Apps\OrbitInstanceDriverConfigData(
                node_id: $nodes->get('app-dev-1'),
                node: 'app-dev-1',
                path: '/home/orbit/apps/docs',
                document_root: 'public',
            ),
        ]);

        $api = \App\Models\App::query()->create([
            'name' => 'api',
            'php_version' => '8.5',
        ]);

        \App\Models\Instance::factory()->for($api, 'app')->create([
            'name' => 'development',
            'php_version' => $api->php_version,
            'adopted' => true,
            'driver_config' => new \App\Data\Apps\OrbitInstanceDriverConfigData(
                node_id: $nodes->get('app-dev-1'),
                node: 'app-dev-1',
                path: '/home/orbit/apps/api',
                document_root: 'public',
            ),
        ]);

        \App\Models\Workspace::query()->create([
            'app_id' => $app->id,
            'instance_id' => $instance->id,
            'name' => 'feature-docs',
            'path' => '/home/orbit/apps/docs/.worktrees/feature-docs',
            'php_version' => null,
            'lifecycle_status' => \App\Enums\WorkspaceLifecycleStatus::Expected,
        ]);

        \App\Models\Workspace::query()->create([
            'app_id' => $app->id,
            'instance_id' => $instance->id,
            'name' => 'bugfix-docs',
            'path' => '/home/orbit/apps/docs/.worktrees/bugfix-docs',
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
            'execution_value' => 'orbit docs:build',
            'enabled' => true,
            'status' => 'expected',
        ]);

        \App\Models\Schedule::query()->create([
            'schedule_key' => 'app:docs:weekly-docs',
            'name' => 'weekly-docs',
            'scope' => 'app',
            'app_id' => $app->id,
            'node_id' => null,
            'target_name' => 'docs',
            'interval' => 'weekly',
            'timezone' => 'UTC',
            'execution_type' => 'command',
            'execution_value' => 'orbit docs:weekly',
            'enabled' => true,
            'status' => 'expected',
        ]);

        echo 'seeded';
        PHP;

    $topology->ssh(
        'gateway',
        "cd {$checkout} && php apps/gateway/artisan tinker --execute=".escapeshellarg($script),
        timeoutSeconds: 120,
    );
}

function registryPromptE2ECapture(
    E2ETopologyHarness $topology,
    string $commandArguments,
    string $label,
    string $input = "\n",
): string {
    $checkout = $topology->checkout('gateway');
    $transcript = '/tmp/orbit-registry-prompt-'.$label.'-'.strtolower(bin2hex(random_bytes(3))).'.log';
    $command = registryPromptE2ECommand($topology, $checkout, $commandArguments);
    $transcriptArgument = escapeshellarg($transcript);
    $inputCommand = 'printf %s '.escapeshellarg($input);

    $result = $topology->ssh(
        'gateway',
        sprintf(
            'if ! command -v script >/dev/null 2>&1 || ! script --version >/dev/null 2>&1 || ! command -v timeout >/dev/null 2>&1; then echo "__ORBIT_PTY_CAPTURE_UNAVAILABLE__"; exit 0; fi; rm -f %1$s; (sleep 1; %3$s) | timeout 30s script -q -e -c %2$s %1$s >/dev/null; code=$?; cat %1$s; rm -f %1$s; exit $code',
            $transcriptArgument,
            escapeshellarg($command),
            $inputCommand,
        ),
        timeoutSeconds: 60,
        allowFailure: true,
    );

    if (str_contains($result->output(), '__ORBIT_PTY_CAPTURE_UNAVAILABLE__')) {
        test()->markTestSkipped('The remote E2E node cannot capture pseudo-TTY prompt output.');
    }

    return $result->output();
}

function registryPromptE2ECommand(E2ETopologyHarness $topology, string $checkout, string $commandArguments): string
{
    return sprintf('cd /tmp && ORBIT_HOST_CWD=/tmp %s %s', escapeshellarg("{$checkout}/bin/orbit"), $commandArguments);
}

it('renders finite registry prompts as data tables in a real terminal session', function (): void {
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev);

    try {
        $topology->withCurrentCheckout(roles: ['gateway']);

        e2eRestartGatewayApi($topology, 'registry-prompt-input-mode');
        registryPromptE2ESeed($topology);

        $appPrompt = registryPromptE2ECapture($topology, 'app:show', 'app');
        $nodePrompt = registryPromptE2ECapture($topology, 'node:show', 'node');
        $workspacePrompt = registryPromptE2ECapture($topology, 'workspace:show --instance=docs', 'workspace');
        $schedulePrompt = registryPromptE2ECapture($topology, 'schedule:show --instance=docs', 'schedule');

        expect($appPrompt)
            ->toContain('App: api')
            ->toContain('api')
            ->toContain('app-dev-1')
            ->not->toContain('App name or hostname');

        expect($nodePrompt)
            ->toContain('Node')
            ->toContain('Role')
            ->toContain('app-dev-1')
            ->not->toContain('Node name or hostname');

        expect($workspacePrompt)
            ->toContain('Workspace')
            ->toContain('feature-docs')
            ->toContain('docs')
            ->not->toContain('Workspace name');

        expect($schedulePrompt)
            ->toContain('Schedule')
            ->toContain('daily-docs')
            ->toContain('docs')
            ->not->toContain('Schedule name');
    } finally {
        $topology->cleanup();
    }
})->group(
    'e2e-feature',
    'e2e-feature-canary',
    'e2e-feature-operator_gateway_app-dev',
    'e2e-feature-operator-gateway-dev',
);
