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
        E2EGatewayApi::waitForGatewayApi($topology->instance('operator'), $config->operatorUser, $topology->lease()->sshKeyPair(), gatewayIp: $gatewayApiIp);

        workspaceLifecycleSeed($topology);
        $topology->ssh('dev', 'mkdir -p '.escapeshellarg("{$workspacePath}/public"), timeoutSeconds: 60);

        $result = $topology->ssh(
            'operator',
            sprintf(
                'cd %s && orbit workspace:setup %s --app=docs --path=%s --json',
                escapeshellarg($topology->checkout('operator')),
                escapeshellarg($workspaceName),
                escapeshellarg($workspacePath),
            ),
            timeoutSeconds: 240,
        );

        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);
        $data = e2eJsonCommandResultData($payload);

        expect($data['workspace'])->toBe($workspaceName)
            ->and($data['app'])->toBe('docs')
            ->and($data['action'])->toBe('adopted')
            ->and($data['setup_steps']['status'])->toBe('skipped');

        $gatewayRecord = $topology->ssh(
            'gateway',
            'cd '.escapeshellarg($topology->checkout('gateway')).' && php apps/gateway/artisan tinker --execute='.escapeshellarg("echo json_encode([
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

it('resolves an opencode worktree by adapter ownership when a stale registered path points at another app', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev, withGatewayApi: true);
    $workspaceName = 'e2e-opencode-'.strtolower(bin2hex(random_bytes(3)));
    $workspacePath = "/home/orbit/.local/share/opencode/worktree/docs/{$workspaceName}";

    try {
        $topology->withCurrentCheckout(roles: ['operator', 'gateway', 'dev']);
        workspaceSetupConfigureLocalExecutor($topology);
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        e2eRestartGatewayApi($topology, 'workspace-setup-opencode');
        E2EGatewayApi::waitForGatewayApi($topology->instance('operator'), $config->operatorUser, $topology->lease()->sshKeyPair(), gatewayIp: $gatewayApiIp);

        workspaceSetupOpencodeSeed($topology, $workspaceName, $workspacePath);
        workspaceSetupInstallFakeOpenCode($topology);

        $install = $topology->ssh(
            'gateway',
            sprintf(
                'cd %s && orbit tool:install opencode-server --node=app-dev-1 --status=running --json',
                escapeshellarg($topology->checkout('gateway')),
            ),
            timeoutSeconds: 180,
        );
        $installData = e2eJsonCommandData(e2eJsonCommandPayload($install->output()));

        expect($installData['tool'])->toMatchArray([
            'name' => 'opencode-server',
            'node' => 'app-dev-1',
            'state' => 'running',
        ]);

        workspaceSetupWriteOpenCodeDatabase($topology, $workspaceName, $workspacePath);

        $setup = $topology->ssh(
            'gateway',
            'cd '.escapeshellarg($topology->checkout('gateway')).' && php apps/gateway/artisan tinker --execute='.escapeshellarg(workspaceSetupOpencodeResolverScript($workspacePath)),
            timeoutSeconds: 240,
        );
        $payload = json_decode(trim($setup->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($payload['app'])->toBe('docs')
            ->and($payload['workspace'])->toBe($workspaceName)
            ->and($payload['action'])->toBe('adopted');

        $gatewayRecord = $topology->ssh(
            'gateway',
            'cd '.escapeshellarg($topology->checkout('gateway')).' && php apps/gateway/artisan tinker --execute='.escapeshellarg("echo json_encode([
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
        $topology->ssh('dev', 'sudo rm -rf '.escapeshellarg($workspacePath), timeoutSeconds: 60);
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-operator_gateway_app-dev', 'e2e-feature-operator-gateway-dev');

function workspaceSetupOpencodeSeed(E2ETopologyHarness $topology, string $workspaceName, string $workspacePath): void
{
    $checkout = escapeshellarg($topology->checkout('gateway'));
    $workspaceNameValue = var_export($workspaceName, true);
    $workspacePathValue = var_export($workspacePath, true);
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
\\Illuminate\\Support\\Facades\\DB::table('node_tools')->delete();
\\Illuminate\\Support\\Facades\\DB::table('node_access')->delete();
\\Illuminate\\Support\\Facades\\DB::table('node_access')->insert([
    [
        'consumer_node_id' => \$nodes->get('operator-1'),
        'serving_node_id' => \$nodes->get('app-dev-1'),
        'permissions' => json_encode(['workspace:setup'], JSON_THROW_ON_ERROR),
        'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ],
    [
        'consumer_node_id' => \$nodes->get('app-dev-1'),
        'serving_node_id' => \$nodes->get('app-dev-1'),
        'permissions' => json_encode(['workspace:setup'], JSON_THROW_ON_ERROR),
        'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ],
]);

\$appNode = \\App\\Models\\Node::query()->findOrFail(\$nodes->get('app-dev-1'));
\$appNode->update(['agent_ide_config' => ['adapter' => 'opencode']]);

\$docs = \\App\\Models\\App::query()->create([
    'name' => 'docs',
    'node_id' => \$nodes->get('app-dev-1'),
    'path' => '/home/orbit/apps/docs',
    'document_root' => 'public',
    'php_version' => '8.5',
    'agent_ide_config' => ['adapter' => 'opencode'],
]);

\$api = \\App\\Models\\App::query()->create([
    'name' => 'api',
    'node_id' => \$nodes->get('app-dev-1'),
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
        "cd {$checkout} && php apps/gateway/artisan tinker --execute=".escapeshellarg($script),
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
    $databasePath = '/home/orbit/.local/share/opencode/opencode.db';
    $script = <<<'PHP'
<?php

declare(strict_types=1);

$workspaceName = __WORKSPACE_NAME__;
$workspacePath = __WORKSPACE_PATH__;
$databasePath = __DATABASE_PATH__;
$databaseDirectory = dirname($databasePath);

if (! is_dir($databaseDirectory) && ! mkdir($databaseDirectory, 0775, true) && ! is_dir($databaseDirectory)) {
    throw new RuntimeException("Could not create OpenCode database directory [{$databaseDirectory}].");
}

$pdo = new PDO('sqlite:'.$databasePath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec('create table if not exists project (id text primary key, worktree text not null)');
$pdo->exec('create table if not exists workspace (id text primary key, type text not null, name text not null, branch text, directory text, extra text, project_id text not null)');
$pdo->exec("delete from workspace where id in ('wrk_docs', 'wrk_stale')");
$pdo->exec("delete from project where id in ('proj_docs', 'proj_api')");

$insertProject = $pdo->prepare('insert into project (id, worktree) values (:id, :worktree)');
$insertProject->execute(['id' => 'proj_docs', 'worktree' => '/home/orbit/apps/docs']);
$insertProject->execute(['id' => 'proj_api', 'worktree' => '/home/orbit/apps/api']);

$insertWorkspace = $pdo->prepare("insert into workspace (id, type, name, branch, directory, extra, project_id) values (:id, 'worktree', :name, :branch, :directory, null, 'proj_docs')");
$insertWorkspace->execute([
    'id' => 'wrk_docs',
    'name' => $workspaceName,
    'branch' => $workspaceName,
    'directory' => $workspacePath,
]);
PHP;

    $script = str_replace(
        ['__WORKSPACE_NAME__', '__WORKSPACE_PATH__', '__DATABASE_PATH__'],
        [var_export($workspaceName, true), var_export($workspacePath, true), var_export($databasePath, true)],
        $script,
    );

    $topology->ssh(
        'dev',
        'php <<'.escapeshellarg('PHP').PHP_EOL.$script.PHP_EOL.'PHP',
        timeoutSeconds: 120,
    );

    $topology->ssh(
        'dev',
        sprintf(
            "touch %1\$s && grep -Ev '^ORBIT_OPENCODE_DB_PATH=' %1\$s > %1\$s.tmp || true; mv %1\$s.tmp %1\$s; printf 'ORBIT_OPENCODE_DB_PATH=%%s\n' %2\$s >> %1\$s",
            escapeshellarg($topology->checkout('dev').'/apps/cli/.env'),
            escapeshellarg($databasePath),
        ),
        timeoutSeconds: 120,
    );
}

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
