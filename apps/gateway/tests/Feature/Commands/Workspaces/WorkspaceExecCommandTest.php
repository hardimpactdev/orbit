<?php

declare(strict_types=1);

use App\Console\Commands\WorkspaceExecCommand;
use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Apps\AppRuntimeKind;
use App\Http\Gateway\Requests\Workspaces\WorkspaceExecRequest;
use App\Models\App;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Console\Tester\CommandTester;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['orbit.is_gateway' => true]);
});

afterEach(function (): void {
    unset($_SERVER['ORBIT_HOST_CWD'], $_ENV['ORBIT_HOST_CWD']);
    putenv('ORBIT_HOST_CWD');
    MockClient::destroyGlobal();
});

function createExecCommandWorkspace(array $appOverrides = [], array $workspaceOverrides = []): Workspace
{
    $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app', 'tld' => 'test']);
    $app = App::factory()->for($node, 'node')->create(array_merge([
        'name' => 'docs',
        'path' => '/home/orbit/apps/docs',
        'document_root' => 'public',
        'php_version' => '8.5',
        'runtime_kind' => AppRuntimeKind::Php,
    ], $appOverrides));

    return Workspace::factory()->for($app, 'app')->create(array_merge([
        'name' => 'docs-feature',
        'path' => '/home/orbit/apps/docs/.worktrees/docs-feature',
    ], $workspaceOverrides));
}

function workspacePreflightRunningResult(): RemoteShellResult
{
    return new RemoteShellResult(exitCode: 0, stdout: "true\n", stderr: '', durationMs: 1);
}

/**
 * @param  list<RemoteShellResult>  $results  Sequence of exec-call results;
 *                                            a preflight "running" result is
 *                                            prepended automatically before
 *                                            every exec call so tests only
 *                                            specify the actual exec outcomes.
 */
function bindWorkspaceExecShell(array $results = []): void
{
    $scripted = [];

    foreach ($results as $result) {
        $scripted[] = workspacePreflightRunningResult();
        $scripted[] = $result;
    }

    app()->instance(RemoteShell::class, new class($scripted) implements RemoteShell
    {
        /** @var list<array{node: string, script: string}> */
        public array $calls = [];

        public function __construct(public array $results) {}

        public function run(Node $node, string $script, array $options = []): RemoteShellResult
        {
            $this->calls[] = ['node' => $node->name, 'script' => $script];

            $next = array_shift($this->results);

            if ($next instanceof RemoteShellResult) {
                return $next;
            }

            if (str_contains($script, 'docker container inspect')) {
                return workspacePreflightRunningResult();
            }

            return new RemoteShellResult(
                exitCode: 0,
                stdout: 'PHP 8.5.0 (cli)',
                stderr: '',
                durationMs: 1,
            );
        }
    });
}

function bindWorkspaceExecPreflightShell(RemoteShellResult $preflight): void
{
    app()->instance(RemoteShell::class, new class([$preflight]) implements RemoteShell
    {
        public function __construct(public array $results) {}

        public function run(Node $node, string $script, array $options = []): RemoteShellResult
        {
            return array_shift($this->results) ?? new RemoteShellResult(
                exitCode: 0,
                stdout: "true\n",
                stderr: '',
                durationMs: 1,
            );
        }
    });
}

describe('workspace:exec command', function (): void {
    it('runs a command inside the workspace runtime container and returns the JSON envelope', function (): void {
        createExecCommandWorkspace();
        bindWorkspaceExecShell([
            new RemoteShellResult(exitCode: 0, stdout: "PHP 8.5.0\n", stderr: '', durationMs: 1),
        ]);

        $exitCode = Artisan::call('workspace:exec', [
            'workspace' => 'docs-feature',
            'cmd' => ['php', '-v'],
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data'])->toMatchArray([
                'workspace' => 'docs-feature',
                'app' => 'docs',
                'container' => 'orbit-ws-docs-docs-feature',
                'command' => ['php', '-v'],
                'exit_code' => 0,
                'stdout' => "PHP 8.5.0\n",
                'stderr' => '',
            ]);
    });

    it('reports the underlying exit code in success.data.exit_code without failing the wrapper', function (): void {
        createExecCommandWorkspace();
        bindWorkspaceExecShell([
            new RemoteShellResult(exitCode: 2, stdout: '', stderr: "boom\n", durationMs: 1),
        ]);

        $exitCode = Artisan::call('workspace:exec', [
            'workspace' => 'docs-feature',
            'cmd' => ['php', 'artisan', 'test'],
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['exit_code'])->toBe(2)
            ->and($payload['success']['data']['stderr'])->toBe("boom\n");
    });

    it('passes the underlying exit code through to the caller in human mode', function (): void {
        createExecCommandWorkspace();
        bindWorkspaceExecShell([
            new RemoteShellResult(exitCode: 4, stdout: '', stderr: "fail\n", durationMs: 1),
        ]);

        $exitCode = Artisan::call('workspace:exec', [
            'workspace' => 'docs-feature',
            'cmd' => ['php', 'artisan', 'test'],
        ]);

        expect($exitCode)->toBe(4);
    });

    it('rejects an empty command list with validation_failed', function (): void {
        createExecCommandWorkspace();
        bindWorkspaceExecShell();

        $exitCode = Artisan::call('workspace:exec', [
            'workspace' => 'docs-feature',
            'cmd' => [],
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('command');
    });

    it('returns workspace.not_found for an unknown selector', function (): void {
        createExecCommandWorkspace();
        bindWorkspaceExecShell();

        $exitCode = Artisan::call('workspace:exec', [
            'workspace' => 'missing',
            'cmd' => ['php', '-v'],
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('workspace.not_found');
    });

    it('refuses to exec when the parent app is not PHP', function (): void {
        createExecCommandWorkspace(['runtime_kind' => AppRuntimeKind::Static]);
        bindWorkspaceExecShell();

        $exitCode = Artisan::call('workspace:exec', [
            'workspace' => 'docs-feature',
            'cmd' => ['php', '-v'],
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('workspace.exec_unsupported_runtime')
            ->and($payload['error']['meta']['runtime_kind'])->toBe('static');
    });

    it('returns workspace.exec_container_not_running when preflight reports no such container', function (): void {
        createExecCommandWorkspace();
        bindWorkspaceExecPreflightShell(new RemoteShellResult(
            exitCode: 1,
            stdout: '',
            stderr: 'Error response from daemon: No such container: orbit-ws-docs-docs-feature',
            durationMs: 1,
        ));

        $exitCode = Artisan::call('workspace:exec', [
            'workspace' => 'docs-feature',
            'cmd' => ['php', '-v'],
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('workspace.exec_container_not_running')
            ->and($payload['error']['meta']['container'])->toBe('orbit-ws-docs-docs-feature')
            ->and($payload['error']['meta']['node'])->toBe('app-1');
    });

    it('returns workspace.exec_container_not_running when preflight reports the container is stopped', function (): void {
        createExecCommandWorkspace();
        bindWorkspaceExecPreflightShell(new RemoteShellResult(
            exitCode: 0,
            stdout: "false\n",
            stderr: '',
            durationMs: 1,
        ));

        $exitCode = Artisan::call('workspace:exec', [
            'workspace' => 'docs-feature',
            'cmd' => ['php', '-v'],
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('workspace.exec_container_not_running')
            ->and($payload['error']['meta']['state'])->toBe('false');
    });

    it('returns workspace.exec_docker_unavailable when preflight reports the docker daemon is down', function (): void {
        createExecCommandWorkspace();
        bindWorkspaceExecPreflightShell(new RemoteShellResult(
            exitCode: 1,
            stdout: '',
            stderr: "Cannot connect to the Docker daemon at unix:///var/run/docker.sock. Is the docker daemon running?\n",
            durationMs: 1,
        ));

        $exitCode = Artisan::call('workspace:exec', [
            'workspace' => 'docs-feature',
            'cmd' => ['php', '-v'],
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('workspace.exec_docker_unavailable')
            ->and($payload['error']['meta']['node'])->toBe('app-1');
    });

    it('returns workspace.exec_node_unreachable when preflight returns a generic SSH-level failure', function (): void {
        createExecCommandWorkspace();
        bindWorkspaceExecPreflightShell(new RemoteShellResult(
            exitCode: 255,
            stdout: '',
            stderr: "ssh: connect to host 10.6.0.7 port 22: Connection refused\n",
            durationMs: 1,
        ));

        $exitCode = Artisan::call('workspace:exec', [
            'workspace' => 'docs-feature',
            'cmd' => ['php', '-v'],
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('workspace.exec_node_unreachable')
            ->and($payload['error']['meta']['exit_code'])->toBe(255);
    });

    it('treats a non-zero exec exit that does not match docker wrapper patterns as a child failure, not infra failure', function (): void {
        createExecCommandWorkspace();
        bindWorkspaceExecShell([
            new RemoteShellResult(
                exitCode: 11,
                stdout: '',
                stderr: "Tests failed\n",
                durationMs: 1,
            ),
        ]);

        $exitCode = Artisan::call('workspace:exec', [
            'workspace' => 'docs-feature',
            'cmd' => ['php', 'artisan', 'test'],
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['exit_code'])->toBe(11)
            ->and($payload['success']['data']['stderr'])->toBe("Tests failed\n");
    });

    it('returns gateway_unavailable when a control-mode caller cannot reach the gateway exec endpoint', function (): void {
        config(['orbit.is_gateway' => false]);
        Node::factory()->create(['name' => 'control-1', 'role' => 'control']);

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        MockClient::global([
            WorkspaceExecRequest::class => MockResponse::make([], 500),
        ]);

        $exitCode = Artisan::call('workspace:exec', [
            'workspace' => 'docs-feature',
            'cmd' => ['php', '-v'],
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('gateway_unavailable');
    });

    it('forwards a successful gateway exec response back to the caller in control mode', function (): void {
        config(['orbit.is_gateway' => false]);
        Node::factory()->create(['name' => 'control-1', 'role' => 'control']);

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        MockClient::global([
            WorkspaceExecRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'workspace' => 'docs-feature',
                        'app' => 'docs',
                        'container' => 'orbit-ws-docs-docs-feature',
                        'command' => ['php', '-v'],
                        'exit_code' => 0,
                        'stdout' => "PHP 8.5.0\n",
                        'stderr' => '',
                    ],
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('workspace:exec', [
            'workspace' => 'docs-feature',
            'cmd' => ['php', '-v'],
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['workspace'])->toBe('docs-feature')
            ->and($payload['success']['data']['exit_code'])->toBe(0);
    });

    it('resolves the workspace from ORBIT_HOST_CWD when the selector is omitted and cwd is inside the workspace path', function (): void {
        createExecCommandWorkspace();
        bindWorkspaceExecShell([
            new RemoteShellResult(exitCode: 0, stdout: "PHP 8.5.0\n", stderr: '', durationMs: 1),
        ]);

        putenv('ORBIT_HOST_CWD=/home/orbit/apps/docs/.worktrees/docs-feature/app');

        $exitCode = Artisan::call('workspace:exec', [
            'cmd' => ['php', '-v'],
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['workspace'])->toBe('docs-feature');
    });

    it('fails with validation_failed when cwd resolves to the parent app but not a workspace', function (): void {
        // Cwd is inside the parent app but not inside any workspace. workspace:exec
        // requires a workspace and must not silently fall back to the parent app.
        createExecCommandWorkspace();
        bindWorkspaceExecShell();

        putenv('ORBIT_HOST_CWD=/home/orbit/apps/docs/app/Models');

        $exitCode = Artisan::call('workspace:exec', [
            'cmd' => ['php', '-v'],
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('workspace');
    });

    it('refuses an ambiguous workspace name when two apps host workspaces with the same name and --app is omitted', function (): void {
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app', 'tld' => 'test']);
        $appA = App::factory()->for($node, 'node')->create([
            'name' => 'docs',
            'path' => '/home/orbit/apps/docs',
            'runtime_kind' => AppRuntimeKind::Php,
        ]);
        $appB = App::factory()->for($node, 'node')->create([
            'name' => 'site',
            'path' => '/home/orbit/apps/site',
            'runtime_kind' => AppRuntimeKind::Php,
        ]);
        Workspace::factory()->for($appA, 'app')->create(['name' => 'shared', 'path' => '/home/orbit/apps/docs/.worktrees/shared']);
        Workspace::factory()->for($appB, 'app')->create(['name' => 'shared', 'path' => '/home/orbit/apps/site/.worktrees/shared']);
        bindWorkspaceExecShell();

        $exitCode = Artisan::call('workspace:exec', [
            'workspace' => 'shared',
            'cmd' => ['php', '-v'],
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('workspace.ambiguous_name')
            ->and($payload['error']['meta']['apps'])->toContain('docs')
            ->and($payload['error']['meta']['apps'])->toContain('site');
    });

    it('resolves an ambiguous workspace name to the correct app when --app is supplied', function (): void {
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app', 'tld' => 'test']);
        $appA = App::factory()->for($node, 'node')->create([
            'name' => 'docs',
            'path' => '/home/orbit/apps/docs',
            'runtime_kind' => AppRuntimeKind::Php,
        ]);
        $appB = App::factory()->for($node, 'node')->create([
            'name' => 'site',
            'path' => '/home/orbit/apps/site',
            'runtime_kind' => AppRuntimeKind::Php,
        ]);
        Workspace::factory()->for($appA, 'app')->create(['name' => 'shared', 'path' => '/home/orbit/apps/docs/.worktrees/shared']);
        Workspace::factory()->for($appB, 'app')->create(['name' => 'shared', 'path' => '/home/orbit/apps/site/.worktrees/shared']);
        bindWorkspaceExecShell([
            new RemoteShellResult(exitCode: 0, stdout: "ok\n", stderr: '', durationMs: 1),
        ]);

        $exitCode = Artisan::call('workspace:exec', [
            'workspace' => 'shared',
            'cmd' => ['php', '-v'],
            '--app' => 'site',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['workspace'])->toBe('shared')
            ->and($payload['success']['data']['app'])->toBe('site')
            ->and($payload['success']['data']['container'])->toBe('orbit-ws-site-shared');
    });

    it('treats child stderr that mentions Docker daemon as a child failure rather than infra failure when exit code is not 125', function (): void {
        createExecCommandWorkspace();
        bindWorkspaceExecShell([
            new RemoteShellResult(
                exitCode: 7,
                stdout: '',
                stderr: "Cannot connect to the Docker daemon (printed by the user app, not the wrapper)\n",
                durationMs: 1,
            ),
        ]);

        $exitCode = Artisan::call('workspace:exec', [
            'workspace' => 'docs-feature',
            'cmd' => ['php', 'artisan', 'check-docker'],
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        // Wrapper succeeded (0); child reported exit 7 with daemon-down stderr.
        // The classifier must NOT promote this to workspace.exec_docker_unavailable
        // because docker's exit code (7) does not match its wrapper failure code (125).
        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['exit_code'])->toBe(7)
            ->and($payload['success']['data']['stderr'])->toContain('Cannot connect to the Docker daemon');
    });

    it('forwards the raw ORBIT_HOST_CWD through the gateway typed request without touching local Workspace rows in control mode', function (): void {
        // Control mode caller: SQLite is empty on this side. The CLI MUST NOT
        // try to resolve App or Workspace rows locally — the gateway owns
        // that state. The CLI's job is to forward the raw host cwd and let
        // the gateway resolve.
        config(['orbit.is_gateway' => false]);
        Node::factory()->create(['name' => 'control-1', 'role' => 'control']);

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        // Local DB has NO matching Workspace row — proves we did not resolve
        // locally. The Saloon mock receives the request and we inspect its
        // body to assert the raw host cwd was forwarded.
        $captured = [];
        MockClient::global([
            WorkspaceExecRequest::class => function ($pending) use (&$captured) {
                $captured = [
                    'endpoint' => $pending->getRequest()->resolveEndpoint(),
                    'body' => $pending->getRequest()->body()->all(),
                ];

                return MockResponse::make([
                    'success' => [
                        'data' => [
                            'workspace' => 'docs-feature',
                            'app' => 'docs',
                            'container' => 'orbit-ws-docs-docs-feature',
                            'command' => ['php', '-v'],
                            'exit_code' => 0,
                            'stdout' => "PHP\n",
                            'stderr' => '',
                        ],
                    ],
                ], 200);
            },
        ]);

        putenv('ORBIT_HOST_CWD=/home/orbit/apps/docs/.worktrees/docs-feature');

        $exitCode = Artisan::call('workspace:exec', [
            'cmd' => ['php', '-v'],
            '--json' => true,
        ]);

        expect($exitCode)->toBe(0)
            ->and(Workspace::query()->count())->toBe(0)
            ->and($captured['endpoint'])->toBe('/api/workspaces/exec/by-path')
            ->and($captured['body']['host_cwd'])->toBe('/home/orbit/apps/docs/.worktrees/docs-feature')
            ->and($captured['body']['command'])->toBe(['php', '-v']);
    });

    it('forwards --app filter and explicit selector through the gateway request when both are present in control mode', function (): void {
        config(['orbit.is_gateway' => false]);
        Node::factory()->create(['name' => 'control-1', 'role' => 'control']);

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        $captured = [];
        MockClient::global([
            WorkspaceExecRequest::class => function ($pending) use (&$captured) {
                $captured = [
                    'endpoint' => $pending->getRequest()->resolveEndpoint(),
                    'query' => $pending->getRequest()->query()->all(),
                    'body' => $pending->getRequest()->body()->all(),
                ];

                return MockResponse::make([
                    'success' => [
                        'data' => [
                            'workspace' => 'shared',
                            'app' => 'site',
                            'container' => 'orbit-ws-site-shared',
                            'command' => ['php', '-v'],
                            'exit_code' => 0,
                            'stdout' => '',
                            'stderr' => '',
                        ],
                    ],
                ], 200);
            },
        ]);

        $exitCode = Artisan::call('workspace:exec', [
            'workspace' => 'shared',
            'cmd' => ['php', '-v'],
            '--app' => 'site',
            '--json' => true,
        ]);

        expect($exitCode)->toBe(0)
            ->and($captured['endpoint'])->toBe('/api/workspaces/shared/exec')
            ->and($captured['query']['app'])->toBe('site');
    });

    it('reports workspace.exec_command_not_executable when docker exec returns exit code 126', function (): void {
        createExecCommandWorkspace();
        bindWorkspaceExecShell([
            new RemoteShellResult(
                exitCode: 126,
                stdout: '',
                stderr: "OCI runtime exec failed: exec failed: unable to start container process: exec: \"./missing-binary\": permission denied\n",
                durationMs: 1,
            ),
        ]);

        $exitCode = Artisan::call('workspace:exec', [
            'workspace' => 'docs-feature',
            'cmd' => ['./missing-binary'],
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('workspace.exec_command_not_executable')
            ->and($payload['error']['meta']['exit_code'])->toBe(126);
    });

    it('routes child stdout and stderr to separate streams in human mode', function (): void {
        createExecCommandWorkspace();
        bindWorkspaceExecShell([
            new RemoteShellResult(
                exitCode: 0,
                stdout: "stdout payload\n",
                stderr: "stderr payload\n",
                durationMs: 1,
            ),
        ]);

        $command = app(WorkspaceExecCommand::class);
        $command->setLaravel(app());

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'workspace' => 'docs-feature',
            'cmd' => ['php', '-v'],
        ], [
            'capture_stderr_separately' => true,
        ]);

        expect($exitCode)->toBe(0)
            ->and($tester->getDisplay())->toBe("stdout payload\n")
            ->and($tester->getErrorOutput())->toBe("stderr payload\n");
    });

    it('reports workspace.exec_command_not_found when docker exec returns exit code 127', function (): void {
        createExecCommandWorkspace();
        bindWorkspaceExecShell([
            new RemoteShellResult(
                exitCode: 127,
                stdout: '',
                stderr: "OCI runtime exec failed: exec failed: unable to start container process: exec: \"nonsuchcmd\": executable file not found in \$PATH\n",
                durationMs: 1,
            ),
        ]);

        $exitCode = Artisan::call('workspace:exec', [
            'workspace' => 'docs-feature',
            'cmd' => ['nonsuchcmd'],
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('workspace.exec_command_not_found')
            ->and($payload['error']['meta']['exit_code'])->toBe(127);
    });
});
