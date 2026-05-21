<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;

function workspaceRemoveSeed(E2ETopologyHarness $topology, string $workspaceName, string $workspacePath): void
{
    $checkout = escapeshellarg($topology->checkout('gateway'));
    $workspaceNameValue = var_export($workspaceName, true);
    $workspacePathValue = var_export($workspacePath, true);
    $script = <<<PHP
\$nodes = \\App\\Models\\Node::query()
    ->whereIn('name', ['control-1', 'app-dev-1'])
    ->pluck('id', 'name');

foreach (['control-1', 'app-dev-1'] as \$name) {
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
    'consumer_node_id' => \$nodes->get('control-1'),
    'serving_node_id' => \$nodes->get('app-dev-1'),
    'permissions' => json_encode(['workspace:remove'], JSON_THROW_ON_ERROR),
    'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
    'created_at' => now(),
    'updated_at' => now(),
]);

\$app = \\App\\Models\\App::query()->create([
    'name' => 'docs',
    'node_id' => \$nodes->get('app-dev-1'),
    'environment' => 'development',
    'path' => '/home/orbit/apps/docs',
    'document_root' => 'public',
    'php_version' => '8.5',
]);

\$workspace = \\App\\Models\\Workspace::query()->create([
    'app_id' => \$app->id,
    'name' => {$workspaceNameValue},
    'path' => {$workspacePathValue},
    'php_version' => null,
    'lifecycle_status' => \\App\\Enums\\WorkspaceLifecycleStatus::Expected,
]);

\\App\\Models\\ProxyRoute::query()->create([
    'node_id' => \$nodes->get('app-dev-1'),
    'domain' => {$workspaceNameValue}.'.docs.test',
    'app_id' => \$app->id,
    'workspace_id' => \$workspace->id,
    'owner_type' => 'workspace',
    'kind' => 'workspace',
    'source_hash' => str_repeat('b', 64),
]);

echo 'seeded';
PHP;

    $topology->ssh(
        'gateway',
        "cd {$checkout} && php artisan tinker --execute=".escapeshellarg($script),
        timeoutSeconds: 120,
    );
}

it('removes a workspace from a non-gateway caller through the gateway api', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev, withGatewayApi: true);
    $workspaceName = 'e2e-ws-rm-'.strtolower(bin2hex(random_bytes(3)));
    $workspacePath = "/home/orbit/apps/docs/.worktrees/{$workspaceName}";

    try {
        $topology->withCurrentCheckout(roles: ['control', 'gateway']);
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        e2eRestartGatewayApi($topology, 'workspace-remove');
        E2EGatewayApi::waitForGatewayApi($topology->instance('control'), $config->controlUser, $topology->lease()->sshKeyPair(), gatewayIp: $gatewayApiIp);

        workspaceRemoveSeed($topology, $workspaceName, $workspacePath);

        $topology->ssh(
            'dev',
            'mkdir -p '.escapeshellarg($workspacePath).' && touch '.escapeshellarg("{$workspacePath}/keep.txt"),
            timeoutSeconds: 60,
        );

        $result = $topology->ssh(
            'control',
            sprintf(
                'cd %s && php artisan workspace:remove %s --app=docs --keep-files --force --json',
                escapeshellarg($topology->checkout('control')),
                escapeshellarg($workspaceName),
            ),
            timeoutSeconds: 180,
        );

        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($payload['success']['data']['name'])->toBe($workspaceName)
            ->and($payload['success']['data']['app'])->toBe('docs')
            ->and($payload['success']['data']['action'])->toBe('removed')
            ->and($payload['success']['data']['proxy_routes_removed'])->toBe(1)
            ->and($payload['success']['data']['worktree_removed'])->toBeFalse()
            ->and($payload['success']['meta']['kept_files'])->toBeTrue();

        $gatewayRecord = $topology->ssh(
            'gateway',
            'cd '.escapeshellarg($topology->checkout('gateway')).' && php artisan tinker --execute='.escapeshellarg("echo json_encode([
                'workspace' => \\App\\Models\\Workspace::query()->where('name', '{$workspaceName}')->exists(),
                'route_count' => \\App\\Models\\ProxyRoute::query()->where('domain', '{$workspaceName}.docs.test')->count(),
                'app' => \\App\\Models\\App::query()->where('name', 'docs')->exists(),
            ], JSON_THROW_ON_ERROR);"),
            timeoutSeconds: 120,
        );
        $state = json_decode(trim($gatewayRecord->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($state)->toMatchArray([
            'workspace' => false,
            'route_count' => 0,
            'app' => true,
        ]);

        $source = $topology->ssh(
            'dev',
            sprintf('test -f %s', escapeshellarg("{$workspacePath}/keep.txt")),
            timeoutSeconds: 60,
        );

        expect($source->successful())->toBeTrue();
    } finally {
        $topology->ssh('dev', 'sudo rm -rf '.escapeshellarg($workspacePath), timeoutSeconds: 60);
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-operator_gateway_app-dev', 'e2e-feature-control-gateway-dev');
