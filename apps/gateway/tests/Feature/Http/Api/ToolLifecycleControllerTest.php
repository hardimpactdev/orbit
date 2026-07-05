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

const TOOL_LIFECYCLE_API_CALLER_WG_IP = '10.6.0.94';

function tool_lifecycle_api_server_headers(array $overrides = []): array
{
    return [
        'HTTP_X_ORBIT_NODE_TRANSPORT_PREFERENCE' => 'transitional-ssh-fallback',
        'REMOTE_ADDR' => TOOL_LIFECYCLE_API_CALLER_WG_IP,
        ...$overrides,
    ];
}

function createToolLifecycleApiCallerNode(array $overrides = []): Node
{
    return Node::factory()->create(array_merge([
        'name' => 'tool-lifecycle-api-caller',
        'host' => TOOL_LIFECYCLE_API_CALLER_WG_IP,
        'wireguard_address' => TOOL_LIFECYCLE_API_CALLER_WG_IP,
    ], $overrides));
}

function assignToolLifecycleApiRole(Node $node, string $role): void
{
    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => $role,
        'status' => 'active',
    ]);
}

/**
 * @param  list<string>  $permissions
 */
function grantToolLifecycleApiAccess(Node $caller, Node $node, array $permissions): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $node->id,
        'permissions' => json_encode($permissions, JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('dispatches orbstack lifecycle scripts through the remote shell', function (string $action): void {
    $caller = createToolLifecycleApiCallerNode();
    $node = Node::factory()->create([
        'name' => 'mac-1',
        'platform' => 'macos_15-4',
        'status' => 'active',
    ]);
    assignToolLifecycleApiRole($node, 'app-dev');
    grantToolLifecycleApiAccess($caller, $node, ["tool:{$action}"]);
    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'orbstack',
    ]);
    $shell = new ToolLifecycleApiRecordingShell;
    app()->instance(RemoteShell::class, $shell);

    $response = $this->call(
        'POST',
        "/api/tools/orbstack/{$action}",
        ['node' => 'mac-1'],
        [],
        [],
        tool_lifecycle_api_server_headers(),
    );

    $response
        ->assertOk()
        ->assertJsonPath('success.data.tool.name', 'orbstack')
        ->assertJsonPath('success.data.tool.node', 'mac-1')
        ->assertJsonPath('success.data.tool.action', $action);

    expect($shell->scripts)
        ->toHaveCount(1)
        ->and($shell->scripts[0])
        ->toContain("orbctl {$action}");
})->with([
    'start',
    'stop',
    'restart',
]);

it('requires explicit transitional ssh fallback before running lifecycle scripts', function (): void {
    $caller = createToolLifecycleApiCallerNode();
    $node = Node::factory()->create([
        'name' => 'mac-1',
        'platform' => 'macos_15-4',
        'status' => 'active',
    ]);
    assignToolLifecycleApiRole($node, 'app-dev');
    grantToolLifecycleApiAccess($caller, $node, ['tool:start']);
    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'orbstack',
    ]);
    $shell = new ToolLifecycleApiRecordingShell;
    app()->instance(RemoteShell::class, $shell);

    $response = $this->call(
        'POST',
        '/api/tools/orbstack/start',
        ['node' => 'mac-1'],
        [],
        [],
        ['REMOTE_ADDR' => TOOL_LIFECYCLE_API_CALLER_WG_IP],
    );

    $response
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'node_transport_required')
        ->assertJsonPath('error.meta.required', 'transitional-ssh-fallback');

    expect($shell->scripts)->toBe([]);
});

it('fails unsupported lifecycle tools before running host commands', function (): void {
    $caller = createToolLifecycleApiCallerNode();
    $node = Node::factory()->create([
        'name' => 'mac-1',
        'platform' => 'macos_15-4',
        'status' => 'active',
    ]);
    assignToolLifecycleApiRole($node, 'app-dev');
    grantToolLifecycleApiAccess($caller, $node, ['tool:start']);
    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'composer',
    ]);
    $shell = new ToolLifecycleApiRecordingShell;
    app()->instance(RemoteShell::class, $shell);

    $response = $this->call(
        'POST',
        '/api/tools/composer/start',
        ['node' => 'mac-1'],
        [],
        [],
        tool_lifecycle_api_server_headers(),
    );

    $response
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'tool.unsupported_action')
        ->assertJsonPath('error.meta.tool', 'composer')
        ->assertJsonPath('error.meta.action', 'start');

    expect($shell->scripts)->toBe([]);
});

it('fails unsupported orbstack platforms before running host commands', function (): void {
    $caller = createToolLifecycleApiCallerNode();
    $node = Node::factory()->create([
        'name' => 'linux-1',
        'platform' => 'ubuntu_24-04',
        'status' => 'active',
    ]);
    assignToolLifecycleApiRole($node, 'app-dev');
    grantToolLifecycleApiAccess($caller, $node, ['tool:start']);
    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'orbstack',
    ]);
    $shell = new ToolLifecycleApiRecordingShell;
    app()->instance(RemoteShell::class, $shell);

    $response = $this->call(
        'POST',
        '/api/tools/orbstack/start',
        ['node' => 'linux-1'],
        [],
        [],
        tool_lifecycle_api_server_headers(),
    );

    $response
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'tool.unsupported_on_node')
        ->assertJsonPath('error.meta.tool', 'orbstack')
        ->assertJsonPath('error.meta.node', 'linux-1');

    expect($shell->scripts)->toBe([]);
});

it('requires an adopted orbstack tool row before lifecycle execution', function (): void {
    $caller = createToolLifecycleApiCallerNode();
    $node = Node::factory()->create([
        'name' => 'mac-1',
        'platform' => 'macos_15-4',
        'status' => 'active',
    ]);
    assignToolLifecycleApiRole($node, 'app-dev');
    grantToolLifecycleApiAccess($caller, $node, ['tool:start']);
    $shell = new ToolLifecycleApiRecordingShell;
    app()->instance(RemoteShell::class, $shell);

    $response = $this->call(
        'POST',
        '/api/tools/orbstack/start',
        ['node' => 'mac-1'],
        [],
        [],
        tool_lifecycle_api_server_headers(),
    );

    $response
        ->assertNotFound()
        ->assertJsonPath('error.code', 'tool.not_found')
        ->assertJsonPath('error.meta.tool', 'orbstack')
        ->assertJsonPath('error.meta.node', 'mac-1');

    expect($shell->scripts)->toBe([]);
});

it('reports remote shell lifecycle failures without retrying', function (): void {
    $caller = createToolLifecycleApiCallerNode();
    $node = Node::factory()->create([
        'name' => 'mac-1',
        'platform' => 'macos_15-4',
        'status' => 'active',
    ]);
    assignToolLifecycleApiRole($node, 'app-dev');
    grantToolLifecycleApiAccess($caller, $node, ['tool:stop']);
    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'orbstack',
    ]);
    $shell = new ToolLifecycleApiRecordingShell(new RemoteShellResult(
        exitCode: 2,
        stdout: '',
        stderr: 'orbstack is not responding',
        durationMs: 1,
    ));
    app()->instance(RemoteShell::class, $shell);

    $response = $this->call(
        'POST',
        '/api/tools/orbstack/stop',
        ['node' => 'mac-1'],
        [],
        [],
        tool_lifecycle_api_server_headers(),
    );

    $response
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'tool.remote_action_failed')
        ->assertJsonPath('error.meta.tool', 'orbstack')
        ->assertJsonPath('error.meta.node', 'mac-1')
        ->assertJsonPath('error.meta.action', 'stop')
        ->assertJsonPath('error.meta.exit_code', 2);

    expect($shell->scripts)->toHaveCount(1);
});

final class ToolLifecycleApiRecordingShell implements RemoteShell
{
    /**
     * @var list<string>
     */
    public array $scripts = [];

    private RemoteShellResult $result;

    public function __construct(?RemoteShellResult $result = null)
    {
        $this->result = $result ?? new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        return $this->result;
    }
}
