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

beforeEach(function (): void {});

function createExecControllerCaller(array $overrides = []): Node
{
    $attributes = array_merge([
        'name' => 'caller',
        'host' => APP_EXEC_CALLER_WG_IP,
        'wireguard_address' => APP_EXEC_CALLER_WG_IP], $overrides);

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
        'updated_at' => now()]);
}

/**
 * Bind a remote shell that returns the given scripted results in order.
 *
 * @param  list<RemoteShellResult>  $results
 */
function bindAppExecControllerShell(array $results = []): void
{
    app()->instance(RemoteShell::class, new class($results) implements RemoteShell
    {
        public function __construct(public array $results) {}

        public function run(Node $node, string $script, array $options = []): RemoteShellResult
        {
            $next = array_shift($this->results);

            if ($next instanceof RemoteShellResult) {
                return $next;
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

describe('AppExecController', function (): void {
    it('runs a command on the app host and returns the success envelope', function (): void {
        $caller = createExecControllerCaller();
        $node = createTestAppHostNode(['name' => 'app-1', 'host' => '10.6.0.7']);
        grantAppExecAccess($caller, $node, ['app:exec']);
        App::factory()->for($node, 'node')->create([
            'name' => 'docs',
            'path' => '/home/orbit/apps/docs',
            'php_version' => '8.5',
            'runtime_kind' => AppRuntimeKind::Php]);
        bindAppExecControllerShell([
            new RemoteShellResult(exitCode: 0, stdout: "PHP 8.5.0\n", stderr: '', durationMs: 1)]);

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
            ->assertJsonPath('success.data.php_version', '8.5')
            ->assertJsonPath('success.data.command', ['php', '-v'])
            ->assertJsonPath('success.data.exit_code', 0)
            ->assertJsonPath('success.data.stdout', "PHP 8.5.0\n");
    });

    it('returns the underlying exit code in success.data.exit_code with HTTP 200', function (): void {
        $caller = createExecControllerCaller();
        $node = createTestAppHostNode(['name' => 'app-1', 'host' => '10.6.0.7']);
        grantAppExecAccess($caller, $node, ['app:exec']);
        App::factory()->for($node, 'node')->create([
            'name' => 'docs',
            'path' => '/home/orbit/apps/docs',
            'runtime_kind' => AppRuntimeKind::Php]);
        bindAppExecControllerShell([
            new RemoteShellResult(exitCode: 7, stdout: '', stderr: "fail\n", durationMs: 1)]);

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
        $node = createTestAppHostNode(['name' => 'app-1', 'host' => '10.6.0.7']);
        grantAppExecAccess($caller, $node, ['app:exec']);
        App::factory()->for($node, 'node')->create([
            'name' => 'docs',
            'path' => '/home/orbit/apps/docs',
            'runtime_kind' => AppRuntimeKind::Php]);
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
        $node = createTestAppHostNode(['name' => 'app-1', 'host' => '10.6.0.7']);
        grantAppExecAccess($caller, $node, ['app:exec']);
        App::factory()->for($node, 'node')->create([
            'name' => 'docs',
            'path' => '/home/orbit/apps/docs',
            'runtime_kind' => AppRuntimeKind::Static]);
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

    it('rejects callers without the app:exec permission with HTTP 403', function (): void {
        $caller = createExecControllerCaller();
        $node = createTestAppHostNode(['name' => 'app-1', 'host' => '10.6.0.7']);
        grantAppExecAccess($caller, $node, ['app:read']);
        App::factory()->for($node, 'node')->create([
            'name' => 'docs',
            'path' => '/home/orbit/apps/docs',
            'runtime_kind' => AppRuntimeKind::Php]);
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
        $node = createTestAppHostNode(['name' => 'app-1', 'host' => '10.6.0.7']);
        grantAppExecAccess($caller, $node, ['app:exec']);
        App::factory()->for($node, 'node')->create([
            'name' => 'docs',
            'path' => '/home/orbit/apps/docs',
            'php_version' => '8.5',
            'runtime_kind' => AppRuntimeKind::Php]);
        bindAppExecControllerShell([
            new RemoteShellResult(exitCode: 0, stdout: "PHP 8.5.0\n", stderr: '', durationMs: 1)]);

        $response = $this->call(
            'POST',
            '/api/apps/exec/by-path',
            [],
            [],
            [],
            ['REMOTE_ADDR' => APP_EXEC_CALLER_WG_IP, 'CONTENT_TYPE' => 'application/json'],
            json_encode([
                'host_cwd' => '/home/orbit/apps/docs/public',
                'command' => ['php', '-v']], JSON_THROW_ON_ERROR),
        );

        $response->assertOk()
            ->assertJsonPath('success.data.app', 'docs')
            ->assertJsonPath('success.data.php_version', '8.5');
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
        $node = createTestAppHostNode(['name' => 'app-1', 'host' => '10.6.0.7']);
        grantAppExecAccess($caller, $node, ['app:exec']);
        App::factory()->for($node, 'node')->create([
            'name' => 'docs',
            'path' => '/home/orbit/apps/docs',
            'runtime_kind' => AppRuntimeKind::Php]);

        $response = $this->call(
            'POST',
            '/api/apps/exec/by-path',
            [],
            [],
            [],
            ['REMOTE_ADDR' => APP_EXEC_CALLER_WG_IP, 'CONTENT_TYPE' => 'application/json'],
            json_encode([
                'host_cwd' => '/tmp/somewhere-else',
                'command' => ['php', '-v']], JSON_THROW_ON_ERROR),
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
        $node = createTestAppHostNode(['name' => 'app-1', 'host' => '10.6.0.7']);
        grantAppExecAccess($caller, $node, ['app:exec', 'workspace:exec']);
        $app = App::factory()->for($node, 'node')->create([
            'name' => 'docs',
            'path' => '/home/orbit/apps/docs',
            'runtime_kind' => AppRuntimeKind::Php]);
        Workspace::factory()->for($app, 'app')->create([
            'name' => 'docs-feature',
            'path' => '/home/orbit/apps/docs/.worktrees/docs-feature']);

        $response = $this->call(
            'POST',
            '/api/apps/exec/by-path',
            [],
            [],
            [],
            ['REMOTE_ADDR' => APP_EXEC_CALLER_WG_IP, 'CONTENT_TYPE' => 'application/json'],
            json_encode([
                'host_cwd' => '/home/orbit/apps/docs/.worktrees/docs-feature/src',
                'command' => ['php', '-v']], JSON_THROW_ON_ERROR),
        );

        // Mirror the gateway-mode CLI: workspace-only cwd matches do not
        // resolve as the parent app. The caller is steered toward
        // workspace:exec instead.
        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.workspace', 'docs-feature')
            ->assertJsonPath('error.meta.app', 'docs');
    });

    it('uses the version-matched host php path when running the command', function (): void {
        $caller = createExecControllerCaller();
        $node = createTestAppHostNode(['name' => 'app-1', 'host' => '10.6.0.7']);
        grantAppExecAccess($caller, $node, ['app:exec']);

        $capturedScript = null;
        app()->instance(RemoteShell::class, new class($capturedScript) implements RemoteShell
        {
            public ?string $capturedScript = null;

            public function run(Node $node, string $script, array $options = []): RemoteShellResult
            {
                $this->capturedScript = $script;

                return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
            }
        });

        App::factory()->for($node, 'node')->create([
            'name' => 'docs',
            'path' => '/home/orbit/apps/docs',
            'php_version' => '8.4',
            'runtime_kind' => AppRuntimeKind::Php]);

        $shell = app(RemoteShell::class);

        $this->call(
            'POST',
            '/api/apps/docs/exec',
            [],
            [],
            [],
            ['REMOTE_ADDR' => APP_EXEC_CALLER_WG_IP, 'CONTENT_TYPE' => 'application/json'],
            json_encode(['command' => ['php', '-v']], JSON_THROW_ON_ERROR),
        );

        expect($shell->capturedScript)
            ->toContain('/opt/orbit/php/')
            ->toContain("'8.4'")
            ->toContain("'sudo'");
    });
});
