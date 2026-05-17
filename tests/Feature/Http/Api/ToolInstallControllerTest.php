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
        'role' => 'control',
        'host' => TOOL_INSTALL_API_CALLER_WG_IP,
        'wireguard_address' => TOOL_INSTALL_API_CALLER_WG_IP,
    ], $overrides));
}

function grantToolInstallApiAccess(Node $caller, Node $appNode): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
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
            'role' => 'gateway',
            'host' => TOOL_INSTALL_API_CALLER_WG_IP,
            'wireguard_address' => TOOL_INSTALL_API_CALLER_WG_IP,
        ]);
        $node = Node::factory()->create([
            'name' => 'db-install-api-1',
            'role' => 'control',
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

    it('returns node.role_required for postgres on an active explicit node without an active database role', function (): void {
        $caller = createToolInstallApiCallerNode();
        $node = Node::factory()->create([
            'name' => 'db-install-api-1',
            'role' => 'control',
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
        $node = Node::factory()->create(['name' => 'app-install-api-1', 'role' => 'app', 'status' => 'active']);
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

    it('rejects direct API install-time version intent before side effects', function (array $payload): void {
        $caller = createToolInstallApiCallerNode();
        $node = Node::factory()->create(['name' => 'app-install-api-1', 'role' => 'app', 'status' => 'active']);
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
        'version' => [['version' => '1.0.0']],
        'expected_version' => [['expected_version' => '1.0.0']],
        'expected-version' => [['expected-version' => '1.0.0']],
    ]);

    it('requires an explicit target selector even when exactly one app node is visible', function (): void {
        $caller = createToolInstallApiCallerNode();
        $node = Node::factory()->create(['name' => 'app-install-api-1', 'role' => 'app', 'status' => 'active']);
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
