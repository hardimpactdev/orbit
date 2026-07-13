<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Processes\ProcessRuntime;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Models\Process as ProcessModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process as ProcessFacade;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    bind_tool_script_dispatcher_to_remote_shell();
});

const TOOL_LIFECYCLE_API_CALLER_WG_IP = '10.6.0.94';

function tool_lifecycle_api_server_headers(array $overrides = []): array
{
    return [
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
    assignToolLifecycleApiRole($node, role: 'app-dev');
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

it('requires agent-push transport before running lifecycle scripts', function (): void {
    $caller = createToolLifecycleApiCallerNode();
    $node = Node::factory()->create([
        'name' => 'mac-1',
        'platform' => 'macos_15-4',
        'status' => 'active',
    ]);
    assignToolLifecycleApiRole($node, role: 'app-dev');
    grantToolLifecycleApiAccess($caller, $node, ['tool:start']);
    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'orbstack',
    ]);
    $shell = new ToolLifecycleApiRecordingShell;
    app()->instance(RemoteShell::class, $shell);
    bind_unavailable_tool_script_dispatcher();

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
        ->assertJsonPath('error.code', 'node.agent_unreachable')
        ->assertJsonPath('error.meta.reason', 'agent_push_unavailable')
        ->assertJsonPath('error.meta.node', 'mac-1');

    expect($shell->scripts)->toBe([]);
});

it('reports direct log transport failures as an unreachable Agent', function (): void {
    $caller = createToolLifecycleApiCallerNode();
    $node = Node::factory()->create([
        'name' => 'app-caddy-1',
        'platform' => 'ubuntu_24-04',
        'status' => 'active',
    ]);
    assignToolLifecycleApiRole($node, role: 'app-dev');
    grantToolLifecycleApiAccess($caller, $node, ['tool:logs']);
    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'caddy',
    ]);
    bind_unavailable_tool_script_dispatcher();

    $response = $this->call(
        'GET',
        '/api/tools/caddy/logs',
        ['node' => 'app-caddy-1'],
        [],
        [],
        tool_lifecycle_api_server_headers(),
    );

    $response
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'node.agent_unreachable')
        ->assertJsonPath('error.meta.reason', 'agent_push_unavailable')
        ->assertJsonPath('error.meta.node', 'app-caddy-1')
        ->assertJsonPath('error.meta.tool', 'caddy')
        ->assertJsonPath('error.meta.action', 'logs');
});

it('reports process-backed lifecycle transport failures as an unreachable Agent', function (): void {
    $caller = createToolLifecycleApiCallerNode();
    $node = Node::factory()->create([
        'name' => 'app-opencode-lifecycle-1',
        'platform' => 'ubuntu_24-04',
        'status' => 'active',
    ]);
    assignToolLifecycleApiRole($node, role: 'app-dev');
    grantToolLifecycleApiAccess($caller, $node, ['tool:start']);
    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'opencode-cli',
    ]);
    ProcessModel::factory()
        ->forOwner($node)
        ->create([
            'name' => 'opencode-server',
            'runtime' => ProcessRuntime::Systemd,
            'tool' => 'opencode-cli',
        ]);
    bind_unavailable_tool_script_dispatcher();

    $response = $this->call(
        'POST',
        '/api/tools/opencode-cli/start',
        ['node' => 'app-opencode-lifecycle-1'],
        [],
        [],
        tool_lifecycle_api_server_headers(),
    );

    $response
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'node.agent_unreachable')
        ->assertJsonPath('error.meta.reason', 'agent_push_unavailable')
        ->assertJsonPath('error.meta.node', 'app-opencode-lifecycle-1')
        ->assertJsonPath('error.meta.tool', 'opencode-cli')
        ->assertJsonPath('error.meta.action', 'start');
});

it('reports process-backed log transport failures as an unreachable Agent', function (): void {
    $caller = createToolLifecycleApiCallerNode();
    $node = Node::factory()->create([
        'name' => 'app-opencode-logs-1',
        'platform' => 'ubuntu_24-04',
        'status' => 'active',
        'wireguard_address' => null,
    ]);
    assignToolLifecycleApiRole($node, role: 'app-dev');
    grantToolLifecycleApiAccess($caller, $node, ['tool:logs']);
    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'opencode-cli',
    ]);
    ProcessModel::factory()
        ->forOwner($node)
        ->create([
            'name' => 'opencode-server',
            'runtime' => ProcessRuntime::Systemd,
            'tool' => 'opencode-cli',
        ]);

    $response = $this->call(
        'GET',
        '/api/tools/opencode-cli/logs',
        ['node' => 'app-opencode-logs-1'],
        [],
        [],
        tool_lifecycle_api_server_headers(),
    );

    $response
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'node.agent_unreachable')
        ->assertJsonPath('error.meta.reason', 'agent_push_unavailable')
        ->assertJsonPath('error.meta.node', 'app-opencode-logs-1')
        ->assertJsonPath('error.meta.tool', 'opencode-cli')
        ->assertJsonPath('error.meta.action', 'logs');
});

it('fails unsupported lifecycle tools before running host commands', function (): void {
    $caller = createToolLifecycleApiCallerNode();
    $node = Node::factory()->create([
        'name' => 'mac-1',
        'platform' => 'macos_15-4',
        'status' => 'active',
    ]);
    assignToolLifecycleApiRole($node, role: 'app-dev');
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

it('runs the declared DNS restart against the gateway-local runtime', function (): void {
    $gateway = createTestGatewayNode([
        'name' => 'gateway-dns',
        'host' => TOOL_LIFECYCLE_API_CALLER_WG_IP,
        'wireguard_address' => TOOL_LIFECYCLE_API_CALLER_WG_IP,
    ]);
    NodeTool::factory()->create([
        'node_id' => $gateway->id,
        'name' => 'dns',
    ]);
    ProcessFacade::fake([
        '*' => ProcessFacade::result(output: '', errorOutput: '', exitCode: 0),
    ]);

    $response = $this->call(
        'POST',
        '/api/tools/dns/restart',
        ['node' => 'gateway-dns'],
        [],
        [],
        ['REMOTE_ADDR' => TOOL_LIFECYCLE_API_CALLER_WG_IP],
    );

    $response
        ->assertOk()
        ->assertJsonPath('success.data.tool.name', 'dns')
        ->assertJsonPath('success.data.tool.action', 'restart');

    ProcessFacade::assertRan(
        fn ($process): bool => (
            str_contains($process->command, 'docker restart') && str_contains($process->command, 'orbit-dns')
        ),
    );
});

it('reads declared DNS logs directly from the gateway-local runtime', function (): void {
    $gateway = createTestGatewayNode([
        'name' => 'gateway-dns-logs',
        'host' => TOOL_LIFECYCLE_API_CALLER_WG_IP,
        'wireguard_address' => TOOL_LIFECYCLE_API_CALLER_WG_IP,
        'tld' => 'gateway-dns-logs',
    ]);
    NodeTool::factory()->create([
        'node_id' => $gateway->id,
        'name' => 'dns',
    ]);
    ProcessFacade::fake([
        '*' => ProcessFacade::result(output: "dns ready\nquery docs.orbit\n", errorOutput: '', exitCode: 0),
    ]);

    $response = $this->call(
        'GET',
        '/api/tools/dns/logs',
        ['node' => 'gateway-dns-logs', 'lines' => 25],
        [],
        [],
        ['REMOTE_ADDR' => TOOL_LIFECYCLE_API_CALLER_WG_IP],
    );

    $response
        ->assertOk()
        ->assertJsonPath('success.data.logs.tool', 'dns')
        ->assertJsonPath('success.data.logs.runtime', 'tool')
        ->assertJsonPath('success.data.logs.lines.0.message', 'dns ready')
        ->assertJsonPath('success.meta.line_count', 2);

    ProcessFacade::assertRan(
        fn ($process): bool => (
            str_contains($process->command, 'docker logs')
            && str_contains($process->command, '--tail')
            && str_contains($process->command, '25')
            && str_contains($process->command, 'orbit-dns')
        ),
    );
});

it('authorizes a non-gateway caller to restart a gateway-local tool through a gateway grant', function (): void {
    $caller = createToolLifecycleApiCallerNode();
    $gateway = createTestGatewayNode(['name' => 'gateway-dns-granted']);
    grantToolLifecycleApiAccess($caller, $gateway, ['tool:restart']);
    NodeTool::factory()->create([
        'node_id' => $gateway->id,
        'name' => 'dns',
    ]);
    ProcessFacade::fake([
        '*' => ProcessFacade::result(output: '', errorOutput: '', exitCode: 0),
    ]);

    $response = $this->call(
        'POST',
        '/api/tools/dns/restart',
        ['node' => 'gateway-dns-granted'],
        [],
        [],
        ['REMOTE_ADDR' => TOOL_LIFECYCLE_API_CALLER_WG_IP],
    );

    $response
        ->assertOk()
        ->assertJsonPath('success.data.tool.node', 'gateway-dns-granted')
        ->assertJsonPath('success.data.tool.action', 'restart');

    ProcessFacade::assertRan(fn ($process): bool => str_contains($process->command, 'docker restart'));
});

it('denies gateway-local lifecycle actions when the gateway grant has the wrong permission', function (): void {
    $caller = createToolLifecycleApiCallerNode();
    $gateway = createTestGatewayNode(['name' => 'gateway-dns-denied']);
    grantToolLifecycleApiAccess($caller, $gateway, ['tool:read']);
    NodeTool::factory()->create([
        'node_id' => $gateway->id,
        'name' => 'dns',
    ]);
    ProcessFacade::fake();

    $response = $this->call(
        'POST',
        '/api/tools/dns/restart',
        ['node' => 'gateway-dns-denied'],
        [],
        [],
        ['REMOTE_ADDR' => TOOL_LIFECYCLE_API_CALLER_WG_IP],
    );

    $response
        ->assertForbidden()
        ->assertJsonPath('error.code', 'authorization_failed')
        ->assertJsonPath('error.meta.reason', 'missing_permission')
        ->assertJsonPath('error.meta.missing_permission', 'tool:restart')
        ->assertJsonPath('error.meta.serving_node', 'gateway-dns-denied');

    ProcessFacade::assertNothingRan();
});

it('authorizes a non-gateway caller to read gateway-local logs through a gateway read grant', function (): void {
    $caller = createToolLifecycleApiCallerNode();
    $gateway = createTestGatewayNode(['name' => 'gateway-dns-logs-granted']);
    grantToolLifecycleApiAccess($caller, $gateway, ['tool:read']);
    NodeTool::factory()->create([
        'node_id' => $gateway->id,
        'name' => 'dns',
    ]);
    ProcessFacade::fake([
        '*' => ProcessFacade::result(output: "dns ready\n", errorOutput: '', exitCode: 0),
    ]);

    $response = $this->call(
        'GET',
        '/api/tools/dns/logs',
        ['node' => 'gateway-dns-logs-granted'],
        [],
        [],
        ['REMOTE_ADDR' => TOOL_LIFECYCLE_API_CALLER_WG_IP],
    );

    $response
        ->assertOk()
        ->assertJsonPath('success.data.logs.tool', 'dns')
        ->assertJsonPath('success.data.logs.lines.0.message', 'dns ready');
});

it('denies gateway-local logs when the gateway grant has the wrong permission', function (): void {
    $caller = createToolLifecycleApiCallerNode();
    $gateway = createTestGatewayNode(['name' => 'gateway-dns-logs-denied']);
    grantToolLifecycleApiAccess($caller, $gateway, ['tool:restart']);
    NodeTool::factory()->create([
        'node_id' => $gateway->id,
        'name' => 'dns',
    ]);
    ProcessFacade::fake();

    $response = $this->call(
        'GET',
        '/api/tools/dns/logs',
        ['node' => 'gateway-dns-logs-denied'],
        [],
        [],
        ['REMOTE_ADDR' => TOOL_LIFECYCLE_API_CALLER_WG_IP],
    );

    $response
        ->assertForbidden()
        ->assertJsonPath('error.code', 'authorization_failed')
        ->assertJsonPath('error.meta.reason', 'missing_permission')
        ->assertJsonPath('error.meta.missing_permission', 'tool:logs')
        ->assertJsonPath('error.meta.serving_node', 'gateway-dns-logs-denied');

    ProcessFacade::assertNothingRan();
});

it('fails explicitly when a capability-declared tool maps to multiple process runtimes', function (): void {
    $caller = createToolLifecycleApiCallerNode();
    $node = Node::factory()->create([
        'name' => 'app-1',
        'platform' => 'ubuntu_24-04',
        'status' => 'active',
        'tld' => 'app-one',
    ]);
    assignToolLifecycleApiRole($node, role: 'app-dev');
    grantToolLifecycleApiAccess($caller, $node, ['tool:start']);
    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'opencode-cli',
    ]);
    ProcessModel::factory()
        ->count(2)
        ->forOwner($node)
        ->sequence(
            ['name' => 'opencode-one'],
            ['name' => 'opencode-two'],
        )
        ->create(['tool' => 'opencode-cli']);

    $response = $this->call(
        'POST',
        '/api/tools/opencode-cli/start',
        ['node' => 'app-1'],
        [],
        [],
        tool_lifecycle_api_server_headers(),
    );

    $response
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'tool.runtime_ambiguous')
        ->assertJsonPath('error.meta.tool', 'opencode-cli')
        ->assertJsonCount(2, 'error.meta.processes');
});

it('fails unsupported orbstack platforms before running host commands', function (): void {
    $caller = createToolLifecycleApiCallerNode();
    $node = Node::factory()->create([
        'name' => 'linux-1',
        'platform' => 'ubuntu_24-04',
        'status' => 'active',
    ]);
    assignToolLifecycleApiRole($node, role: 'app-dev');
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
    assignToolLifecycleApiRole($node, role: 'app-dev');
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
    assignToolLifecycleApiRole($node, role: 'app-dev');
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
