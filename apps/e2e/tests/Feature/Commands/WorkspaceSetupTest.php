<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;

if (! function_exists('workspaceLifecycleSeed')) {
    function workspaceLifecycleSeed(E2ETopologyHarness $topology, string $appPath = '/home/orbit/apps/docs'): void
    {
        $checkout = escapeshellarg($topology->checkout('gateway'));
        $appPathValue = var_export($appPath, true);
        $script = <<<PHP
            \$nodes = \\App\\Models\\Node::query()
                ->whereIn('name', ['operator-1', 'app-dev-1'])
                ->pluck('id', 'name');

            foreach (['operator-1', 'app-dev-1'] as \$name) {
                if (! \$nodes->has(\$name)) {
                    throw new \\RuntimeException("Missing prepared node [{\$name}].");
                }
            }

            \\Illuminate\\Support\\Facades\\DB::table('workspace_run_steps')->delete();
            \\Illuminate\\Support\\Facades\\DB::table('workspace_runs')->delete();
            \\Illuminate\\Support\\Facades\\DB::table('workspace_steps')->delete();
            \\Illuminate\\Support\\Facades\\DB::table('proxy_routes')->delete();
            \\Illuminate\\Support\\Facades\\DB::table('workspaces')->delete();
            \\App\\Models\\App::query()->delete();
            \\Illuminate\\Support\\Facades\\DB::table('node_access')->delete();
            \\Illuminate\\Support\\Facades\\DB::table('node_access')->insert([
                'consumer_node_id' => \$nodes->get('operator-1'),
                'serving_node_id' => \$nodes->get('app-dev-1'),
                'permissions' => json_encode(['workspace:setup'], JSON_THROW_ON_ERROR),
                'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            \\App\\Models\\App::query()->create([
                'name' => 'docs',
                'node_id' => \$nodes->get('app-dev-1'),
                'path' => {$appPathValue},
                'document_root' => 'public',
                'php_version' => '8.5',
            ]);

            echo 'seeded';
            PHP;

        $topology->ssh(
            'gateway',
            "cd {$checkout} && php apps/gateway/artisan tinker --execute=".escapeshellarg($script),
            timeoutSeconds: 120,
        );

        $topology->ssh(
            'dev',
            sprintf(
                <<<'SH'
                    sudo rm -rf %1$s && mkdir -p %1$s/public && cd %1$s
                    git init -b main
                    git config user.email orbit@example.test
                    git config user.name Orbit
                    printf 'ok\n' > public/index.html
                    git add .
                    git commit -m init
                    SH,
                escapeshellarg($appPath),
            ),
            timeoutSeconds: 120,
        );
    }
}

it('sets up an existing workspace path from a non-gateway caller through the gateway api', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev, withGatewayApi: true);
    $workspaceName = 'e2e-ws-setup-'.strtolower(bin2hex(random_bytes(3)));
    $workspacePath = "/home/orbit/apps/docs/.worktrees/{$workspaceName}";

    try {
        $topology->withCurrentCheckout(roles: ['operator', 'gateway', 'dev']);
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        e2eRestartGatewayApi($topology, 'workspace-setup');
        E2EGatewayApi::waitForGatewayApi(
            $topology->instance('operator'),
            $config->operatorUser,
            $topology->lease()->sshKeyPair(),
            gatewayIp: $gatewayApiIp,
        );

        workspaceLifecycleSeed($topology);
        $topology->ssh('dev', 'mkdir -p '.escapeshellarg("{$workspacePath}/public"), timeoutSeconds: 60);

        $result = $topology->ssh(
            'operator',
            sprintf(
                'cd %s && orbit workspace:setup %s --instance=docs --path=%s --json',
                escapeshellarg($topology->checkout('operator')),
                escapeshellarg($workspaceName),
                escapeshellarg($workspacePath),
            ),
            timeoutSeconds: 240,
        );

        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);
        $data = e2eJsonCommandResultData($payload);

        expect($data['workspace'])
            ->toBe($workspaceName)
            ->and($data['app'])
            ->toBe('docs')
            ->and($data['action'])
            ->toBe('adopted')
            ->and($data['setup_steps']['status'])
            ->toBe('skipped');

        $gatewayRecord = $topology->ssh(
            'gateway',
            'cd '.escapeshellarg($topology->checkout('gateway')).' && php apps/gateway/artisan tinker --execute='
                .escapeshellarg("echo json_encode([
                'workspace' => \\App\\Models\\Workspace::query()->where('name', '{$workspaceName}')->value('lifecycle_status'),
                'route_count' => \\App\\Models\\ProxyRoute::query()
                    ->where('workspace_id', \\App\\Models\\Workspace::query()->where('name', '{$workspaceName}')->value('id'))
                    ->count(),
            ], JSON_THROW_ON_ERROR);"),
            timeoutSeconds: 120,
        );
        $state = json_decode(trim($gatewayRecord->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($state)->toMatchArray([
            'workspace' => 'active',
            'route_count' => 1,
        ]);
    } finally {
        $topology->ssh('dev', 'sudo rm -rf '.escapeshellarg($workspacePath), timeoutSeconds: 60);
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-operator_gateway_app-dev', 'e2e-feature-operator-gateway-dev');

function workspaceSetupConfigureLocalExecutor(E2ETopologyHarness $topology): void
{
    $topology->ssh(
        'gateway',
        sprintf(
            <<<'SH'
                cd %1$s
                mkdir -p /home/orbit/.config/orbit apps/gateway/storage/framework/cache/data apps/gateway/storage/framework/sessions apps/gateway/storage/framework/testing apps/gateway/storage/framework/views apps/gateway/storage/logs
                env_file=/home/orbit/.config/orbit/.env
                if [ ! -f "$env_file" ]; then cp apps/gateway/.env.example "$env_file"; fi
                grep -Ev '^(ORBIT_OPERATION_TOKEN_TTL_SECONDS|DB_DATABASE|SESSION_DRIVER)=' "$env_file" > "$env_file.tmp" || true
                mv "$env_file.tmp" "$env_file"
                printf 'DB_DATABASE=/home/orbit/.config/orbit/gateway.sqlite\nSESSION_DRIVER=file\nORBIT_OPERATION_TOKEN_TTL_SECONDS=120\n' >> "$env_file"
                touch /home/orbit/.config/orbit/gateway.sqlite
                ORBIT_CONFIG_ROOT=/home/orbit/.config/orbit php apps/gateway/artisan key:generate --force --no-interaction --ansi
                SH,
            escapeshellarg($topology->checkout('gateway')),
        ),
        timeoutSeconds: 60,
    );
}
