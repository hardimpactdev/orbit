<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Apps\AppRuntimeKind;
use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const WORKSPACE_EXEC_CALLER_WG_IP = '10.6.0.99';

beforeEach(function (): void {
    config(['orbit.is_gateway' => true]);
});

function createWorkspaceExecCaller(array $overrides = []): Node
{
    $attributes = array_merge([
        'name' => 'caller',
        'host' => WORKSPACE_EXEC_CALLER_WG_IP,
        'wireguard_address' => WORKSPACE_EXEC_CALLER_WG_IP,
    ], $overrides);

    return Node::factory()->create($attributes);
}

/**
 * @param  list<string>  $permissions
 */
function grantWorkspaceExecAccess(Node $caller, Node $appNode, array $permissions): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
        'permissions' => json_encode($permissions, JSON_THROW_ON_ERROR),
        'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function workspaceExecControllerPreflightRunning(): RemoteShellResult
{
    return new RemoteShellResult(exitCode: 0, stdout: "true\n", stderr: '', durationMs: 1);
}

/**
 * @param  list<RemoteShellResult>  $results
 */
function bindWorkspaceExecControllerShell(array $results = []): void
{
    $scripted = [];

    foreach ($results as $result) {
        $scripted[] = workspaceExecControllerPreflightRunning();
        $scripted[] = $result;
    }

    app()->instance(RemoteShell::class, new class($scripted) implements RemoteShell
    {
        public function __construct(public array $results) {}

        public function run(Node $node, string $script, array $options = []): RemoteShellResult
        {
            $next = array_shift($this->results);

            if ($next instanceof RemoteShellResult) {
                return $next;
            }

            if (str_contains($script, 'docker container inspect')) {
                return workspaceExecControllerPreflightRunning();
            }

            return new RemoteShellResult(
                exitCode: 0,
                stdout: 'PHP 8.5.0',
                stderr: '',
                durationMs: 1,
            );
        }
    });
}

function bindWorkspaceExecControllerPreflightShell(RemoteShellResult $preflight): void
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

function createWorkspaceExecFixture(Node $node, array $appOverrides = [], array $workspaceOverrides = []): Workspace
{
    $app = App::factory()->for($node, 'node')->create(array_merge([
        'name' => 'docs',
        'path' => '/home/orbit/apps/docs',
        'document_root' => 'public',
        'runtime_kind' => AppRuntimeKind::Php,
    ], $appOverrides));

    return Workspace::factory()->for($app, 'app')->create(array_merge([
        'name' => 'docs-feature',
        'path' => '/home/orbit/apps/docs/.worktrees/docs-feature',
    ], $workspaceOverrides));
}

describe('WorkspaceExecController', function (): void {
    it('runs a command inside the workspace runtime container and returns the success envelope', function (): void {
        $caller = createWorkspaceExecCaller();
        $node = createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'host' => '10.6.0.7']);
        grantWorkspaceExecAccess($caller, $node, ['workspace:exec']);
        createWorkspaceExecFixture($node);
        bindWorkspaceExecControllerShell([
            new RemoteShellResult(exitCode: 0, stdout: "PHP 8.5.0\n", stderr: '', durationMs: 1),
        ]);

        $response = $this->call(
            'POST',
            '/api/workspaces/docs-feature/exec',
            [],
            [],
            [],
            ['REMOTE_ADDR' => WORKSPACE_EXEC_CALLER_WG_IP, 'CONTENT_TYPE' => 'application/json'],
            json_encode(['command' => ['php', '-v']], JSON_THROW_ON_ERROR),
        );

        $response->assertOk()
            ->assertJsonPath('success.data.workspace', 'docs-feature')
            ->assertJsonPath('success.data.app', 'docs')
            ->assertJsonPath('success.data.container', 'orbit-ws-docs-docs-feature')
            ->assertJsonPath('success.data.command', ['php', '-v'])
            ->assertJsonPath('success.data.exit_code', 0)
            ->assertJsonPath('success.data.stdout', "PHP 8.5.0\n");
    });

    it('returns the underlying exit code in success.data.exit_code with HTTP 200', function (): void {
        $caller = createWorkspaceExecCaller();
        $node = createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'host' => '10.6.0.7']);
        grantWorkspaceExecAccess($caller, $node, ['workspace:exec']);
        createWorkspaceExecFixture($node);
        bindWorkspaceExecControllerShell([
            new RemoteShellResult(exitCode: 9, stdout: '', stderr: "no\n", durationMs: 1),
        ]);

        $response = $this->call(
            'POST',
            '/api/workspaces/docs-feature/exec',
            [],
            [],
            [],
            ['REMOTE_ADDR' => WORKSPACE_EXEC_CALLER_WG_IP, 'CONTENT_TYPE' => 'application/json'],
            json_encode(['command' => ['php', 'artisan', 'test']], JSON_THROW_ON_ERROR),
        );

        $response->assertOk()
            ->assertJsonPath('success.data.exit_code', 9)
            ->assertJsonPath('success.data.stderr', "no\n");
    });

    it('returns 422 validation_failed when the command body is missing or empty', function (): void {
        $caller = createWorkspaceExecCaller();
        $node = createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'host' => '10.6.0.7']);
        grantWorkspaceExecAccess($caller, $node, ['workspace:exec']);
        createWorkspaceExecFixture($node);
        bindWorkspaceExecControllerShell();

        $response = $this->call(
            'POST',
            '/api/workspaces/docs-feature/exec',
            [],
            [],
            [],
            ['REMOTE_ADDR' => WORKSPACE_EXEC_CALLER_WG_IP, 'CONTENT_TYPE' => 'application/json'],
            json_encode(['command' => []], JSON_THROW_ON_ERROR),
        );

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'command');
    });

    it('returns 404 when the targeted workspace is missing', function (): void {
        createWorkspaceExecCaller();
        bindWorkspaceExecControllerShell();

        $response = $this->call(
            'POST',
            '/api/workspaces/missing/exec',
            [],
            [],
            [],
            ['REMOTE_ADDR' => WORKSPACE_EXEC_CALLER_WG_IP, 'CONTENT_TYPE' => 'application/json'],
            json_encode(['command' => ['php', '-v']], JSON_THROW_ON_ERROR),
        );

        $response->assertNotFound()
            ->assertJsonPath('error.code', 'workspace.not_found');
    });

    it('returns 422 workspace.exec_unsupported_runtime when the parent app is not PHP', function (): void {
        $caller = createWorkspaceExecCaller();
        $node = createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'host' => '10.6.0.7']);
        grantWorkspaceExecAccess($caller, $node, ['workspace:exec']);
        createWorkspaceExecFixture($node, ['runtime_kind' => AppRuntimeKind::Static]);
        bindWorkspaceExecControllerShell();

        $response = $this->call(
            'POST',
            '/api/workspaces/docs-feature/exec',
            [],
            [],
            [],
            ['REMOTE_ADDR' => WORKSPACE_EXEC_CALLER_WG_IP, 'CONTENT_TYPE' => 'application/json'],
            json_encode(['command' => ['php', '-v']], JSON_THROW_ON_ERROR),
        );

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'workspace.exec_unsupported_runtime');
    });

    it('returns 422 workspace.exec_container_not_running when preflight reports no such container', function (): void {
        $caller = createWorkspaceExecCaller();
        $node = createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'host' => '10.6.0.7']);
        grantWorkspaceExecAccess($caller, $node, ['workspace:exec']);
        createWorkspaceExecFixture($node);
        bindWorkspaceExecControllerPreflightShell(new RemoteShellResult(
            exitCode: 1,
            stdout: '',
            stderr: 'Error response from daemon: No such container: orbit-ws-docs-docs-feature',
            durationMs: 1,
        ));

        $response = $this->call(
            'POST',
            '/api/workspaces/docs-feature/exec',
            [],
            [],
            [],
            ['REMOTE_ADDR' => WORKSPACE_EXEC_CALLER_WG_IP, 'CONTENT_TYPE' => 'application/json'],
            json_encode(['command' => ['php', '-v']], JSON_THROW_ON_ERROR),
        );

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'workspace.exec_container_not_running');
    });

    it('returns 502 workspace.exec_docker_unavailable when preflight reports the docker daemon is down', function (): void {
        $caller = createWorkspaceExecCaller();
        $node = createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'host' => '10.6.0.7']);
        grantWorkspaceExecAccess($caller, $node, ['workspace:exec']);
        createWorkspaceExecFixture($node);
        bindWorkspaceExecControllerPreflightShell(new RemoteShellResult(
            exitCode: 1,
            stdout: '',
            stderr: "Cannot connect to the Docker daemon at unix:///var/run/docker.sock. Is the docker daemon running?\n",
            durationMs: 1,
        ));

        $response = $this->call(
            'POST',
            '/api/workspaces/docs-feature/exec',
            [],
            [],
            [],
            ['REMOTE_ADDR' => WORKSPACE_EXEC_CALLER_WG_IP, 'CONTENT_TYPE' => 'application/json'],
            json_encode(['command' => ['php', '-v']], JSON_THROW_ON_ERROR),
        );

        $response->assertStatus(502)
            ->assertJsonPath('error.code', 'workspace.exec_docker_unavailable');
    });

    it('returns 502 workspace.exec_node_unreachable when preflight returns an SSH-level failure', function (): void {
        $caller = createWorkspaceExecCaller();
        $node = createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'host' => '10.6.0.7']);
        grantWorkspaceExecAccess($caller, $node, ['workspace:exec']);
        createWorkspaceExecFixture($node);
        bindWorkspaceExecControllerPreflightShell(new RemoteShellResult(
            exitCode: 255,
            stdout: '',
            stderr: "ssh: connect to host 10.6.0.7 port 22: Connection refused\n",
            durationMs: 1,
        ));

        $response = $this->call(
            'POST',
            '/api/workspaces/docs-feature/exec',
            [],
            [],
            [],
            ['REMOTE_ADDR' => WORKSPACE_EXEC_CALLER_WG_IP, 'CONTENT_TYPE' => 'application/json'],
            json_encode(['command' => ['php', '-v']], JSON_THROW_ON_ERROR),
        );

        $response->assertStatus(502)
            ->assertJsonPath('error.code', 'workspace.exec_node_unreachable');
    });

    it('rejects callers without the workspace:exec permission with HTTP 403', function (): void {
        $caller = createWorkspaceExecCaller();
        $node = createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'host' => '10.6.0.7']);
        grantWorkspaceExecAccess($caller, $node, ['workspace:read']);
        createWorkspaceExecFixture($node);
        bindWorkspaceExecControllerShell();

        $response = $this->call(
            'POST',
            '/api/workspaces/docs-feature/exec',
            [],
            [],
            [],
            ['REMOTE_ADDR' => WORKSPACE_EXEC_CALLER_WG_IP, 'CONTENT_TYPE' => 'application/json'],
            json_encode(['command' => ['php', '-v']], JSON_THROW_ON_ERROR),
        );

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.missing_permission', 'workspace:exec');
    });

    it('returns 400 workspace.ambiguous_name when two apps host a workspace with the same name and no ?app= is supplied', function (): void {
        $caller = createWorkspaceExecCaller();
        $node = createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'host' => '10.6.0.7']);
        grantWorkspaceExecAccess($caller, $node, ['workspace:exec']);
        $appA = App::factory()->for($node, 'node')->create(['name' => 'docs', 'path' => '/home/orbit/apps/docs', 'runtime_kind' => AppRuntimeKind::Php]);
        $appB = App::factory()->for($node, 'node')->create(['name' => 'site', 'path' => '/home/orbit/apps/site', 'runtime_kind' => AppRuntimeKind::Php]);
        Workspace::factory()->for($appA, 'app')->create(['name' => 'shared', 'path' => '/home/orbit/apps/docs/.worktrees/shared']);
        Workspace::factory()->for($appB, 'app')->create(['name' => 'shared', 'path' => '/home/orbit/apps/site/.worktrees/shared']);

        $response = $this->call(
            'POST',
            '/api/workspaces/shared/exec',
            [],
            [],
            [],
            ['REMOTE_ADDR' => WORKSPACE_EXEC_CALLER_WG_IP, 'CONTENT_TYPE' => 'application/json'],
            json_encode(['command' => ['php', '-v']], JSON_THROW_ON_ERROR),
        );

        $response->assertStatus(400)
            ->assertJsonPath('error.code', 'workspace.ambiguous_name');
    });

    it('disambiguates with ?app= and executes against the correct workspace', function (): void {
        $caller = createWorkspaceExecCaller();
        $node = createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'host' => '10.6.0.7']);
        grantWorkspaceExecAccess($caller, $node, ['workspace:exec']);
        $appA = App::factory()->for($node, 'node')->create(['name' => 'docs', 'path' => '/home/orbit/apps/docs', 'runtime_kind' => AppRuntimeKind::Php]);
        $appB = App::factory()->for($node, 'node')->create(['name' => 'site', 'path' => '/home/orbit/apps/site', 'runtime_kind' => AppRuntimeKind::Php]);
        Workspace::factory()->for($appA, 'app')->create(['name' => 'shared', 'path' => '/home/orbit/apps/docs/.worktrees/shared']);
        Workspace::factory()->for($appB, 'app')->create(['name' => 'shared', 'path' => '/home/orbit/apps/site/.worktrees/shared']);
        bindWorkspaceExecControllerShell([
            new RemoteShellResult(exitCode: 0, stdout: "ok\n", stderr: '', durationMs: 1),
        ]);

        $response = $this->call(
            'POST',
            '/api/workspaces/shared/exec?app=site',
            [],
            [],
            [],
            ['REMOTE_ADDR' => WORKSPACE_EXEC_CALLER_WG_IP, 'CONTENT_TYPE' => 'application/json'],
            json_encode(['command' => ['php', '-v']], JSON_THROW_ON_ERROR),
        );

        $response->assertOk()
            ->assertJsonPath('success.data.workspace', 'shared')
            ->assertJsonPath('success.data.app', 'site')
            ->assertJsonPath('success.data.container', 'orbit-ws-site-shared');
    });

    it('resolves the workspace from host_cwd via the by-path endpoint and executes the command', function (): void {
        $caller = createWorkspaceExecCaller();
        $node = createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'host' => '10.6.0.7']);
        grantWorkspaceExecAccess($caller, $node, ['workspace:exec']);
        createWorkspaceExecFixture($node);
        bindWorkspaceExecControllerShell([
            new RemoteShellResult(exitCode: 0, stdout: "PHP 8.5.0\n", stderr: '', durationMs: 1),
        ]);

        $response = $this->call(
            'POST',
            '/api/workspaces/exec/by-path',
            [],
            [],
            [],
            ['REMOTE_ADDR' => WORKSPACE_EXEC_CALLER_WG_IP, 'CONTENT_TYPE' => 'application/json'],
            json_encode([
                'host_cwd' => '/home/orbit/apps/docs/.worktrees/docs-feature',
                'command' => ['php', '-v'],
            ], JSON_THROW_ON_ERROR),
        );

        $response->assertOk()
            ->assertJsonPath('success.data.workspace', 'docs-feature')
            ->assertJsonPath('success.data.app', 'docs');
    });

    it('returns 422 validation_failed when the by-path endpoint receives no host_cwd', function (): void {
        createWorkspaceExecCaller();

        $response = $this->call(
            'POST',
            '/api/workspaces/exec/by-path',
            [],
            [],
            [],
            ['REMOTE_ADDR' => WORKSPACE_EXEC_CALLER_WG_IP, 'CONTENT_TYPE' => 'application/json'],
            json_encode(['command' => ['php', '-v']], JSON_THROW_ON_ERROR),
        );

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'host_cwd');
    });

    it('returns 422 validation_failed when the by-path endpoint cannot resolve the host_cwd to a workspace', function (): void {
        $caller = createWorkspaceExecCaller();
        $node = createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'host' => '10.6.0.7']);
        grantWorkspaceExecAccess($caller, $node, ['workspace:exec']);
        createWorkspaceExecFixture($node);

        $response = $this->call(
            'POST',
            '/api/workspaces/exec/by-path',
            [],
            [],
            [],
            ['REMOTE_ADDR' => WORKSPACE_EXEC_CALLER_WG_IP, 'CONTENT_TYPE' => 'application/json'],
            json_encode([
                // Cwd is inside the parent app but not inside any workspace.
                'host_cwd' => '/home/orbit/apps/docs/app/Models',
                'command' => ['php', '-v'],
            ], JSON_THROW_ON_ERROR),
        );

        // An unresolvable cwd is a malformed input, not a missing entity.
        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'host_cwd');
    });
});
