<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;
use Illuminate\Contracts\Process\ProcessResult;

it('installs OpenCode Server and creates an accessible OpenCode-backed workspace on Incus', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev, withGatewayApi: true);
    $appName = 'e2e-opencode-'.strtolower(bin2hex(random_bytes(3)));
    $workspaceName = 'feature-opencode-'.strtolower(bin2hex(random_bytes(3)));
    $appPath = "/home/orbit/apps/{$appName}";
    $workspacePath = "{$appPath}/.worktrees/{$workspaceName}";
    $workspaceUrl = null;

    try {
        $topology->withCurrentCheckout(roles: ['operator', 'gateway', 'dev']);
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        e2eRestartGatewayApi($topology, 'opencode-workspace');
        E2EGatewayApi::waitForGatewayApi(
            $topology->instance('operator'),
            $config->operatorUser,
            $topology->lease()->sshKeyPair(),
            gatewayIp: $gatewayApiIp,
        );

        e2eGrantNodeAccess($topology);
        opencodeWorkspaceInstallFakeInstaller($topology);

        $install = $topology->ssh(
            'operator',
            sprintf(
                'cd %s && orbit tool:install opencode-server --node=app-dev-1 --json',
                escapeshellarg($topology->checkout('operator')),
            ),
            timeoutSeconds: 240,
        );
        $installPayload = e2eJsonCommandPayload($install->output());
        $installData = e2eJsonCommandData($installPayload);

        expect($install->successful())
            ->toBeTrue()
            ->and($installData['tool'])
            ->toMatchArray([
                'name' => 'opencode-server',
                'node' => 'app-dev-1',
                'state' => 'installed',
            ])
            ->and($installData['tool']['process'])
            ->toMatchArray([
                'name' => 'opencode-server',
                'runtime' => 'systemd',
                'tool' => 'opencode',
            ]);

        opencodeWorkspaceWaitForServer($topology);

        $topology->ssh(
            'dev',
            sprintf('sudo install -d -o orbit -g orbit %s', escapeshellarg($appPath)),
            timeoutSeconds: 60,
        );

        $registration = $topology->ssh(
            'operator',
            sprintf(
                'cd %s && orbit instance:register %s --node=app-dev-1 --path=%s --root=public --php-version=8.5 --json',
                escapeshellarg($topology->checkout('operator')),
                escapeshellarg($appName),
                escapeshellarg($appPath),
            ),
            timeoutSeconds: 240,
        );
        $registrationPayload = e2eJsonCommandPayload($registration->output());
        $registrationData = e2eJsonCommandData($registrationPayload);

        expect($registration->successful())
            ->toBeTrue()
            ->and($registrationData['result']['action'])
            ->toBe('adopted')
            ->and($registrationData['project'])
            ->toMatchArray([
                'name' => $appName,
            ])
            ->and($registrationData['instance'])
            ->toMatchArray([
                'node' => 'app-dev-1',
                'path' => $appPath,
            ]);

        opencodeWorkspacePrepareLaravelApp($topology, $appPath);

        $agentIde = $topology->ssh(
            'operator',
            sprintf(
                'cd %s && orbit instance:agent-ide %s opencode --json',
                escapeshellarg($topology->checkout('operator')),
                escapeshellarg($appName),
            ),
            timeoutSeconds: 180,
        );
        $agentIdePayload = e2eJsonCommandPayload($agentIde->output());

        expect($agentIde->successful())
            ->toBeTrue()
            ->and($agentIdePayload['success']['data']['agent_ide'])
            ->toMatchArray([
                'adapter' => 'opencode',
                'source' => 'app',
                'effective_adapter' => 'opencode',
            ]);

        $workspace = $topology->ssh(
            'operator',
            sprintf(
                'cd %s && orbit workspace:new %s --instance=%s --json',
                escapeshellarg($topology->checkout('operator')),
                escapeshellarg($workspaceName),
                escapeshellarg($appName),
            ),
            timeoutSeconds: 360,
        );
        $workspacePayload = e2eJsonCommandPayload($workspace->output());
        $workspaceData = e2eJsonCommandResultData($workspacePayload);
        $workspaceUrl = $workspaceData['workspace']['url'];

        expect($workspace->successful())
            ->toBeTrue()
            ->and($workspaceData['result'])
            ->toBe(['action' => 'created'])
            ->and($workspaceData['workspace'])
            ->toMatchArray([
                'name' => $workspaceName,
                'project' => $appName,
                'instance' => 'development',
                'node' => 'app-dev-1',
                'path' => $workspacePath,
                'agent_ide' => [
                    'adapter' => 'opencode',
                    'workspace_id' => "session-{$workspaceName}",
                ],
                'lifecycle_status' => 'active',
            ]);

        $state = opencodeWorkspaceGatewayState($topology, $appName, $workspaceName);

        expect($state)->toMatchArray([
            'process' => [
                'name' => 'opencode-server',
                'runtime' => 'systemd',
                'tool' => 'opencode',
            ],
            'workspace' => [
                'name' => $workspaceName,
                'path' => $workspacePath,
                'agent_ide' => 'opencode',
                'agent_ide_workspace_id' => "session-{$workspaceName}",
                'lifecycle_status' => 'active',
            ],
        ]);

        $adapterSession = $topology->ssh(
            'gateway',
            sprintf(
                'curl -fsS %s',
                escapeshellarg(
                    "http://{$state['app_node_host']}:4096/session/session-{$workspaceName}?directory={$workspacePath}",
                ),
            ),
            timeoutSeconds: 60,
        );

        expect($adapterSession->successful())->toBeTrue();

        $workspaceHost = parse_url($workspaceUrl, PHP_URL_HOST);

        expect($workspaceHost)->toBeString();

        $workspaceHttpStatus = opencodeWorkspaceWaitForHttpsRoute(
            $topology,
            $workspaceHost,
            $state['app_node_host'],
            $workspaceUrl,
        );

        expect($workspaceHttpStatus->successful())
            ->toBeTrue($workspaceHttpStatus->output().$workspaceHttpStatus->errorOutput())
            ->and(trim($workspaceHttpStatus->output()))
            ->toBe('200');
    } finally {
        $topology->ssh('dev', 'sudo rm -rf '.escapeshellarg($appPath), timeoutSeconds: 60, allowFailure: true);
        $topology->cleanup();
    }
})->group(
    'e2e-feature',
    'e2e-provider-incus',
    'e2e-feature-operator_gateway_app-dev',
    'e2e-feature-operator-gateway-dev',
);

function opencodeWorkspaceInstallFakeInstaller(E2ETopologyHarness $topology): void
{
    $opencode = <<<'BASH'
        #!/usr/bin/env bash
        set -euo pipefail

        if [ "${1:-}" != "serve" ]; then
            echo "opencode 1.0.0"
            exit 0
        fi

        router="$(mktemp /tmp/orbit-fake-opencode.XXXXXX.php)"
        cat > "$router" <<'PHP'
        <?php

        declare(strict_types=1);

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $query = [];
        parse_str(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_QUERY) ?: '', $query);

        function respond(mixed $payload, int $status = 200): void
        {
            http_response_code($status);
            header('Content-Type: application/json');
            echo json_encode($payload, JSON_THROW_ON_ERROR);
        }

        if ($method === 'GET' && $path === '/project/current') {
            $directory = (string) ($query['directory'] ?? '');
            respond([
                'id' => 'project-'.basename($directory),
                'worktree' => $directory,
                'time' => ['created' => time()],
                'sandboxes' => [],
            ]);
            return;
        }

        if ($method === 'GET' && $path === '/experimental/worktree') {
            $directory = (string) ($query['directory'] ?? '');
            $worktrees = is_dir("{$directory}/.worktrees")
                ? array_values(array_map(
                    static fn (string $path): string => "{$directory}/.worktrees/{$path}",
                    array_values(array_diff(scandir("{$directory}/.worktrees") ?: [], ['.', '..'])),
                ))
                : [];
            respond($worktrees);
            return;
        }

        if ($method === 'POST' && $path === '/experimental/worktree') {
            $directory = (string) ($query['directory'] ?? '');
            $body = json_decode(file_get_contents('php://input') ?: '{}', true, flags: JSON_THROW_ON_ERROR);
            $name = (string) ($body['name'] ?? '');
            $workspace = "{$directory}/.worktrees/{$name}";

            if ($directory === '' || $name === '') {
                respond(['error' => 'missing directory or name'], 422);
                return;
            }

            if (! is_dir($workspace)) {
                $command = sprintf(
                    'git -C %s worktree add -b %s %s HEAD 2>&1',
                    escapeshellarg($directory),
                    escapeshellarg($name),
                    escapeshellarg($workspace),
                );
                exec($command, $output, $exitCode);

                if ($exitCode !== 0) {
                    respond(['error' => implode("\n", $output)], 500);
                    return;
                }
            }

            respond([
                'name' => $name,
                'branch' => $name,
                'directory' => $workspace,
            ]);
            return;
        }

        if ($method === 'POST' && $path === '/session') {
            $directory = (string) ($query['directory'] ?? '');
            $body = json_decode(file_get_contents('php://input') ?: '{}', true, flags: JSON_THROW_ON_ERROR);
            $title = (string) ($body['title'] ?? basename($directory));

            respond([
                'id' => "session-{$title}",
                'title' => $title,
                'directory' => $directory,
                'projectID' => 'project-'.basename(dirname(dirname($directory))),
            ]);
            return;
        }

        if ($method === 'GET' && preg_match('#^/session/(?<id>[^/]+)$#', $path, $matches) === 1) {
            respond([
                'id' => $matches['id'],
                'title' => $matches['id'],
                'directory' => (string) ($query['directory'] ?? ''),
            ]);
            return;
        }

        respond(['error' => 'not found', 'path' => $path], 404);
        PHP

        exec php -S 0.0.0.0:4096 "$router"
        BASH;

    $installer = <<<'BASH'
        #!/usr/bin/env bash
        set -euo pipefail
        true
        BASH;

    $curl = <<<'BASH'
        #!/usr/bin/env bash
        set -euo pipefail

        if printf '%s\n' "$*" | grep -q 'https://opencode.ai/install'; then
            cat /tmp/orbit-fake-opencode-install.sh
            exit 0
        fi

        exec /usr/bin/curl "$@"
        BASH;

    $topology->ssh(
        'dev',
        sprintf(
            <<<'SH'
                printf %%s %s | sudo tee /tmp/orbit-fake-opencode >/dev/null
                printf %%s %s | sudo tee /usr/local/bin/opencode >/dev/null
                printf %%s %s | sudo tee /tmp/orbit-fake-opencode-install.sh >/dev/null
                printf %%s %s | sudo tee /usr/local/bin/curl >/dev/null
                sudo chmod 0755 /tmp/orbit-fake-opencode /usr/local/bin/opencode /tmp/orbit-fake-opencode-install.sh /usr/local/bin/curl
                SH,
            escapeshellarg($opencode),
            escapeshellarg($opencode),
            escapeshellarg($installer),
            escapeshellarg($curl),
        ),
        timeoutSeconds: 120,
    );
}

function opencodeWorkspaceWaitForServer(E2ETopologyHarness $topology): void
{
    $topology->ssh(
        'dev',
        'for i in $(seq 1 30); do curl -fsS "http://127.0.0.1:4096/project/current?directory=/home/orbit" >/dev/null 2>&1 && exit 0; sleep 1; done; sudo systemctl status opencode-server --no-pager || true; exit 1',
        timeoutSeconds: 60,
    );
}

function opencodeWorkspacePrepareLaravelApp(E2ETopologyHarness $topology, string $appPath): void
{
    $topology->ssh(
        'dev',
        sprintf(
            <<<'SH'
                cd %1$s
                mkdir -p public
                cat > composer.json <<'JSON'
                {"name":"orbit/e2e-stock-laravel","type":"project","require":{"php":"^8.5","laravel/framework":"^13.0"}}
                JSON
                cat > artisan <<'PHP'
                #!/usr/bin/env php
                <?php
                PHP
                cat > public/index.php <<'PHP'
                <?php

                http_response_code(200);
                echo 'stock laravel app';
                PHP
                git init -b main >/dev/null
                git config user.email orbit@example.test
                git config user.name Orbit
                git add .
                git commit -m "Initial stock Laravel app" >/dev/null
                SH,
            escapeshellarg($appPath),
        ),
        timeoutSeconds: 120,
    );
}

function opencodeWorkspaceWaitForHttpsRoute(
    E2ETopologyHarness $topology,
    string $workspaceHost,
    string $appNodeHost,
    string $workspaceUrl,
): ProcessResult {
    $resolve = escapeshellarg("{$workspaceHost}:443:{$appNodeHost}");
    $url = escapeshellarg($workspaceUrl);
    $script = sprintf(
        <<<'SH'
            for attempt in $(seq 1 30); do
                status="$(curl --resolve %s -k -sS -o /tmp/orbit-opencode-workspace-response.txt -w "%%{http_code}" %s 2>/tmp/orbit-opencode-workspace-curl.err || true)"

                if [ "$status" = "200" ]; then
                    printf '200'
                    exit 0
                fi

                sleep 2
            done

            curl --resolve %s -k -sS -o /tmp/orbit-opencode-workspace-response.txt -w "%%{http_code}" %s
            SH,
        $resolve,
        $url,
        $resolve,
        $url,
    );

    return $topology->ssh('dev', $script, timeoutSeconds: 90, allowFailure: true);
}

/**
 * @return array<string, mixed>
 */
function opencodeWorkspaceGatewayState(E2ETopologyHarness $topology, string $appName, string $workspaceName): array
{
    $script = <<<'PHP'
        $appName = __APP_NAME__;
        $workspaceName = __WORKSPACE_NAME__;
        $app = \App\Models\Project::query()->where('name', $appName)->firstOrFail();
        $workspace = \App\Models\Workspace::query()
            ->where('app_id', $app->id)
            ->where('name', $workspaceName)
            ->firstOrFail();
        $node = $app->node()->firstOrFail();
        $process = \App\Models\Process::query()
            ->where('node_id', $node->id)
            ->where('name', 'opencode-server')
            ->firstOrFail();

        echo json_encode([
            'app_node_host' => $node->wireguard_address ?? $node->host,
            'process' => [
                'name' => $process->name,
                'runtime' => $process->runtime->value,
                'tool' => $process->tool,
            ],
            'workspace' => [
                'name' => $workspace->name,
                'path' => $workspace->path,
                'agent_ide' => $workspace->agent_ide,
                'agent_ide_workspace_id' => $workspace->agent_ide_workspace_id,
                'lifecycle_status' => $workspace->lifecycle_status->value,
            ],
        ], JSON_THROW_ON_ERROR);
        PHP;

    $script = str_replace(
        ['__APP_NAME__', '__WORKSPACE_NAME__'],
        [var_export($appName, true), var_export($workspaceName, true)],
        $script,
    );

    $result = $topology->ssh(
        'gateway',
        'cd '.escapeshellarg($topology->checkout('gateway')).' && php apps/gateway/artisan tinker --execute='
            .escapeshellarg($script),
        timeoutSeconds: 120,
    );

    return json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);
}
