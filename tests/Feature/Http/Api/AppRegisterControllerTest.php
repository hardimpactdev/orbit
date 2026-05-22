<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Contracts\SiteCertificateInstaller;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\SiteCertificateInstallerFake;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(SiteCertificateInstaller::class, new SiteCertificateInstallerFake);
});

const APP_REGISTER_CALLER_WG_IP = '10.6.0.78';

function createAppRegisterCallerNode(array $overrides = []): Node
{
    $attributes = array_merge([
        'name' => 'caller',
        'role' => 'control',
        'host' => APP_REGISTER_CALLER_WG_IP,
        'wireguard_address' => APP_REGISTER_CALLER_WG_IP,
    ], $overrides);

    if ($attributes['role'] === 'gateway') {
        return createTestGatewayNode($attributes);
    }

    return Node::factory()->create($attributes);
}

/**
 * @param  list<string>  $permissions
 */
function grantAppRegisterAccess(Node $caller, Node $appNode, array $permissions = ['app:register']): void
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

describe('AppRegisterController', function (): void {
    it('registers an existing app path for authorized callers', function (): void {
        createTestGatewayNode([
            'name' => 'gateway-1',
            'role' => 'gateway',
        ]);

        $caller = createAppRegisterCallerNode();
        $targetNode = createTestAppHostNode([
            'name' => 'app-1',
            'role' => 'app',
            'tld' => 'test',
            'status' => 'active',
        ]);
        grantAppRegisterAccess($caller, $targetNode);

        $remoteShell = new AppRegisterApiSequencedRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '/usr/sbin/php-fpm8.5', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call('POST', '/api/apps/register', [
            'name' => 'docs',
            'node' => 'app-1',
            'path' => '/home/orbit/apps/docs',
        ], [], [], ['REMOTE_ADDR' => APP_REGISTER_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.result.action', 'adopted')
            ->assertJsonPath('success.data.app.name', 'docs')
            ->assertJsonPath('success.data.app.node', 'app-1')
            ->assertJsonPath('success.data.app.runtime_kind', 'php')
            ->assertJsonPath('success.data.app.worker_enabled', false)
            ->assertJsonPath('success.data.app.worker_config', null)
            ->assertJsonPath('success.meta.node', 'app-1')
            ->assertJsonPath('success.meta.warnings', []);

        expect(App::query()->where('name', 'docs')->exists())->toBeTrue()
            ->and($remoteShell->scripts[0])->toContain("test -d '/home/orbit/apps/docs'");
    });

    it('rejects registration when the caller lacks app:register on the target app node', function (): void {
        createTestGatewayNode([
            'name' => 'gateway-1',
            'role' => 'gateway',
        ]);

        $caller = createAppRegisterCallerNode();
        $targetNode = createTestAppHostNode([
            'name' => 'app-1',
            'role' => 'app',
            'status' => 'active',
        ]);
        grantAppRegisterAccess($caller, $targetNode, ['app:read']);

        $remoteShell = new AppRegisterApiSequencedRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call('POST', '/api/apps/register', [
            'name' => 'docs',
            'node' => 'app-1',
            'path' => '/home/orbit/apps/docs',
        ], [], [], ['REMOTE_ADDR' => APP_REGISTER_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.missing_permission', 'app:register')
            ->assertJsonPath('error.meta.serving_node', 'app-1');

        expect(App::query()->count())->toBe(0)
            ->and($remoteShell->scripts)->toBe([]);
    });

    it('rejects omitted-node registration when the caller cannot access the inferred target app node', function (): void {
        createTestGatewayNode([
            'name' => 'gateway-1',
            'role' => 'gateway',
        ]);

        createAppRegisterCallerNode();
        createTestAppHostNode([
            'name' => 'app-1',
            'role' => 'app',
            'tld' => 'test',
            'status' => 'active',
        ]);

        $remoteShell = new AppRegisterApiSequencedRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call('POST', '/api/apps/register', [
            'name' => 'docs',
            'path' => '/home/orbit/apps/docs',
        ], [], [], ['REMOTE_ADDR' => APP_REGISTER_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.missing_permission', 'app:register')
            ->assertJsonPath('error.meta.serving_node', 'app-1');

        expect(App::query()->count())->toBe(0)
            ->and($remoteShell->scripts)->toBe([]);
    });

    it('rejects production registration when the target node lacks the app-production role', function (): void {
        createTestGatewayNode([
            'name' => 'gateway-1',
            'role' => 'gateway',
        ]);

        $caller = createAppRegisterCallerNode();
        $targetNode = createTestAppHostNode([
            'name' => 'app-1',
            'role' => 'app',
            'tld' => 'test',
            'status' => 'active',
        ]);
        grantAppRegisterAccess($caller, $targetNode);

        $remoteShell = new AppRegisterApiSequencedRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call('POST', '/api/apps/register', [
            'name' => 'docs',
            'node' => 'app-1',
            'path' => '/home/orbit/apps/docs',
            'domain' => 'docs.example.com',
        ], [], [], ['REMOTE_ADDR' => APP_REGISTER_CALLER_WG_IP]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'app.ineligible_node')
            ->assertJsonPath('error.meta.required_role', 'app-production');

        expect(App::query()->count())->toBe(0)
            ->and($remoteShell->scripts)->toBe([]);
    });

    it('allows database-role callers when app:register is granted on the target app node', function (): void {
        createTestGatewayNode([
            'name' => 'gateway-1',
            'role' => 'gateway',
        ]);

        $caller = createAppRegisterCallerNode();
        NodeRoleAssignment::factory()->create([
            'node_id' => $caller->id,
            'role' => 'database',
            'status' => 'active',
        ]);
        $targetNode = createTestAppHostNode([
            'name' => 'app-1',
            'role' => 'app',
            'status' => 'active',
        ]);
        grantAppRegisterAccess($caller, $targetNode);

        $remoteShell = new AppRegisterApiSequencedRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '/usr/sbin/php-fpm8.5', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call('POST', '/api/apps/register', [
            'name' => 'docs',
            'node' => 'app-1',
            'path' => '/home/orbit/apps/docs',
        ], [], [], ['REMOTE_ADDR' => APP_REGISTER_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.result.action', 'adopted')
            ->assertJsonPath('success.data.app.name', 'docs');

        expect(App::query()->where('name', 'docs')->exists())->toBeTrue()
            ->and($remoteShell->scripts[0])->toContain("test -d '/home/orbit/apps/docs'");
    });
});

final class AppRegisterApiSequencedRemoteShell implements RemoteShell
{
    /**
     * @var list<string>
     */
    public array $scripts = [];

    /**
     * @param  list<RemoteShellResult>  $results
     */
    public function __construct(
        private array $results,
    ) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        return array_shift($this->results) ?? new RemoteShellResult(
            exitCode: 0,
            stdout: '',
            stderr: '',
            durationMs: 1,
        );
    }
}
