<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    bind_tool_script_dispatcher_to_remote_shell();
});

const TOOL_UPDATE_API_CALLER_WG_IP = '10.6.0.93';

function tool_update_api_server_headers(array $overrides = []): array
{
    return [
        'REMOTE_ADDR' => TOOL_UPDATE_API_CALLER_WG_IP,
        ...$overrides,
    ];
}

function createToolUpdateApiCallerNode(array $overrides = []): Node
{
    return Node::factory()->create(array_merge([
        'name' => 'tool-update-api-caller',
        'host' => TOOL_UPDATE_API_CALLER_WG_IP,
        'wireguard_address' => TOOL_UPDATE_API_CALLER_WG_IP,
    ], $overrides));
}

function assignToolUpdateApiRole(Node $node, string $role): void
{
    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => $role,
        'status' => 'active',
    ]);
}

function grantToolUpdateApiAccess(Node $caller, Node $node): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $node->id,
        'permissions' => json_encode(['tool:update'], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function assert_tool_update_api_bulk_did_not_mutate(ToolUpdateApiRecordingShell $shell, NodeTool ...$tools): void
{
    foreach ($tools as $tool) {
        $expectedAttributes = $tool->getAttributes();
        $freshTool = $tool->fresh();

        assert(
            $freshTool instanceof NodeTool,
            description: 'Expected the selected tool row to still exist.',
        );

        $actualAttributes = $freshTool->getAttributes();

        ksort($expectedAttributes);
        ksort($actualAttributes);

        expect($actualAttributes)->toBe($expectedAttributes);
    }

    expect($shell->scripts)->toBeEmpty();
}

it('updates host capability expected versions without service instance fields', function (): void {
    $caller = createToolUpdateApiCallerNode();
    $node = Node::factory()->create(['name' => 'app-update-api-1', 'status' => 'active']);
    assignToolUpdateApiRole($node, 'app-dev');
    grantToolUpdateApiAccess($caller, $node);
    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'php-cli',
        'expected_version' => '8.4',
    ]);
    $shell = new ToolUpdateApiRecordingShell;
    app()->instance(RemoteShell::class, $shell);

    $response = $this->call(
        'POST',
        '/api/tools/php-cli/update',
        [
            'node' => 'app-update-api-1',
            'version' => '8.5',
        ],
        [],
        [],
        tool_update_api_server_headers(),
    );

    $response
        ->assertOk()
        ->assertJsonPath('success.data.tool.name', 'php-cli')
        ->assertJsonPath('success.data.tool.version', '8.5');

    $tool = NodeTool::query()->where('name', 'php-cli')->firstOrFail();

    expect($tool->expected_version)
        ->toBe('8.5')
        ->and($tool->getAttributes())
        ->not
        ->toHaveKeys(['instance_key', 'version_family', 'runtime', 'runtime_config'])
        ->and($shell->scripts)
        ->toHaveCount(1);
});

it('updates a host capability through an instance target', function (): void {
    $caller = createToolUpdateApiCallerNode();
    $node = Node::factory()->create(['name' => 'app-update-api-1', 'status' => 'active']);
    assignToolUpdateApiRole($node, 'app-dev');
    grantToolUpdateApiAccess($caller, $node);
    $app = App::factory()->create(['name' => 'docs']);
    Instance::factory()->for($app)->create([
        'name' => 'development',
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $node->id,
            path: '/home/orbit/apps/docs',
            document_root: 'public',
            domain: 'docs.test',
        ),
    ]);
    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'php-cli',
        'expected_version' => '8.4',
    ]);
    $shell = new ToolUpdateApiRecordingShell;
    app()->instance(RemoteShell::class, $shell);

    $response = $this->call(
        'POST',
        '/api/tools/php-cli/update',
        [
            'instance' => 'docs.development',
            'version' => '8.5',
        ],
        [],
        [],
        tool_update_api_server_headers(),
    );

    $response
        ->assertOk()
        ->assertJsonPath('success.data.tool.node', 'app-update-api-1')
        ->assertJsonPath('success.data.tool.version', '8.5');

    expect($shell->scripts)->toHaveCount(1);
});

it('limits bulk updates to the selected instance node', function (): void {
    $caller = createToolUpdateApiCallerNode();
    $targetNode = Node::factory()->create(['name' => 'app-update-api-1', 'status' => 'active']);
    $otherNode = Node::factory()->create(['name' => 'app-update-api-2', 'status' => 'active']);
    assignToolUpdateApiRole($targetNode, 'app-dev');
    assignToolUpdateApiRole($otherNode, 'app-dev');
    grantToolUpdateApiAccess($caller, $targetNode);
    $app = App::factory()->create(['name' => 'docs']);
    Instance::factory()->for($app)->create([
        'name' => 'development',
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $targetNode->id,
            path: '/home/orbit/apps/docs',
            document_root: 'public',
            domain: 'docs.test',
        ),
    ]);
    NodeTool::factory()->create([
        'node_id' => $targetNode->id,
        'name' => 'node-exporter',
        'expected_version' => 'old',
    ]);
    $otherTool = NodeTool::factory()->create([
        'node_id' => $otherNode->id,
        'name' => 'node-exporter',
        'expected_version' => 'old',
    ]);
    $shell = new ToolUpdateApiRecordingShell;
    app()->instance(RemoteShell::class, $shell);

    $response = $this->call(
        'POST',
        '/api/tools/update',
        ['instance' => 'docs.development'],
        [],
        [],
        tool_update_api_server_headers(),
    );

    $response
        ->assertOk()
        ->assertJsonCount(1, 'success.data.updated')
        ->assertJsonPath('success.data.updated.0.node', 'app-update-api-1');

    expect($shell->scripts)
        ->toHaveCount(1)
        ->and($otherTool->fresh()->expected_version)
        ->toBe('old');
});

it('limits bulk updates to the explicitly selected node for an authorized peer', function (): void {
    $caller = createToolUpdateApiCallerNode();
    $targetNode = Node::factory()->appDev()->create(['name' => 'app-update-api-1']);
    $otherNode = Node::factory()->appDev()->create(['name' => 'app-update-api-2']);
    grantToolUpdateApiAccess($caller, $targetNode);
    $targetTool = NodeTool::factory()->create([
        'node_id' => $targetNode->id,
        'name' => 'node-exporter',
        'expected_version' => 'old',
    ]);
    $otherTool = NodeTool::factory()->create([
        'node_id' => $otherNode->id,
        'name' => 'node-exporter',
        'expected_version' => 'old',
    ]);
    $shell = new ToolUpdateApiRecordingShell;
    app()->instance(RemoteShell::class, $shell);

    $response = $this->call(
        'POST',
        '/api/tools/update',
        ['node' => $targetNode->name],
        [],
        [],
        tool_update_api_server_headers(),
    );

    $response
        ->assertOk()
        ->assertJsonCount(1, 'success.data.updated')
        ->assertJsonPath('success.data.updated.0.node', $targetNode->name);

    expect($targetTool->fresh()->expected_version)
        ->not
        ->toBe('old')
        ->and($otherTool->fresh()->expected_version)
        ->toBe('old')
        ->and($shell->scripts)
        ->toHaveCount(1);
});

it('allows the gateway to bulk update an explicitly selected tool-host node', function (): void {
    $gateway = createToolUpdateApiCallerNode();
    assignToolUpdateApiRole($gateway, role: 'gateway');
    $targetNode = Node::factory()->appDev()->create(['name' => 'app-update-api-1']);
    $tool = NodeTool::factory()->create([
        'node_id' => $targetNode->id,
        'name' => 'node-exporter',
        'expected_version' => 'old',
    ]);
    $shell = new ToolUpdateApiRecordingShell;
    app()->instance(RemoteShell::class, $shell);

    $response = $this->call(
        'POST',
        '/api/tools/update',
        ['node' => $targetNode->name],
        [],
        [],
        tool_update_api_server_headers(),
    );

    $response
        ->assertOk()
        ->assertJsonPath('success.data.updated.0.node', $targetNode->name);

    expect($tool->fresh()->expected_version)
        ->not
        ->toBe('old')
        ->and($shell->scripts)
        ->toHaveCount(1);
});

it('rejects missing and invalid bulk update selectors before mutation', function (
    array $payload,
    ?string $field,
): void {
    $caller = createToolUpdateApiCallerNode();
    $node = Node::factory()->appDev()->create(['name' => 'app-update-api-1']);
    grantToolUpdateApiAccess($caller, $node);
    $tool = NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'node-exporter',
        'expected_version' => 'old',
    ]);
    $shell = new ToolUpdateApiRecordingShell;
    app()->instance(RemoteShell::class, $shell);

    $response = $this->call(
        'POST',
        '/api/tools/update',
        $payload,
        [],
        [],
        tool_update_api_server_headers(),
    );

    $response
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed');

    $response->assertJsonPath(
        $field === null ? 'error.meta.fields.0' : 'error.meta.field',
        $field ?? 'target',
    );

    assert_tool_update_api_bulk_did_not_mutate($shell, $tool);
})->with([
    'missing selector' => [[], null],
    'invalid node selector' => [['node' => 'missing-node'], 'node'],
    'invalid instance selector' => [['instance' => 'missing.development'], 'instance'],
]);

it('rejects conflicting bulk update selectors before mutation', function (): void {
    $caller = createToolUpdateApiCallerNode();
    $selectedNode = Node::factory()->appDev()->create(['name' => 'app-update-api-1']);
    $instanceNode = Node::factory()->appDev()->create(['name' => 'app-update-api-2']);
    grantToolUpdateApiAccess($caller, $selectedNode);
    grantToolUpdateApiAccess($caller, $instanceNode);
    $app = App::factory()->create(['name' => 'docs']);
    Instance::factory()->for($app)->create([
        'name' => 'development',
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $instanceNode->id,
            path: '/home/orbit/apps/docs',
            document_root: 'public',
            domain: 'docs.test',
        ),
    ]);
    $selectedTool = NodeTool::factory()->create([
        'node_id' => $selectedNode->id,
        'name' => 'node-exporter',
        'expected_version' => 'old',
    ]);
    $instanceTool = NodeTool::factory()->create([
        'node_id' => $instanceNode->id,
        'name' => 'node-exporter',
        'expected_version' => 'old',
    ]);
    $shell = new ToolUpdateApiRecordingShell;
    app()->instance(RemoteShell::class, $shell);

    $response = $this->call(
        'POST',
        '/api/tools/update',
        [
            'node' => $selectedNode->name,
            'instance' => 'docs.development',
        ],
        [],
        [],
        tool_update_api_server_headers(),
    );

    $response
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.meta.field', 'instance');

    assert_tool_update_api_bulk_did_not_mutate($shell, $selectedTool, $instanceTool);
});

it('rejects an unauthorized bulk update target before mutation', function (): void {
    $caller = createToolUpdateApiCallerNode();
    $visibleNode = Node::factory()->appDev()->create(['name' => 'app-update-api-visible']);
    $unauthorizedNode = Node::factory()->appDev()->create(['name' => 'app-update-api-hidden']);
    grantToolUpdateApiAccess($caller, $visibleNode);
    $visibleTool = NodeTool::factory()->create([
        'node_id' => $visibleNode->id,
        'name' => 'node-exporter',
        'expected_version' => 'old',
    ]);
    $unauthorizedTool = NodeTool::factory()->create([
        'node_id' => $unauthorizedNode->id,
        'name' => 'node-exporter',
        'expected_version' => 'old',
    ]);
    $shell = new ToolUpdateApiRecordingShell;
    app()->instance(RemoteShell::class, $shell);

    $response = $this->call(
        'POST',
        '/api/tools/update',
        ['node' => $unauthorizedNode->name],
        [],
        [],
        tool_update_api_server_headers(),
    );

    $response
        ->assertForbidden()
        ->assertJsonPath('error.code', 'authorization_failed')
        ->assertJsonPath('error.meta.node', $unauthorizedNode->name);

    assert_tool_update_api_bulk_did_not_mutate($shell, $visibleTool, $unauthorizedTool);
});

it('rejects a gateway instance target whose active node is not a tool host', function (): void {
    $gateway = createToolUpdateApiCallerNode();
    assignToolUpdateApiRole($gateway, role: 'gateway');
    $rolelessNode = Node::factory()->create(['name' => 'roleless-instance-node']);
    $app = App::factory()->create(['name' => 'docs']);
    Instance::factory()->for($app)->create([
        'name' => 'development',
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $rolelessNode->id,
            path: '/home/orbit/apps/docs',
            document_root: 'public',
            domain: 'docs.test',
        ),
    ]);
    $tool = NodeTool::factory()->create([
        'node_id' => $rolelessNode->id,
        'name' => 'node-exporter',
        'expected_version' => 'old',
    ]);
    $shell = new ToolUpdateApiRecordingShell;
    app()->instance(RemoteShell::class, $shell);

    $response = $this->call(
        'POST',
        '/api/tools/update',
        ['instance' => 'docs.development'],
        [],
        [],
        tool_update_api_server_headers(),
    );

    $response
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.meta.field', 'instance');

    assert_tool_update_api_bulk_did_not_mutate($shell, $tool);
});

it('requires agent-push transport before running tool update scripts', function (): void {
    $caller = createToolUpdateApiCallerNode();
    $node = Node::factory()->create(['name' => 'app-update-api-1', 'status' => 'active']);
    assignToolUpdateApiRole($node, 'app-dev');
    grantToolUpdateApiAccess($caller, $node);
    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'php-cli',
        'expected_version' => '8.4',
    ]);
    $shell = new ToolUpdateApiRecordingShell;
    app()->instance(RemoteShell::class, $shell);
    bind_unavailable_tool_script_dispatcher();

    $response = $this->call(
        'POST',
        '/api/tools/php-cli/update',
        [
            'node' => 'app-update-api-1',
            'version' => '8.5',
        ],
        [],
        [],
        ['REMOTE_ADDR' => TOOL_UPDATE_API_CALLER_WG_IP],
    );

    $response
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'node.agent_unreachable')
        ->assertJsonPath('error.meta.reason', 'agent_push_unavailable')
        ->assertJsonPath('error.meta.node', 'app-update-api-1');

    expect($shell->scripts)->toBeEmpty();
});

it('does not update database and cache services through tool updates', function (string $tool): void {
    $caller = createToolUpdateApiCallerNode();
    $node = Node::factory()->create(['name' => 'app-update-api-1', 'status' => 'active']);
    assignToolUpdateApiRole($node, 'app-dev');
    grantToolUpdateApiAccess($caller, $node);
    $shell = new ToolUpdateApiRecordingShell;
    app()->instance(RemoteShell::class, $shell);

    $response = $this->call(
        'POST',
        "/api/tools/{$tool}/update",
        [
            'node' => 'app-update-api-1',
            'version' => '8',
        ],
        [],
        [],
        ['REMOTE_ADDR' => TOOL_UPDATE_API_CALLER_WG_IP],
    );

    $response
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'tool.unsupported_action')
        ->assertJsonPath('error.meta.tool', $tool)
        ->assertJsonPath('error.meta.action', 'update');

    expect(NodeTool::query()->count())->toBe(0)->and($shell->scripts)->toBe([]);
})->with([
    'mysql',
    'postgres',
    'redis',
    'valkey',
]);

it('rejects service-style values as invalid instance selectors', function (): void {
    $caller = createToolUpdateApiCallerNode();
    $node = Node::factory()->create(['name' => 'app-update-api-1', 'status' => 'active']);
    assignToolUpdateApiRole($node, 'app-dev');
    grantToolUpdateApiAccess($caller, $node);
    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'php-cli',
    ]);
    $shell = new ToolUpdateApiRecordingShell;
    app()->instance(RemoteShell::class, $shell);

    $response = $this->call(
        'POST',
        '/api/tools/php-cli/update',
        [
            'node' => 'app-update-api-1',
            'instance' => 'php-cli:8.5',
        ],
        [],
        [],
        ['REMOTE_ADDR' => TOOL_UPDATE_API_CALLER_WG_IP],
    );

    $response
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.meta.field', 'instance');

    expect($shell->scripts)->toBe([]);
});

final class ToolUpdateApiRecordingShell implements RemoteShell
{
    /**
     * @var list<string>
     */
    public array $scripts = [];

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}
