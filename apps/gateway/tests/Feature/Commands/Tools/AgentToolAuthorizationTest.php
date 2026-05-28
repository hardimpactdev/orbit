<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const AGENT_TOOL_AUTH_WG_IP = '10.6.0.97';

function createAgentToolAuthAgentNode(array $overrides = []): Node
{
    $node = Node::factory()->create(array_merge([
        'name' => 'agent-1',
        'status' => 'active',
        'host' => AGENT_TOOL_AUTH_WG_IP,
        'wireguard_address' => AGENT_TOOL_AUTH_WG_IP,
    ], $overrides));

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'agent',
        'status' => 'active',
    ]);

    return $node;
}

/**
 * @param  list<string>  $permissions
 */
function grantAgentToolAuthAccess(Node $caller, Node $serving, array $permissions = ['*']): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $serving->id,
        'permissions' => json_encode($permissions),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

final class AgentToolAuthorizationRecordingShell implements RemoteShell
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

describe('agent tool authorization CLI', function (): void {
    it('allows agent self to update agent-category tools', function (): void {
        $agent = createAgentToolAuthAgentNode();
        grantAgentToolAuthAccess($agent, $agent, ['tool:update:agent-tools']);

        NodeTool::factory()->create([
            'node_id' => $agent->id,
            'name' => 'openclaw',
            'expected_state' => 'running',
        ]);

        $shell = new AgentToolAuthorizationRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:update', ['tool' => 'openclaw', '--node' => 'agent-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['tool'])->toMatchArray([
                'name' => 'openclaw',
                'node' => 'agent-1',
            ]);
    });

    it('allows agent self to restart agent-category tools', function (): void {
        $agent = createAgentToolAuthAgentNode();
        grantAgentToolAuthAccess($agent, $agent, ['tool:restart']);

        NodeTool::factory()->create([
            'node_id' => $agent->id,
            'name' => 'openclaw',
            'expected_state' => 'running',
        ]);

        $shell = new AgentToolAuthorizationRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:restart', ['tool' => 'openclaw', '--node' => 'agent-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['tool'])->toMatchArray([
                'name' => 'openclaw',
                'node' => 'agent-1',
            ]);
    });

    it('allows agent self to read credentials with explicit permission', function (): void {
        $agent = createAgentToolAuthAgentNode();
        grantAgentToolAuthAccess($agent, $agent, ['tool:credentials']);

        NodeTool::factory()->create([
            'node_id' => $agent->id,
            'name' => 'openclaw',
            'expected_state' => 'running',
            'credentials' => [
                'fields' => [
                    'url' => 'https://openclaw.agent',
                    'username' => 'orbit',
                    'password' => 'secret',
                ],
            ],
        ]);

        $exitCode = Artisan::call('tool:credentials', ['tool' => 'openclaw', '--node' => 'agent-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['credentials']['tool'])->toBe('openclaw');
    });

    it('allows agent self to install tools with explicit permission', function (): void {
        $agent = createAgentToolAuthAgentNode();
        grantAgentToolAuthAccess($agent, $agent, ['tool:install']);

        $shell = new AgentToolAuthorizationRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:install', ['tool' => 'hermes', '--node' => 'agent-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['tool']['name'])->toBe('hermes');
    });

    it('allows agent self to remove tools with explicit permission', function (): void {
        $agent = createAgentToolAuthAgentNode();
        grantAgentToolAuthAccess($agent, $agent, ['tool:remove']);

        NodeTool::factory()->create([
            'node_id' => $agent->id,
            'name' => 'openclaw',
            'expected_state' => 'running',
        ]);

        $shell = new AgentToolAuthorizationRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:remove', ['tool' => 'openclaw', '--node' => 'agent-1', '--force' => true, '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['tool']['name'])->toBe('openclaw');
    });

    it('allows agent self to stop tools with explicit permission', function (): void {
        $agent = createAgentToolAuthAgentNode();
        grantAgentToolAuthAccess($agent, $agent, ['tool:stop']);

        NodeTool::factory()->create([
            'node_id' => $agent->id,
            'name' => 'openclaw',
            'expected_state' => 'running',
        ]);

        $shell = new AgentToolAuthorizationRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:stop', ['tool' => 'openclaw', '--node' => 'agent-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['tool']['name'])->toBe('openclaw');
    });

    it('allows agent self to reconfigure tools with explicit permission', function (): void {
        $agent = createAgentToolAuthAgentNode();
        grantAgentToolAuthAccess($agent, $agent, ['tool:reconfigure']);

        NodeTool::factory()->create([
            'node_id' => $agent->id,
            'name' => 'openclaw',
            'expected_state' => 'running',
        ]);

        $shell = new AgentToolAuthorizationRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:reconfigure', ['tool' => 'openclaw', '--node' => 'agent-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['tool']['name'])->toBe('openclaw');
    });

    it('emits multiple_agent_tools_running warning in JSON mode when installing a second agent tool', function (): void {
        $agent = createAgentToolAuthAgentNode();
        grantAgentToolAuthAccess($agent, $agent, ['tool:install']);

        NodeTool::factory()->create([
            'node_id' => $agent->id,
            'name' => 'openclaw',
            'expected_state' => 'running',
        ]);

        $shell = new AgentToolAuthorizationRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:install', ['tool' => 'hermes', '--node' => 'agent-1', '--status' => 'running', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['meta']['warnings'])->toHaveCount(1)
            ->and($payload['success']['meta']['warnings'][0]['code'])->toBe('tool.multiple_agent_tools_running')
            ->and($payload['success']['meta']['warnings'][0]['tools'])->toContain('openclaw')
            ->and($payload['success']['meta']['warnings'][0]['tools'])->toContain('hermes');
    });

    it('warns when gateway installs a second running agent tool', function (): void {
        $gateway = Node::factory()->create([
            'name' => 'gateway-1',
            'status' => 'active',
        ]);

        NodeRoleAssignment::factory()->create([
            'node_id' => $gateway->id,
            'role' => 'gateway',
            'status' => 'active',
        ]);

        $agent = createAgentToolAuthAgentNode();

        NodeTool::factory()->create([
            'node_id' => $agent->id,
            'name' => 'openclaw',
            'expected_state' => 'running',
        ]);

        $shell = new AgentToolAuthorizationRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        config(['orbit.is_gateway' => true]);

        $exitCode = Artisan::call('tool:install', ['tool' => 'hermes', '--node' => 'agent-1', '--status' => 'running', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['meta']['warnings'])->toHaveCount(1)
            ->and($payload['success']['meta']['warnings'][0]['code'])->toBe('tool.multiple_agent_tools_running')
            ->and($payload['success']['meta']['warnings'][0]['tools'])->toContain('openclaw')
            ->and($payload['success']['meta']['warnings'][0]['tools'])->toContain('hermes');
    });

    it('warns when gateway starts an agent tool while another is running', function (): void {
        $gateway = Node::factory()->create([
            'name' => 'gateway-1',
            'status' => 'active',
        ]);

        NodeRoleAssignment::factory()->create([
            'node_id' => $gateway->id,
            'role' => 'gateway',
            'status' => 'active',
        ]);

        $agent = createAgentToolAuthAgentNode();

        NodeTool::factory()->create([
            'node_id' => $agent->id,
            'name' => 'openclaw',
            'expected_state' => 'running',
        ]);

        NodeTool::factory()->create([
            'node_id' => $agent->id,
            'name' => 'hermes',
            'expected_state' => 'installed',
        ]);

        $shell = new AgentToolAuthorizationRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        config(['orbit.is_gateway' => true]);

        $exitCode = Artisan::call('tool:start', ['tool' => 'hermes', '--node' => 'agent-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['meta']['warnings'])->toHaveCount(1)
            ->and($payload['success']['meta']['warnings'][0]['code'])->toBe('tool.multiple_agent_tools_running')
            ->and($payload['success']['meta']['warnings'][0]['tools'])->toContain('openclaw')
            ->and($payload['success']['meta']['warnings'][0]['tools'])->toContain('hermes');
    });
});

describe('agent tool authorization API', function (): void {
    it('allows agent self to update agent-category tools via API with tool:update:agent-tools', function (): void {
        $agent = createAgentToolAuthAgentNode();
        grantAgentToolAuthAccess($agent, $agent, ['tool:update:agent-tools']);

        NodeTool::factory()->create([
            'node_id' => $agent->id,
            'name' => 'openclaw',
            'expected_state' => 'running',
        ]);

        $shell = new AgentToolAuthorizationRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call('POST', '/api/tools/openclaw/update', [
            'node' => 'agent-1',
        ], [], [], ['REMOTE_ADDR' => AGENT_TOOL_AUTH_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.tool.name', 'openclaw')
            ->assertJsonPath('success.data.tool.node', 'agent-1');
    });

    it('denies agent self from updating non-agent tools via API', function (): void {
        $agent = createAgentToolAuthAgentNode();
        grantAgentToolAuthAccess($agent, $agent, ['tool:update:agent-tools']);

        NodeTool::factory()->create([
            'node_id' => $agent->id,
            'name' => 'caddy',
            'expected_state' => 'running',
        ]);

        $response = $this->call('POST', '/api/tools/caddy/update', [
            'node' => 'agent-1',
        ], [], [], ['REMOTE_ADDR' => AGENT_TOOL_AUTH_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.message', "Agent self may only update agent-category tools. 'caddy' is not an agent tool.");
    });

    it('denies agent self from installing non-agent tools via API even with install permission', function (): void {
        $agent = createAgentToolAuthAgentNode();
        grantAgentToolAuthAccess($agent, $agent, ['tool:install']);

        $shell = new AgentToolAuthorizationRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call('POST', '/api/tools/caddy/install', [
            'node' => 'agent-1',
        ], [], [], ['REMOTE_ADDR' => AGENT_TOOL_AUTH_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.message', "Agent self may only install agent-category tools. 'caddy' is not an agent tool.");
    });

    it('allows agent self to install tools via API with explicit permission', function (): void {
        $agent = createAgentToolAuthAgentNode();
        grantAgentToolAuthAccess($agent, $agent, ['tool:install']);

        $shell = new AgentToolAuthorizationRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call('POST', '/api/tools/hermes/install', [
            'node' => 'agent-1',
        ], [], [], ['REMOTE_ADDR' => AGENT_TOOL_AUTH_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.tool.name', 'hermes');
    });

    it('denies agent self from installing tools via API without permission', function (): void {
        $agent = createAgentToolAuthAgentNode();
        // No tool:install grant

        $shell = new AgentToolAuthorizationRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call('POST', '/api/tools/hermes/install', [
            'node' => 'agent-1',
        ], [], [], ['REMOTE_ADDR' => AGENT_TOOL_AUTH_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.message', 'This node is not authorized to manage tools.');
    });

    it('allows agent self to read credentials via API with explicit permission', function (): void {
        $agent = createAgentToolAuthAgentNode();
        grantAgentToolAuthAccess($agent, $agent, ['tool:credentials']);

        NodeTool::factory()->create([
            'node_id' => $agent->id,
            'name' => 'openclaw',
            'expected_state' => 'running',
            'credentials' => [
                'fields' => [
                    'url' => 'https://openclaw.agent',
                    'username' => 'orbit',
                    'password' => 'secret',
                ],
            ],
        ]);

        $response = $this->call('GET', '/api/tools/openclaw/credentials', [
            'node' => 'agent-1',
        ], [], [], ['REMOTE_ADDR' => AGENT_TOOL_AUTH_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.credentials.tool', 'openclaw');
    });

    it('denies agent self from reading credentials via API without permission', function (): void {
        $agent = createAgentToolAuthAgentNode();
        // No tool:credentials grant

        NodeTool::factory()->create([
            'node_id' => $agent->id,
            'name' => 'openclaw',
            'expected_state' => 'running',
            'credentials' => [
                'fields' => [
                    'url' => 'https://openclaw.agent',
                    'username' => 'orbit',
                    'password' => 'secret',
                ],
            ],
        ]);

        $response = $this->call('GET', '/api/tools/openclaw/credentials', [
            'node' => 'agent-1',
        ], [], [], ['REMOTE_ADDR' => AGENT_TOOL_AUTH_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.message', 'This node is not authorized to manage tools.');
    });

    it('denies agent self from starting tools via API', function (): void {
        $agent = createAgentToolAuthAgentNode();
        grantAgentToolAuthAccess($agent, $agent, ['tool:start']);

        NodeTool::factory()->create([
            'node_id' => $agent->id,
            'name' => 'openclaw',
            'expected_state' => 'running',
        ]);

        NodeTool::factory()->create([
            'node_id' => $agent->id,
            'name' => 'hermes',
            'expected_state' => 'installed',
        ]);

        $shell = new AgentToolAuthorizationRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call('POST', '/api/tools/hermes/start', [
            'node' => 'agent-1',
        ], [], [], ['REMOTE_ADDR' => AGENT_TOOL_AUTH_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.message', 'Agent self is not authorized to start tools.');
    });

    it('denies agent self without explicit self-grant from any tool action via API', function (): void {
        $agent = createAgentToolAuthAgentNode();
        // No node_access grant at all

        NodeTool::factory()->create([
            'node_id' => $agent->id,
            'name' => 'openclaw',
            'expected_state' => 'running',
        ]);

        $response = $this->call('POST', '/api/tools/openclaw/update', [
            'node' => 'agent-1',
        ], [], [], ['REMOTE_ADDR' => AGENT_TOOL_AUTH_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.message', 'This node is not authorized to manage tools.');
    });

    it('denies agent self from listing tools via API without self-grant', function (): void {
        $agent = createAgentToolAuthAgentNode();
        // No node_access grant at all

        NodeTool::factory()->create([
            'node_id' => $agent->id,
            'name' => 'openclaw',
            'expected_state' => 'running',
        ]);

        $response = $this->call('GET', '/api/tools', [
            'node' => 'agent-1',
        ], [], [], ['REMOTE_ADDR' => AGENT_TOOL_AUTH_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.message', 'This node is not authorized to read the tool registry.');
    });

    it('denies agent self from reading tool details via API without self-grant', function (): void {
        $agent = createAgentToolAuthAgentNode();
        // No node_access grant at all

        NodeTool::factory()->create([
            'node_id' => $agent->id,
            'name' => 'openclaw',
            'expected_state' => 'running',
        ]);

        $response = $this->call('GET', '/api/tools/openclaw', [
            'node' => 'agent-1',
        ], [], [], ['REMOTE_ADDR' => AGENT_TOOL_AUTH_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.message', 'This node is not authorized to read the tool registry.');
    });

    it('denies agent self from reading tool logs via API without self-grant', function (): void {
        $agent = createAgentToolAuthAgentNode();
        // No node_access grant at all

        NodeTool::factory()->create([
            'node_id' => $agent->id,
            'name' => 'openclaw',
            'expected_state' => 'running',
        ]);

        $response = $this->call('GET', '/api/tools/openclaw/logs', [
            'node' => 'agent-1',
        ], [], [], ['REMOTE_ADDR' => AGENT_TOOL_AUTH_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.message', 'This node is not authorized to inspect tools.');
    });

    it('allows agent self to restart agent-category tools via API with tool:restart', function (): void {
        $agent = createAgentToolAuthAgentNode();
        grantAgentToolAuthAccess($agent, $agent, ['tool:restart']);

        NodeTool::factory()->create([
            'node_id' => $agent->id,
            'name' => 'openclaw',
            'expected_state' => 'running',
        ]);

        $shell = new AgentToolAuthorizationRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call('POST', '/api/tools/openclaw/restart', [
            'node' => 'agent-1',
        ], [], [], ['REMOTE_ADDR' => AGENT_TOOL_AUTH_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.tool.name', 'openclaw')
            ->assertJsonPath('success.data.tool.node', 'agent-1');
    });

    it('rejects agent self update when node does not match caller', function (): void {
        $agent = createAgentToolAuthAgentNode();
        grantAgentToolAuthAccess($agent, $agent, ['tool:update:agent-tools']);

        NodeTool::factory()->create([
            'node_id' => $agent->id,
            'name' => 'openclaw',
            'expected_state' => 'running',
        ]);

        $shell = new AgentToolAuthorizationRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call('POST', '/api/tools/openclaw/update', [
            'node' => 'other-node',
        ], [], [], ['REMOTE_ADDR' => AGENT_TOOL_AUTH_WG_IP]);

        $response->assertUnprocessable();
        expect($shell->scripts)->toBeEmpty();
    });

    it('rejects agent self restart when node does not match caller', function (): void {
        $agent = createAgentToolAuthAgentNode();
        grantAgentToolAuthAccess($agent, $agent, ['tool:restart']);

        NodeTool::factory()->create([
            'node_id' => $agent->id,
            'name' => 'openclaw',
            'expected_state' => 'running',
        ]);

        $shell = new AgentToolAuthorizationRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call('POST', '/api/tools/openclaw/restart', [
            'node' => 'other-node',
        ], [], [], ['REMOTE_ADDR' => AGENT_TOOL_AUTH_WG_IP]);

        $response->assertUnprocessable();
        expect($shell->scripts)->toBeEmpty();
    });

    it('rejects agent self update when app target is provided', function (): void {
        $agent = createAgentToolAuthAgentNode();
        grantAgentToolAuthAccess($agent, $agent, ['tool:update:agent-tools']);

        NodeTool::factory()->create([
            'node_id' => $agent->id,
            'name' => 'openclaw',
            'expected_state' => 'running',
        ]);

        $shell = new AgentToolAuthorizationRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call('POST', '/api/tools/openclaw/update', [
            'app' => 'some-app',
        ], [], [], ['REMOTE_ADDR' => AGENT_TOOL_AUTH_WG_IP]);

        $response->assertUnprocessable();
        expect($shell->scripts)->toBeEmpty();
    });

    it('rejects agent self restart when app target is provided', function (): void {
        $agent = createAgentToolAuthAgentNode();
        grantAgentToolAuthAccess($agent, $agent, ['tool:restart']);

        NodeTool::factory()->create([
            'node_id' => $agent->id,
            'name' => 'openclaw',
            'expected_state' => 'running',
        ]);

        $shell = new AgentToolAuthorizationRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call('POST', '/api/tools/openclaw/restart', [
            'app' => 'some-app',
        ], [], [], ['REMOTE_ADDR' => AGENT_TOOL_AUTH_WG_IP]);

        $response->assertUnprocessable();
        expect($shell->scripts)->toBeEmpty();
    });

    it('warns via API when gateway installs a second running agent tool', function (): void {
        $gateway = Node::factory()->create([
            'name' => 'gateway-1',
            'status' => 'active',
            'wireguard_address' => '10.6.0.1',
        ]);

        NodeRoleAssignment::factory()->create([
            'node_id' => $gateway->id,
            'role' => 'gateway',
            'status' => 'active',
        ]);

        $agent = createAgentToolAuthAgentNode();

        NodeTool::factory()->create([
            'node_id' => $agent->id,
            'name' => 'openclaw',
            'expected_state' => 'running',
        ]);

        $shell = new AgentToolAuthorizationRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call('POST', '/api/tools/hermes/install', [
            'node' => 'agent-1',
            'status' => 'running',
        ], [], [], ['REMOTE_ADDR' => '10.6.0.1']);

        $json = $response->json();

        $response->assertOk();

        $this->assertEquals('tool.multiple_agent_tools_running', $json['success']['meta']['warnings'][0]['code'] ?? null);
        $this->assertEquals(['openclaw', 'hermes'], $json['success']['meta']['warnings'][0]['tools'] ?? null);
    });

    it('warns via API when gateway starts an agent tool while another is running', function (): void {
        $gateway = Node::factory()->create([
            'name' => 'gateway-1',
            'status' => 'active',
            'wireguard_address' => '10.6.0.1',
        ]);

        NodeRoleAssignment::factory()->create([
            'node_id' => $gateway->id,
            'role' => 'gateway',
            'status' => 'active',
        ]);

        $agent = createAgentToolAuthAgentNode();

        NodeTool::factory()->create([
            'node_id' => $agent->id,
            'name' => 'openclaw',
            'expected_state' => 'running',
        ]);

        NodeTool::factory()->create([
            'node_id' => $agent->id,
            'name' => 'hermes',
            'expected_state' => 'installed',
        ]);

        $shell = new AgentToolAuthorizationRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call('POST', '/api/tools/hermes/start', [
            'node' => 'agent-1',
        ], [], [], ['REMOTE_ADDR' => '10.6.0.1']);

        $response->assertOk()
            ->assertJsonPath('success.meta.warnings.0.code', 'tool.multiple_agent_tools_running')
            ->assertJsonPath('success.meta.warnings.0.tools', ['openclaw', 'hermes']);
    });
});
