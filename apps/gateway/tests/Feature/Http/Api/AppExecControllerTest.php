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

const APP_EXEC_CALLER_WG_IP = '10.6.0.99';

beforeEach(function (): void {
    config(['orbit.is_gateway' => true]);
});

function createExecControllerCaller(array $overrides = []): Node
{
    $attributes = array_merge([
        'name' => 'caller',
        'host' => APP_EXEC_CALLER_WG_IP,
        'wireguard_address' => APP_EXEC_CALLER_WG_IP,
    ], $overrides);

    return Node::factory()->create($attributes);
}

/**
 * @param  list<string>  $permissions
 */
function grantAppExecAccess(Node $caller, Node $appNode, array $permissions): void
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

function appExecControllerPreflightRunning(): RemoteShellResult
{
    return new RemoteShellResult(exitCode: 0, stdout: "true\n", stderr: '', durationMs: 1);
}

/**
 * @param  list<RemoteShellResult>  $results  Sequence of exec-call results.
 *                                            A preflight "running" result is
 *                                            prepended before every exec
 *                                            call so tests script only the
 *                                            docker exec outcome.
 */
function bindAppExecControllerShell(array $results = []): void
{
    $scripted = [];

    foreach ($results as $result) {
        $scripted[] = appExecControllerPreflightRunning();
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
                return appExecControllerPreflightRunning();
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

function bindAppExecControllerPreflightShell(RemoteShellResult $preflight): void
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

describe('AppExecController', function (): void {
    it('runs a command inside the app runtime container and returns the success envelope', function (): void {
        $caller = createExecControllerCaller();
        $node = createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'host' => '10.6.0.7']);
        grantAppExecAccess($caller, $node, ['app:exec']);
        App::factory()->for($node, 'node')->create([
            'name' => 'docs',
            'path' => '/home/orbit/apps/docs',
            'runtime_kind' => AppRuntimeKind::Php,
        ]);
        bindAppExecControllerShell([
            new RemoteShellResult(exitCode: 0, stdout: "PHP 8.5.0\n", stderr: '', durationMs: 1),
        ]);

        $response = $this->call(
            'POST',
            '/api/apps/docs/exec',
            [],
            [],
            [],
            ['REMOTE_ADDR' => APP_EXEC_CALLER_WG_IP, 'CONTENT_TYPE' => 'application/json'],
            json_encode(['command' => ['php', '-v']], JSON_THROW_ON_ERROR),
        );

        $response->assertOk()
            ->assertJsonPath('success.data.app', 'docs')
            ->assertJsonPath('success.data.container', 'orbit-app-docs')
            ->assertJsonPath('success.data.command', ['php', '-v'])
            ->assertJsonPath('success.data.exit_code', 0)
            ->assertJsonPath('success.data.stdout', "PHP 8.5.0\n");
    });

    it('returns the underlying exit code in success.data.exit_code with HTTP 200', function (): void {
        $caller = createExecControllerCaller();
        $node = createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'host' => '10.6.0.7']);
        grantAppExecAccess($caller, $node, ['app:exec']);
        App::factory()->for($node, 'node')->create([
            'name' => 'docs',
            'path' => '/home/orbit/apps/docs',
            'runtime_kind' => AppRuntimeKind::Php,
        ]);
        bindAppExecControllerShell([
            new RemoteShellResult(exitCode: 7, stdout: '', stderr: "fail\n", durationMs: 1),
        ]);

        $response = $this->call(
            'POST',
            '/api/apps/docs/exec',
            [],
            [],
            [],
            ['REMOTE_ADDR' => APP_EXEC_CALLER_WG_IP, 'CONTENT_TYPE' => 'application/json'],
            json_encode(['command' => ['php', 'artisan', 'migrate']], JSON_THROW_ON_ERROR),
        );

        $response->assertOk()
            ->assertJsonPath('success.data.exit_code', 7)
            ->assertJsonPath('success.data.stderr', "fail\n");
    });

    it('returns 422 validation_failed when the command body is missing or empty', function (): void {
        $caller = createExecControllerCaller();
        $node = createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'host' => '10.6.0.7']);
        grantAppExecAccess($caller, $node, ['app:exec']);
        App::factory()->for($node, 'node')->create([
            'name' => 'docs',
            'path' => '/home/orbit/apps/docs',
            'runtime_kind' => AppRuntimeKind::Php,
        ]);
        bindAppExecControllerShell();

        $response = $this->call(
            'POST',
            '/api/apps/docs/exec',
            [],
            [],
            [],
            ['REMOTE_ADDR' => APP_EXEC_CALLER_WG_IP, 'CONTENT_TYPE' => 'application/json'],
            json_encode(['command' => []], JSON_THROW_ON_ERROR),
        );

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'command');
    });

    it('returns 404 when the targeted app is missing', function (): void {
        createExecControllerCaller();
        bindAppExecControllerShell();

        $response = $this->call(
            'POST',
            '/api/apps/missing/exec',
            [],
            [],
            [],
            ['REMOTE_ADDR' => APP_EXEC_CALLER_WG_IP, 'CONTENT_TYPE' => 'application/json'],
            json_encode(['command' => ['php', '-v']], JSON_THROW_ON_ERROR),
        );

        $response->assertNotFound()
            ->assertJsonPath('error.code', 'app.not_found');
    });

    it('returns 422 app.exec_unsupported_runtime for a static app', function (): void {
        $caller = createExecControllerCaller();
        $node = createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'host' => '10.6.0.7']);
        grantAppExecAccess($caller, $node, ['app:exec']);
        App::factory()->for($node, 'node')->create([
            'name' => 'docs',
            'path' => '/home/orbit/apps/docs',
            'runtime_kind' => AppRuntimeKind::Static,
        ]);
        bindAppExecControllerShell();

        $response = $this->call(
            'POST',
            '/api/apps/docs/exec',
            [],
            [],
            [],
            ['REMOTE_ADDR' => APP_EXEC_CALLER_WG_IP, 'CONTENT_TYPE' => 'application/json'],
            json_encode(['command' => ['php', '-v']], JSON_THROW_ON_ERROR),
        );

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'app.exec_unsupported_runtime');
    });

    it('returns 422 app.exec_container_not_running when preflight reports no such container', function (): void {
        $caller = createExecControllerCaller();
        $node = createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'host' => '10.6.0.7']);
        grantAppExecAccess($caller, $node, ['app:exec']);
        App::factory()->for($node, 'node')->create([
            'name' => 'docs',
            'path' => '/home/orbit/apps/docs',
            'runtime_kind' => AppRuntimeKind::Php,
        ]);
        bindAppExecControllerPreflightShell(new RemoteShellResult(
            exitCode: 1,
            stdout: '',
            stderr: 'Error response from daemon: No such container: orbit-app-docs',
            durationMs: 1,
        ));

        $response = $this->call(
            'POST',
            '/api/apps/docs/exec',
            [],
            [],
            [],
            ['REMOTE_ADDR' => APP_EXEC_CALLER_WG_IP, 'CONTENT_TYPE' => 'application/json'],
            json_encode(['command' => ['php', '-v']], JSON_THROW_ON_ERROR),
        );

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'app.exec_container_not_running');
    });

    it('returns 502 app.exec_docker_unavailable when preflight reports the docker daemon is down', function (): void {
        $caller = createExecControllerCaller();
        $node = createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'host' => '10.6.0.7']);
        grantAppExecAccess($caller, $node, ['app:exec']);
        App::factory()->for($node, 'node')->create([
            'name' => 'docs',
            'path' => '/home/orbit/apps/docs',
            'runtime_kind' => AppRuntimeKind::Php,
        ]);
        bindAppExecControllerPreflightShell(new RemoteShellResult(
            exitCode: 1,
            stdout: '',
            stderr: "Cannot connect to the Docker daemon at unix:///var/run/docker.sock. Is the docker daemon running?\n",
            durationMs: 1,
        ));

        $response = $this->call(
            'POST',
            '/api/apps/docs/exec',
            [],
            [],
            [],
            ['REMOTE_ADDR' => APP_EXEC_CALLER_WG_IP, 'CONTENT_TYPE' => 'application/json'],
            json_encode(['command' => ['php', '-v']], JSON_THROW_ON_ERROR),
        );

        $response->assertStatus(502)
            ->assertJsonPath('error.code', 'app.exec_docker_unavailable');
    });

    it('returns 502 app.exec_node_unreachable when preflight returns an SSH-level failure', function (): void {
        $caller = createExecControllerCaller();
        $node = createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'host' => '10.6.0.7']);
        grantAppExecAccess($caller, $node, ['app:exec']);
        App::factory()->for($node, 'node')->create([
            'name' => 'docs',
            'path' => '/home/orbit/apps/docs',
            'runtime_kind' => AppRuntimeKind::Php,
        ]);
        bindAppExecControllerPreflightShell(new RemoteShellResult(
            exitCode: 255,
            stdout: '',
            stderr: "ssh: connect to host 10.6.0.7 port 22: Connection refused\n",
            durationMs: 1,
        ));

        $response = $this->call(
            'POST',
            '/api/apps/docs/exec',
            [],
            [],
            [],
            ['REMOTE_ADDR' => APP_EXEC_CALLER_WG_IP, 'CONTENT_TYPE' => 'application/json'],
            json_encode(['command' => ['php', '-v']], JSON_THROW_ON_ERROR),
        );

        $response->assertStatus(502)
            ->assertJsonPath('error.code', 'app.exec_node_unreachable');
    });

    it('rejects callers without the app:exec permission with HTTP 403', function (): void {
        $caller = createExecControllerCaller();
        $node = createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'host' => '10.6.0.7']);
        grantAppExecAccess($caller, $node, ['app:read']);
        App::factory()->for($node, 'node')->create([
            'name' => 'docs',
            'path' => '/home/orbit/apps/docs',
            'runtime_kind' => AppRuntimeKind::Php,
        ]);
        bindAppExecControllerShell();

        $response = $this->call(
            'POST',
            '/api/apps/docs/exec',
            [],
            [],
            [],
            ['REMOTE_ADDR' => APP_EXEC_CALLER_WG_IP, 'CONTENT_TYPE' => 'application/json'],
            json_encode(['command' => ['php', '-v']], JSON_THROW_ON_ERROR),
        );

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.missing_permission', 'app:exec');
    });

    it('resolves the app from host_cwd via the by-path endpoint and executes the command', function (): void {
        $caller = createExecControllerCaller();
        $node = createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'host' => '10.6.0.7']);
        grantAppExecAccess($caller, $node, ['app:exec']);
        App::factory()->for($node, 'node')->create([
            'name' => 'docs',
            'path' => '/home/orbit/apps/docs',
            'runtime_kind' => AppRuntimeKind::Php,
        ]);
        bindAppExecControllerShell([
            new RemoteShellResult(exitCode: 0, stdout: "PHP 8.5.0\n", stderr: '', durationMs: 1),
        ]);

        $response = $this->call(
            'POST',
            '/api/apps/exec/by-path',
            [],
            [],
            [],
            ['REMOTE_ADDR' => APP_EXEC_CALLER_WG_IP, 'CONTENT_TYPE' => 'application/json'],
            json_encode([
                'host_cwd' => '/home/orbit/apps/docs/public',
                'command' => ['php', '-v'],
            ], JSON_THROW_ON_ERROR),
        );

        $response->assertOk()
            ->assertJsonPath('success.data.app', 'docs')
            ->assertJsonPath('success.data.container', 'orbit-app-docs');
    });

    it('returns 422 validation_failed when the by-path endpoint receives no host_cwd', function (): void {
        createExecControllerCaller();

        $response = $this->call(
            'POST',
            '/api/apps/exec/by-path',
            [],
            [],
            [],
            ['REMOTE_ADDR' => APP_EXEC_CALLER_WG_IP, 'CONTENT_TYPE' => 'application/json'],
            json_encode(['command' => ['php', '-v']], JSON_THROW_ON_ERROR),
        );

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'host_cwd');
    });

    it('returns 422 validation_failed when the by-path endpoint cannot resolve the host_cwd to any app', function (): void {
        $caller = createExecControllerCaller();
        $node = createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'host' => '10.6.0.7']);
        grantAppExecAccess($caller, $node, ['app:exec']);
        App::factory()->for($node, 'node')->create([
            'name' => 'docs',
            'path' => '/home/orbit/apps/docs',
            'runtime_kind' => AppRuntimeKind::Php,
        ]);

        $response = $this->call(
            'POST',
            '/api/apps/exec/by-path',
            [],
            [],
            [],
            ['REMOTE_ADDR' => APP_EXEC_CALLER_WG_IP, 'CONTENT_TYPE' => 'application/json'],
            json_encode([
                'host_cwd' => '/tmp/somewhere-else',
                'command' => ['php', '-v'],
            ], JSON_THROW_ON_ERROR),
        );

        // An unresolvable cwd is a malformed input, not a missing entity.
        // The gateway-mode CLI returns validation_failed for the equivalent
        // state; the API surface must match.
        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'host_cwd');
    });

    it('refuses to fall through to the parent app when host_cwd resolves into a workspace tree', function (): void {
        $caller = createExecControllerCaller();
        $node = createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'host' => '10.6.0.7']);
        grantAppExecAccess($caller, $node, ['app:exec', 'workspace:exec']);
        $app = App::factory()->for($node, 'node')->create([
            'name' => 'docs',
            'path' => '/home/orbit/apps/docs',
            'runtime_kind' => AppRuntimeKind::Php,
        ]);
        Workspace::factory()->for($app, 'app')->create([
            'name' => 'docs-feature',
            'path' => '/home/orbit/apps/docs/.worktrees/docs-feature',
        ]);

        $response = $this->call(
            'POST',
            '/api/apps/exec/by-path',
            [],
            [],
            [],
            ['REMOTE_ADDR' => APP_EXEC_CALLER_WG_IP, 'CONTENT_TYPE' => 'application/json'],
            json_encode([
                'host_cwd' => '/home/orbit/apps/docs/.worktrees/docs-feature/src',
                'command' => ['php', '-v'],
            ], JSON_THROW_ON_ERROR),
        );

        // Mirror the gateway-mode CLI: workspace-only cwd matches do not
        // resolve as the parent app. The caller is steered toward
        // workspace:exec instead.
        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.workspace', 'docs-feature')
            ->assertJsonPath('error.meta.app', 'docs');
    });
});
