<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const TOOL_INSTALL_API_CALLER_WG_IP = '10.6.0.98';

function createToolInstallApiCallerNode(array $overrides = []): Node
{
    return Node::factory()->create(array_merge([
        'name' => 'tool-install-api-caller',
        'host' => TOOL_INSTALL_API_CALLER_WG_IP,
        'wireguard_address' => TOOL_INSTALL_API_CALLER_WG_IP,
    ], $overrides));
}

/**
 * @param  list<string>  $permissions
 */
function grantToolInstallApiAccess(Node $caller, Node $appNode, array $permissions = ['*']): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
        'permissions' => json_encode($permissions),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function assignToolInstallApiRole(Node $node, string $role, string $status = 'active'): NodeRoleAssignment
{
    return NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => $role,
        'status' => $status,
    ]);
}

describe('ToolInstallController', function (): void {
    it('allows gateway callers to install postgres on an active database-only node via explicit node', function (): void {
        $caller = Node::factory()->create([
            'name' => 'gateway-install-api-caller',
            'host' => TOOL_INSTALL_API_CALLER_WG_IP,
            'wireguard_address' => TOOL_INSTALL_API_CALLER_WG_IP,
        ]);
        assignToolInstallApiRole($caller, 'gateway');
        $node = Node::factory()->create([
            'name' => 'db-install-api-1',
            'status' => 'active',
        ]);
        assignToolInstallApiRole($node, 'database');
        $shell = new ToolInstallApiRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call('POST', '/api/tools/postgres/install', [
            'node' => 'db-install-api-1',
        ], [], [], ['REMOTE_ADDR' => TOOL_INSTALL_API_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.tool.name', 'postgres')
            ->assertJsonPath('success.data.tool.node', 'db-install-api-1')
            ->assertJsonPath('success.data.tool.state', 'installed');

        expect(NodeTool::query()->where('node_id', $node->id)->where('name', 'postgres')->exists())->toBeTrue()
            ->and($shell->scripts)->toHaveCount(1);
    });

    it('allows database callers to install postgres on themselves with an explicit self grant', function (): void {
        $caller = createToolInstallApiCallerNode([
            'name' => 'database-self-install-api-caller',
            'status' => 'active',
        ]);
        assignToolInstallApiRole($caller, 'database');
        grantToolInstallApiAccess($caller, $caller);
        $shell = new ToolInstallApiRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call('POST', '/api/tools/postgres/install', [
            'node' => 'database-self-install-api-caller',
        ], [], [], ['REMOTE_ADDR' => TOOL_INSTALL_API_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.tool.name', 'postgres')
            ->assertJsonPath('success.data.tool.node', 'database-self-install-api-caller')
            ->assertJsonPath('success.data.tool.state', 'installed');

        expect(NodeTool::query()->where('node_id', $caller->id)->where('name', 'postgres')->exists())->toBeTrue()
            ->and(DB::table('node_access')->count())->toBe(1)
            ->and($shell->scripts)->toHaveCount(1);
    });

    it('does not treat an unassigned caller as gateway tool authority', function (): void {
        Node::factory()->create([
            'name' => 'plain-gateway-install-api-caller',
            'host' => TOOL_INSTALL_API_CALLER_WG_IP,
            'wireguard_address' => TOOL_INSTALL_API_CALLER_WG_IP,
        ]);
        $node = Node::factory()->create([
            'name' => 'db-install-api-1',
            'status' => 'active',
        ]);
        assignToolInstallApiRole($node, 'database');
        $shell = new ToolInstallApiRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call('POST', '/api/tools/postgres/install', [
            'node' => 'db-install-api-1',
        ], [], [], ['REMOTE_ADDR' => TOOL_INSTALL_API_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed');

        expect(NodeTool::query()->count())->toBe(0)
            ->and($shell->scripts)->toBe([]);
    });

    it('returns node.role_required for postgres on an active explicit node without an active database role', function (): void {
        $caller = createToolInstallApiCallerNode();
        $node = Node::factory()->create([
            'name' => 'db-install-api-1',
            'status' => 'active',
        ]);
        assignToolInstallApiRole($node, 'database', 'pending');
        grantToolInstallApiAccess($caller, $node);
        $shell = new ToolInstallApiRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call('POST', '/api/tools/postgres/install', [
            'node' => 'db-install-api-1',
        ], [], [], ['REMOTE_ADDR' => TOOL_INSTALL_API_CALLER_WG_IP]);

        $response->assertStatus(400)
            ->assertJsonPath('error.code', 'node.role_required')
            ->assertJsonPath('error.message', "Tool 'postgres' requires node 'db-install-api-1' to have active role 'database'.")
            ->assertJsonPath('error.meta', [
                'node' => 'db-install-api-1',
                'required_role' => 'database',
                'tool' => 'postgres',
            ]);

        expect(NodeTool::query()->count())->toBe(0)
            ->and($shell->scripts)->toBe([]);
    });

    it('rejects invalid status before row writes or remote shell actions', function (): void {
        $caller = createToolInstallApiCallerNode();
        $node = Node::factory()->create(['name' => 'app-install-api-1', 'status' => 'active']);
        assignToolInstallApiRole($node, 'app-dev');
        grantToolInstallApiAccess($caller, $node);
        $shell = new ToolInstallApiRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call('POST', '/api/tools/redis/install', [
            'node' => 'app-install-api-1',
            'status' => 'foo',
        ], [], [], ['REMOTE_ADDR' => TOOL_INSTALL_API_CALLER_WG_IP]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'status')
            ->assertJsonPath('error.meta.value', 'foo')
            ->assertJsonPath('error.meta.reason', 'unsupported_value');

        expect(NodeTool::query()->count())->toBe(0)
            ->and($shell->scripts)->toBe([]);
    });

    it('rejects update-only version intent before side effects', function (array $payload): void {
        $caller = createToolInstallApiCallerNode();
        $node = Node::factory()->create(['name' => 'app-install-api-1', 'status' => 'active']);
        assignToolInstallApiRole($node, 'app-dev');
        grantToolInstallApiAccess($caller, $node);
        $shell = new ToolInstallApiRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call('POST', '/api/tools/redis/install', [
            'node' => 'app-install-api-1',
            ...$payload,
        ], [], [], ['REMOTE_ADDR' => TOOL_INSTALL_API_CALLER_WG_IP]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.reason', 'unsupported_field');

        expect(NodeTool::query()->count())->toBe(0)
            ->and($shell->scripts)->toBe([]);
    })->with([
        'expected_version' => [['expected_version' => '1.0.0']],
        'expected-version' => [['expected-version' => '1.0.0']],
    ]);

    it('records install-time version and runtime intent before applying the managed tool', function (): void {
        $caller = createToolInstallApiCallerNode();
        $node = Node::factory()->create([
            'name' => 'database-install-api-1',
            'platform' => 'ubuntu_24-04',
            'status' => 'active',
            'wireguard_address' => '10.6.0.44',
        ]);
        assignToolInstallApiRole($node, 'database');
        grantToolInstallApiAccess($caller, $node);
        $shell = new ToolInstallApiRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call('POST', '/api/tools/mysql/install', [
            'node' => 'database-install-api-1',
            'version' => '8.4',
            'runtime' => 'docker-swarm',
            'status' => 'running',
        ], [], [], ['REMOTE_ADDR' => TOOL_INSTALL_API_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.tool.name', 'mysql')
            ->assertJsonPath('success.data.tool.node', 'database-install-api-1')
            ->assertJsonPath('success.data.tool.state', 'running')
            ->assertJsonPath('success.data.tool.instance', 'mysql:8')
            ->assertJsonPath('success.data.tool.version_family', '8')
            ->assertJsonPath('success.data.tool.version', '8.4')
            ->assertJsonPath('success.data.tool.runtime', 'docker-swarm');

        $tool = NodeTool::query()
            ->where('node_id', $node->id)
            ->where('name', 'mysql')
            ->where('instance_key', 'mysql:8')
            ->firstOrFail();

        expect($tool->version_family)->toBe('8')
            ->and($tool->expected_version)->toBe('8.4')
            ->and($tool->runtime)->toBe('docker-swarm')
            ->and($tool->expected_state)->toBe('running')
            ->and($tool->runtime_config['implementation_key'] ?? null)->toBe('docker-swarm/ubuntu')
            ->and($tool->config['endpoints'][0]['host'] ?? null)->toBe('10.6.0.44')
            ->and($tool->config['endpoints'][0]['port'] ?? null)->toBe(3308)
            ->and($shell->scripts)->toHaveCount(1);
    });

    it('rejects unsupported install runtime before row writes or remote shell actions', function (): void {
        $caller = createToolInstallApiCallerNode();
        $node = Node::factory()->create([
            'name' => 'database-install-api-1',
            'platform' => 'ubuntu_24-04',
            'status' => 'active',
        ]);
        assignToolInstallApiRole($node, 'database');
        grantToolInstallApiAccess($caller, $node);
        $shell = new ToolInstallApiRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call('POST', '/api/tools/mysql/install', [
            'node' => 'database-install-api-1',
            'version' => '8',
            'runtime' => 'podman',
        ], [], [], ['REMOTE_ADDR' => TOOL_INSTALL_API_CALLER_WG_IP]);

        $response->assertStatus(400)
            ->assertJsonPath('error.code', 'tool.runtime_unsupported')
            ->assertJsonPath('error.meta.tool', 'mysql')
            ->assertJsonPath('error.meta.runtime', 'podman');

        expect(NodeTool::query()->count())->toBe(0)
            ->and($shell->scripts)->toBe([]);
    });

    it('rejects runtime platform mismatches before row writes or remote shell actions', function (): void {
        $caller = createToolInstallApiCallerNode();
        $node = Node::factory()->create([
            'name' => 'database-install-api-1',
            'platform' => 'macos_15-4',
            'status' => 'active',
        ]);
        assignToolInstallApiRole($node, 'database');
        grantToolInstallApiAccess($caller, $node);
        $shell = new ToolInstallApiRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call('POST', '/api/tools/mysql/install', [
            'node' => 'database-install-api-1',
            'version' => '8',
            'runtime' => 'docker-swarm',
        ], [], [], ['REMOTE_ADDR' => TOOL_INSTALL_API_CALLER_WG_IP]);

        $response->assertStatus(400)
            ->assertJsonPath('error.code', 'tool.runtime_platform_unsupported')
            ->assertJsonPath('error.meta.tool', 'mysql')
            ->assertJsonPath('error.meta.runtime', 'docker-swarm')
            ->assertJsonPath('error.meta.platform', 'macos_15-4');

        expect(NodeTool::query()->count())->toBe(0)
            ->and($shell->scripts)->toBe([]);
    });

    it('requires explicit version selection for multi-version tools before side effects', function (): void {
        $caller = createToolInstallApiCallerNode();
        $node = Node::factory()->create([
            'name' => 'database-install-api-1',
            'platform' => 'ubuntu_24-04',
            'status' => 'active',
        ]);
        assignToolInstallApiRole($node, 'database');
        grantToolInstallApiAccess($caller, $node);
        $shell = new ToolInstallApiRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call('POST', '/api/tools/mysql/install', [
            'node' => 'database-install-api-1',
            'runtime' => 'docker',
        ], [], [], ['REMOTE_ADDR' => TOOL_INSTALL_API_CALLER_WG_IP]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'version')
            ->assertJsonPath('error.meta.reason', 'required');

        expect(NodeTool::query()->count())->toBe(0)
            ->and($shell->scripts)->toBe([]);
    });

    it('requires an explicit target selector even when exactly one app node is visible', function (): void {
        $caller = createToolInstallApiCallerNode();
        $node = Node::factory()->create(['name' => 'app-install-api-1', 'status' => 'active']);
        assignToolInstallApiRole($node, 'app-dev');
        grantToolInstallApiAccess($caller, $node);
        $shell = new ToolInstallApiRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call('POST', '/api/tools/redis/install', [], [], [], ['REMOTE_ADDR' => TOOL_INSTALL_API_CALLER_WG_IP]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.fields', ['target']);

        expect(NodeTool::query()->count())->toBe(0)
            ->and($shell->scripts)->toBe([]);
    });

    it('rejects install when the grant only allows reading tools', function (): void {
        $caller = createToolInstallApiCallerNode();
        $node = Node::factory()->create(['name' => 'app-install-api-1', 'status' => 'active']);
        assignToolInstallApiRole($node, 'app-dev');
        grantToolInstallApiAccess($caller, $node, ['tool:read']);
        $shell = new ToolInstallApiRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call('POST', '/api/tools/redis/install', [
            'node' => 'app-install-api-1',
        ], [], [], ['REMOTE_ADDR' => TOOL_INSTALL_API_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed');

        expect(NodeTool::query()->count())->toBe(0)
            ->and($shell->scripts)->toBe([]);
    });
});

final class ToolInstallApiRecordingShell implements RemoteShell
{
    /**
     * @var list<string>
     */
    public array $scripts = [];

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}
