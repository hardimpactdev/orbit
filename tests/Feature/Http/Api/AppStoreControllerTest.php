<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Contracts\SiteCertificateInstaller;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\ProxyRoute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\SiteCertificateInstallerFake;

uses(RefreshDatabase::class);

const APP_STORE_CALLER_WG_IP = '10.6.0.77';

function createAppStoreCallerNode(array $overrides = []): Node
{
    return Node::factory()->create(array_merge([
        'name' => 'caller',
        'role' => 'control',
        'host' => APP_STORE_CALLER_WG_IP,
        'wireguard_address' => APP_STORE_CALLER_WG_IP,
    ], $overrides));
}

function grantAppStoreAccess(Node $caller, Node $appNode): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function assignAppStoreRole(Node $node, string $role, string $status = 'active', array $settings = []): NodeRoleAssignment
{
    return NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => $role,
        'status' => $status,
        'settings' => $settings,
    ]);
}

describe('AppStoreController', function (): void {
    it('creates app source and registry intent for authorized callers', function (): void {
        $caller = createAppStoreCallerNode();
        $targetNode = Node::factory()->create([
            'name' => 'app-1',
            'role' => 'app',
            'tld' => 'test',
            'status' => 'active',
        ]);
        assignAppStoreRole($targetNode, 'app-development', settings: ['tld' => 'test']);
        grantAppStoreAccess($caller, $targetNode);

        $remoteShell = new AppStoreRecordingRemoteShell;
        app()->instance(RemoteShell::class, $remoteShell);
        app()->instance(SiteCertificateInstaller::class, new SiteCertificateInstallerFake);

        $response = $this->call('POST', '/api/apps', [
            'name' => 'docs',
            'node' => 'app-1',
            'root' => 'public',
            'php_version' => '8.5',
        ], [], [], ['REMOTE_ADDR' => APP_STORE_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.result.action', 'created')
            ->assertJsonPath('success.data.app.name', 'docs')
            ->assertJsonPath('success.data.app.node', 'app-1')
            ->assertJsonPath('success.meta.warnings', []);

        expect(App::query()->where('name', 'docs')->exists())->toBeTrue()
            ->and($remoteShell->runs)->toHaveCount(4);
    });

    it('rejects app creation when the caller cannot access the target app node', function (): void {
        createAppStoreCallerNode();
        $targetNode = Node::factory()->create([
            'name' => 'app-1',
            'role' => 'app',
            'status' => 'active',
        ]);
        assignAppStoreRole($targetNode, 'app-development', settings: ['tld' => 'test']);

        $remoteShell = new AppStoreRecordingRemoteShell;
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call('POST', '/api/apps', [
            'name' => 'docs',
            'node' => 'app-1',
        ], [], [], ['REMOTE_ADDR' => APP_STORE_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed');

        expect(App::query()->count())->toBe(0)
            ->and($remoteShell->runs)->toBe([]);
    });

    it('rejects database callers before remote work even when the legacy role shadow is control', function (): void {
        $caller = createAppStoreCallerNode();
        assignAppStoreRole($caller, 'database');
        $targetNode = Node::factory()->create([
            'name' => 'app-1',
            'role' => 'app',
            'tld' => 'test',
            'status' => 'active',
        ]);
        assignAppStoreRole($targetNode, 'app-development', settings: ['tld' => 'test']);
        grantAppStoreAccess($caller, $targetNode);

        $remoteShell = new AppStoreRecordingRemoteShell;
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call('POST', '/api/apps', [
            'name' => 'docs',
            'node' => 'app-1',
        ], [], [], ['REMOTE_ADDR' => APP_STORE_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'caller_role_not_allowed')
            ->assertJsonPath('error.meta.caller_role', 'database');

        expect(App::query()->count())->toBe(0)
            ->and($remoteShell->runs)->toBe([]);
    });

    it('rejects app creation before remote work when the proxy route domain is already registered', function (): void {
        $caller = createAppStoreCallerNode();
        $targetNode = Node::factory()->create([
            'name' => 'app-1',
            'role' => 'app',
            'tld' => 'test',
            'status' => 'active',
        ]);
        assignAppStoreRole($targetNode, 'app-development', settings: ['tld' => 'test']);
        grantAppStoreAccess($caller, $targetNode);

        ProxyRoute::query()->create([
            'node_id' => $targetNode->id,
            'domain' => 'docs.test',
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'source_hash' => str_repeat('a', 64),
        ]);

        $remoteShell = new AppStoreRecordingRemoteShell;
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call('POST', '/api/apps', [
            'name' => 'docs',
            'node' => 'app-1',
        ], [], [], ['REMOTE_ADDR' => APP_STORE_CALLER_WG_IP]);

        $response->assertConflict()
            ->assertJsonPath('error.code', 'proxy.domain_conflict')
            ->assertJsonPath('error.meta.domain', 'docs.test');

        expect(App::query()->count())->toBe(0)
            ->and($remoteShell->runs)->toBe([]);
    });

    it('reports github transport when github source creation fails', function (): void {
        $caller = createAppStoreCallerNode();
        $targetNode = Node::factory()->create([
            'name' => 'app-1',
            'role' => 'app',
            'status' => 'active',
        ]);
        assignAppStoreRole($targetNode, 'app-development', settings: ['tld' => 'test']);
        grantAppStoreAccess($caller, $targetNode);

        $remoteShell = new AppStoreRecordingRemoteShell(new RemoteShellResult(
            exitCode: 128,
            stdout: '',
            stderr: "permission denied\n",
            durationMs: 5,
        ));
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call('POST', '/api/apps', [
            'name' => 'docs',
            'node' => 'app-1',
            'repository' => 'hardimpact/docs',
        ], [], [], ['REMOTE_ADDR' => APP_STORE_CALLER_WG_IP]);

        $response->assertServerError()
            ->assertJsonPath('error.code', 'app.source_creation_failed')
            ->assertJsonPath('error.meta.reason', 'permission denied')
            ->assertJsonPath('error.meta.transport', 'github');

        expect(App::query()->where('name', 'docs')->exists())->toBeFalse()
            ->and($remoteShell->runs[0]['script'])->toContain("gh repo clone 'hardimpact/docs'");
    });
});

final class AppStoreRecordingRemoteShell implements RemoteShell
{
    /**
     * @var list<array{node: int|null, script: string, options: array<string, mixed>}>
     */
    public array $runs = [];

    public function __construct(
        private readonly ?RemoteShellResult $result = null,
    ) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->runs[] = [
            'node' => $node->id,
            'script' => $script,
            'options' => $options,
        ];

        return $this->result ?? new RemoteShellResult(
            exitCode: 0,
            stdout: '',
            stderr: '',
            durationMs: 1,
        );
    }
}
