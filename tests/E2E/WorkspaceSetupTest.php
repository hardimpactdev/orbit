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

it('sets up an existing workspace path from a control caller through the gateway api', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev, withGatewayApi: true);
    $workspaceName = 'e2e-ws-setup-'.strtolower(bin2hex(random_bytes(3)));
    $workspacePath = "/home/orbit/apps/docs/.worktrees/{$workspaceName}";

    try {
        $topology->withCurrentCheckout(roles: ['control', 'gateway']);
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        E2EGatewayApi::restart(
            $topology->instance('gateway'),
            'workspace-setup',
            $topology->checkout('gateway'),
            gatewayIp: $gatewayApiIp,
        );
        E2EGatewayApi::waitForGatewayApi($topology->instance('control'), $config->controlUser, $topology->lease()->sshKeyPair(), gatewayIp: $gatewayApiIp);

        workspaceLifecycleSeed($topology);
        $topology->ssh('dev', 'mkdir -p '.escapeshellarg("{$workspacePath}/public"), timeoutSeconds: 60);

        $result = $topology->ssh(
            'control',
            sprintf(
                'cd %s && php artisan workspace:setup %s --app=docs --path=%s --json',
                escapeshellarg($topology->checkout('control')),
                escapeshellarg($workspaceName),
                escapeshellarg($workspacePath),
            ),
            timeoutSeconds: 240,
        );

        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($payload['success']['data']['workspace'])->toBe($workspaceName)
            ->and($payload['success']['data']['app'])->toBe('docs')
            ->and($payload['success']['data']['action'])->toBe('adopted')
            ->and($payload['success']['data']['setup_steps']['status'])->toBe('skipped');

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
})->group('e2e-feature', 'e2e-feature-operator-gateway-appdev', 'e2e-feature-control-gateway-dev');

it('resolves an opencode worktree by adapter ownership when a stale registered path points at another app', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev, withGatewayApi: true);
    $workspaceName = 'e2e-opencode-'.strtolower(bin2hex(random_bytes(3)));
    $workspacePath = "/home/orbit/.local/share/opencode/worktree/docs/{$workspaceName}";

    try {
        $topology->withCurrentCheckout(roles: ['control', 'gateway', 'dev']);
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        E2EGatewayApi::restart(
            $topology->instance('gateway'),
            'workspace-setup-opencode',
            $topology->checkout('gateway'),
            gatewayIp: $gatewayApiIp,
        );
        E2EGatewayApi::waitForGatewayApi($topology->instance('control'), $config->controlUser, $topology->lease()->sshKeyPair(), gatewayIp: $gatewayApiIp);

        workspaceSetupOpencodeSeed($topology, $workspaceName, $workspacePath);
        workspaceSetupInstallFakeOpenCode($topology);

        $install = $topology->ssh(
            'gateway',
            sprintf(
                'cd %s && ORBIT_IS_GATEWAY=1 php artisan tool:install opencode-server --node=app-dev-1 --status=running --json',
                escapeshellarg($topology->checkout('gateway')),
            ),
            timeoutSeconds: 180,
        );
        $installPayload = json_decode(trim($install->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($installPayload['success']['data']['tool'])->toMatchArray([
            'name' => 'opencode-server',
            'node' => 'app-dev-1',
            'state' => 'running',
        ]);

        workspaceSetupWriteOpenCodeDatabase($topology, $workspaceName, $workspacePath);

        $setup = $topology->ssh(
            'gateway',
            'cd '.escapeshellarg($topology->checkout('gateway')).' && php artisan tinker --execute='.escapeshellarg(workspaceSetupOpencodeResolverScript($workspacePath)),
            timeoutSeconds: 240,
        );
        $payload = json_decode(trim($setup->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($payload['app'])->toBe('docs')
            ->and($payload['workspace'])->toBe($workspaceName)
            ->and($payload['action'])->toBe('adopted');

        $gatewayRecord = $topology->ssh(
            'gateway',
            'cd '.escapeshellarg($topology->checkout('gateway')).' && php artisan tinker --execute='.escapeshellarg("echo json_encode([
                'docs_workspace_app' => \\App\\Models\\Workspace::query()
                    ->where('name', '{$workspaceName}')
                    ->first()?->app?->name,
                'stale_workspace_app' => \\App\\Models\\Workspace::query()
                    ->where('name', 'stale-opencode')
                    ->first()?->app?->name,
            ], JSON_THROW_ON_ERROR);"),
            timeoutSeconds: 120,
        );
        $state = json_decode(trim($gatewayRecord->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($state)->toMatchArray([
            'docs_workspace_app' => 'docs',
            'stale_workspace_app' => 'api',
        ]);
    } finally {
        $topology->ssh('dev', 'rm -rf '.escapeshellarg($workspacePath), timeoutSeconds: 60);
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-operator-gateway-appdev', 'e2e-feature-control-gateway-dev');

function workspaceSetupOpencodeSeed(E2ETopologyHarness $topology, string $workspaceName, string $workspacePath): void
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
\\Illuminate\\Support\\Facades\\DB::table('node_tools')->delete();
\\Illuminate\\Support\\Facades\\DB::table('node_access')->delete();
\\Illuminate\\Support\\Facades\\DB::table('node_access')->insert([
    [
        'consumer_node_id' => \$nodes->get('control-1'),
        'serving_node_id' => \$nodes->get('app-dev-1'),
        'created_at' => now(),
        'updated_at' => now(),
    ],
    [
        'consumer_node_id' => \$nodes->get('app-dev-1'),
        'serving_node_id' => \$nodes->get('app-dev-1'),
        'created_at' => now(),
        'updated_at' => now(),
    ],
]);

\$appNode = \\App\\Models\\Node::query()->findOrFail(\$nodes->get('app-dev-1'));
\$appNode->update(['agent_ide_config' => ['adapter' => 'opencode']]);

\$docs = \\App\\Models\\App::query()->create([
    'name' => 'docs',
    'node_id' => \$nodes->get('app-dev-1'),
    'environment' => 'development',
    'path' => '/home/orbit/apps/docs',
    'document_root' => 'public',
    'php_version' => '8.5',
    'agent_ide_config' => ['adapter' => 'opencode'],
]);

\$api = \\App\\Models\\App::query()->create([
    'name' => 'api',
    'node_id' => \$nodes->get('app-dev-1'),
    'environment' => 'development',
    'path' => '/home/orbit/apps/api',
    'document_root' => 'public',
    'php_version' => '8.5',
    'agent_ide_config' => ['adapter' => 'opencode'],
]);

\\App\\Models\\Workspace::query()->create([
    'app_id' => \$api->id,
    'name' => 'stale-opencode',
    'path' => {$workspacePathValue},
    'agent_ide' => 'opencode',
    'agent_ide_workspace_id' => 'stale-opencode-id',
    'lifecycle_status' => \\App\\Enums\\WorkspaceLifecycleStatus::Active,
]);

echo {$workspaceNameValue};
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
rm -rf /home/orbit/apps/docs /home/orbit/apps/api %1$s
mkdir -p /home/orbit/apps/docs/public /home/orbit/apps/api/public %1$s/public
printf 'docs\n' > /home/orbit/apps/docs/public/index.html
printf 'api\n' > /home/orbit/apps/api/public/index.html
printf 'workspace\n' > %1$s/public/index.html
SH,
            escapeshellarg($workspacePath),
        ),
        timeoutSeconds: 120,
    );
}

function workspaceSetupInstallFakeOpenCode(E2ETopologyHarness $topology): void
{
    $curl = <<<'BASH'
#!/usr/bin/env bash
cat <<'INSTALL'
#!/usr/bin/env bash
set -e
mkdir -p "${HOME}/.opencode/bin"
cat > "${HOME}/.opencode/bin/opencode" <<'OPENCODE'
#!/usr/bin/env bash
echo "opencode fake"
OPENCODE
chmod 0755 "${HOME}/.opencode/bin/opencode"
INSTALL
BASH;

    $systemctl = <<<'BASH'
#!/usr/bin/env bash
exit 0
BASH;

    $topology->ssh(
        'dev',
        sprintf(
            'printf %%s %s | sudo tee /usr/local/bin/curl >/dev/null && printf %%s %s | sudo tee /usr/local/bin/systemctl >/dev/null && printf %%s %s | sudo tee /usr/local/bin/loginctl >/dev/null && sudo chmod 0755 /usr/local/bin/curl /usr/local/bin/systemctl /usr/local/bin/loginctl',
            escapeshellarg($curl),
            escapeshellarg($systemctl),
            escapeshellarg($systemctl),
        ),
        timeoutSeconds: 120,
    );
}

function workspaceSetupOpencodeResolverScript(string $workspacePath): string
{
    $workspacePathValue = var_export($workspacePath, true);

    return <<<PHP
\$callerNode = \\App\\Models\\Node::query()->where('name', 'app-dev-1')->firstOrFail();
[\$workspace, \$app, \$node, \$isAdoption] = app(\\App\\Services\\Workspaces\\WorkspaceSetupTargetResolver::class)
    ->resolve(null, null, null, {$workspacePathValue}, \$callerNode);
\$result = app(\\App\\Actions\\Workspaces\\SetupWorkspace::class)
    ->handle(\$app, \$workspace, \$node, \$isAdoption);

echo json_encode(\$result, JSON_THROW_ON_ERROR);
PHP;
}

function workspaceSetupWriteOpenCodeDatabase(E2ETopologyHarness $topology, string $workspaceName, string $workspacePath): void
{
    $script = <<<'PY'
import pathlib, sqlite3
db = pathlib.Path.home() / ".local/share/opencode/opencode.db"
db.parent.mkdir(parents=True, exist_ok=True)
conn = sqlite3.connect(db)
try:
    conn.execute("create table if not exists project (id text primary key, worktree text not null)")
    conn.execute("create table if not exists workspace (id text primary key, type text not null, name text not null, branch text, directory text, extra text, project_id text not null)")
    conn.execute("delete from workspace where id in ('wrk_docs', 'wrk_stale')")
    conn.execute("delete from project where id in ('proj_docs', 'proj_api')")
    conn.execute("insert into project (id, worktree) values ('proj_docs', '/home/orbit/apps/docs')")
    conn.execute("insert into project (id, worktree) values ('proj_api', '/home/orbit/apps/api')")
    conn.execute(
        "insert into workspace (id, type, name, branch, directory, extra, project_id) values (?, 'worktree', ?, ?, ?, null, 'proj_docs')",
        ('wrk_docs', WORKSPACE_NAME, WORKSPACE_NAME, WORKSPACE_PATH),
    )
    conn.commit()
finally:
    conn.close()
PY;

    $script = str_replace(
        ['WORKSPACE_NAME', 'WORKSPACE_PATH'],
        [var_export($workspaceName, true), var_export($workspacePath, true)],
        $script,
    );

    $topology->ssh(
        'dev',
        'python3 - <<'.escapeshellarg('PY').PHP_EOL.$script.PHP_EOL.'PY',
        timeoutSeconds: 120,
    );
}
