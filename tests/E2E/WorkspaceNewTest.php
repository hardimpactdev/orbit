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
    'created_at' => now(),
    'updated_at' => now(),
]);

\\App\\Models\\App::query()->create([
    'name' => 'docs',
    'node_id' => \$nodes->get('app-dev-1'),
    'environment' => 'development',
    'path' => {$appPathValue},
    'document_root' => 'public',
    'php_version' => '8.5',
]);

echo 'seeded';
PHP;

        $topology->ssh(
            'gateway',
            "cd {$checkout} && php artisan tinker --execute=".escapeshellarg($script),
            timeoutSeconds: 120,
        );

        $topology->ssh(
            'dev',
            sprintf(
                <<<'SH'
rm -rf %1$s && mkdir -p %1$s/public && cd %1$s
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

it('creates and sets up a workspace from a control caller through the gateway api', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::ControlGatewayDev, withGatewayApi: true);
    $workspaceName = 'e2e-ws-new-'.strtolower(bin2hex(random_bytes(3)));
    $workspacePath = "/home/orbit/apps/docs/{$workspaceName}";

    try {
        $topology->withCurrentCheckout(roles: ['control', 'gateway']);
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        E2EGatewayApi::restart(
            $topology->instance('gateway'),
            'workspace-new',
            $topology->checkout('gateway'),
            gatewayIp: $gatewayApiIp,
        );
        E2EGatewayApi::waitForGatewayApi($topology->instance('control'), $config->controlUser, $topology->lease()->sshKeyPair(), gatewayIp: $gatewayApiIp);

        workspaceLifecycleSeed($topology);

        $result = $topology->ssh(
            'control',
            sprintf(
                'cd %s && php artisan workspace:new %s --app=docs --json',
                escapeshellarg($topology->checkout('control')),
                escapeshellarg($workspaceName),
            ),
            timeoutSeconds: 240,
        );

        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($payload['success']['data']['result'])->toBe(['action' => 'created'])
            ->and($payload['success']['data']['workspace']['name'])->toBe($workspaceName)
            ->and($payload['success']['data']['workspace']['app'])->toBe('docs')
            ->and($payload['success']['data']['workspace']['path'])->toBe($workspacePath)
            ->and($payload['success']['data']['workspace']['lifecycle_status'])->toBe('active')
            ->and($payload['success']['meta']['base'])->toBe('main');

        $topology->ssh('dev', 'test -e '.escapeshellarg("{$workspacePath}/.git"), timeoutSeconds: 60);

        $gatewayRecord = $topology->ssh(
            'gateway',
            'cd '.escapeshellarg($topology->checkout('gateway')).' && php artisan tinker --execute='.escapeshellarg("echo json_encode([
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
        $topology->ssh('dev', 'rm -rf '.escapeshellarg($workspacePath), timeoutSeconds: 60);
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-control-gateway-dev');
