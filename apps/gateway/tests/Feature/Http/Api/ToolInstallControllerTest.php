<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    bind_tool_script_dispatcher_to_remote_shell();
});

const TOOL_INSTALL_API_CALLER_WG_IP = '10.6.0.98';

function tool_install_api_server_headers(array $overrides = []): array
{
    return [
        'REMOTE_ADDR' => TOOL_INSTALL_API_CALLER_WG_IP,
        ...$overrides,
    ];
}

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
        'permissions' => json_encode($permissions, JSON_THROW_ON_ERROR),
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
    it('allows gateway callers to install host capabilities on visible tool nodes', function (): void {
        $caller = createToolInstallApiCallerNode();
        assignToolInstallApiRole($caller, 'gateway');
        $node = Node::factory()->create([
            'name' => 'app-install-api-1',
            'status' => 'active',
        ]);
        assignToolInstallApiRole($node, 'app-dev');
        $shell = new ToolInstallApiRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call(
            'POST',
            '/api/tools/php-cli/install',
            [
                'node' => 'app-install-api-1',
                'version' => '8.5',
            ],
            [],
            [],
            tool_install_api_server_headers(),
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.tool.name', 'php-cli')
            ->assertJsonPath('success.data.tool.node', 'app-install-api-1')
            ->assertJsonPath('success.data.tool.state', 'installed')
            ->assertJsonPath('success.data.tool.version', '8.5');

        $tool = NodeTool::query()
            ->where('node_id', $node->id)
            ->where('name', 'php-cli')
            ->firstOrFail();

        expect($tool->expected_version)
            ->toBe('8.5')
            ->and($tool->getAttributes())
            ->not
            ->toHaveKeys(['instance_key', 'version_family', 'runtime', 'runtime_config'])
            ->and($shell->scripts)
            ->toHaveCount(1);
    });

    it('installs a host capability through an instance target', function (): void {
        $caller = createToolInstallApiCallerNode();
        $node = Node::factory()->create(['name' => 'app-install-api-1', 'status' => 'active']);
        assignToolInstallApiRole($node, 'app-dev');
        grantToolInstallApiAccess($caller, $node);
        $project = Project::factory()->create(['name' => 'docs']);
        AppInstance::factory()->for($project)->create([
            'name' => 'development',
            'driver_config' => new OrbitAppInstanceDriverConfigData(
                node_id: $node->id,
                path: '/home/orbit/apps/docs',
                document_root: 'public',
                domain: 'docs.test',
            ),
        ]);
        $shell = new ToolInstallApiRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call(
            'POST',
            '/api/tools/php-cli/install',
            [
                'instance' => 'docs.development',
                'version' => '8.5',
            ],
            [],
            [],
            tool_install_api_server_headers(),
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.tool.node', 'app-install-api-1')
            ->assertJsonPath('success.data.tool.version', '8.5');

        expect($shell->scripts)->toHaveCount(1);
    });

    it('requires agent-push transport before installing host capabilities', function (): void {
        $caller = createToolInstallApiCallerNode();
        assignToolInstallApiRole($caller, 'gateway');
        $node = Node::factory()->create([
            'name' => 'app-install-api-1',
            'status' => 'active',
        ]);
        assignToolInstallApiRole($node, 'app-dev');
        $shell = new ToolInstallApiRecordingShell;
        app()->instance(RemoteShell::class, $shell);
        bind_unavailable_tool_script_dispatcher();

        $response = $this->call(
            'POST',
            '/api/tools/php-cli/install',
            [
                'node' => 'app-install-api-1',
                'version' => '8.5',
            ],
            [],
            [],
            ['REMOTE_ADDR' => TOOL_INSTALL_API_CALLER_WG_IP],
        );

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'node.agent_unreachable')
            ->assertJsonPath('error.meta.reason', 'agent_push_unavailable')
            ->assertJsonPath('error.meta.node', 'app-install-api-1');

        expect(NodeTool::query()->where('node_id', $node->id)->where('name', 'php-cli')->exists())
            ->toBeFalse()
            ->and($shell->scripts)
            ->toBe([]);
    });

    it('rejects missing platform metadata through stable preflight before side effects', function (): void {
        $caller = createToolInstallApiCallerNode();
        assignToolInstallApiRole($caller, 'gateway');
        $node = Node::factory()->create([
            'name' => 'missing-platform-tool-node',
            'status' => 'active',
            'platform' => null,
        ]);
        assignToolInstallApiRole($node, 'app-dev');
        $shell = new ToolInstallApiRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call(
            'POST',
            '/api/tools/php-cli/install',
            ['node' => $node->name],
            [],
            [],
            tool_install_api_server_headers(),
        );

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'tool.constraint_unsatisfied')
            ->assertJsonPath('error.meta.tool', 'php-cli')
            ->assertJsonPath('error.meta.node', $node->name)
            ->assertJsonPath('error.meta.action', 'install')
            ->assertJsonPath('error.meta.constraint', 'operating_system')
            ->assertJsonPath('error.meta.actual', null)
            ->assertJsonPath('error.meta.required', ['linux', 'macos']);

        expect(NodeTool::query()->where('node_id', $node->id)->exists())
            ->toBeFalse()
            ->and($shell->scripts)
            ->toBe([]);
    });

    it('checks agent runtime-user isolation before OpenClaw or Hermes installation side effects', function (
        string $tool,
    ): void {
        $caller = createToolInstallApiCallerNode();
        assignToolInstallApiRole($caller, 'gateway');
        $node = Node::factory()->create([
            'name' => "{$tool}-missing-runtime-user",
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'tld' => "{$tool}-agent",
        ]);
        assignToolInstallApiRole($node, 'agent');
        $shell = new ToolInstallApiRecordingShell([
            "id -u 'agent'" => new RemoteShellResult(
                exitCode: 64,
                stdout: '',
                stderr: "Required runtime user 'agent' does not exist.",
                durationMs: 1,
            ),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call(
            'POST',
            "/api/tools/{$tool}/install",
            ['node' => $node->name],
            [],
            [],
            tool_install_api_server_headers(),
        );

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'tool.constraint_unsatisfied')
            ->assertJsonPath('error.meta.tool', $tool)
            ->assertJsonPath('error.meta.constraint', 'runtime_user')
            ->assertJsonPath('error.meta.required', 'agent')
            ->assertJsonPath('error.meta.actual', 'missing')
            ->assertJsonPath('error.meta.exit_code', 64);

        expect(NodeTool::query()->where('node_id', $node->id)->where('name', $tool)->exists())
            ->toBeFalse()
            ->and($shell->scripts)
            ->toHaveCount(1)
            ->and($shell->scripts[0])
            ->toContain("id -u 'agent'")
            ->and($shell->toolRowsPresent)
            ->toBe([false]);
    })->with(['openclaw', 'hermes']);

    it('rejects a privileged agent runtime user through stable isolation metadata', function (): void {
        $caller = createToolInstallApiCallerNode();
        assignToolInstallApiRole($caller, 'gateway');
        $node = Node::factory()->create([
            'name' => 'openclaw-privileged-runtime-user',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'tld' => 'openclaw-privileged',
        ]);
        assignToolInstallApiRole($node, 'agent');
        $shell = new ToolInstallApiRecordingShell([
            "id -u 'agent'" => new RemoteShellResult(
                exitCode: 65,
                stdout: '',
                stderr: 'Required runtime user must be unprivileged.',
                durationMs: 1,
            ),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call(
            'POST',
            '/api/tools/openclaw/install',
            ['node' => $node->name],
            [],
            [],
            tool_install_api_server_headers(),
        );

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'tool.constraint_unsatisfied')
            ->assertJsonPath('error.meta.constraint', 'isolation')
            ->assertJsonPath('error.meta.required', 'unprivileged-user')
            ->assertJsonPath('error.meta.actual', 'privileged-user')
            ->assertJsonPath('error.meta.exit_code', 65);

        expect(NodeTool::query()->where('node_id', $node->id)->where('name', 'openclaw')->exists())
            ->toBeFalse()
            ->and($shell->toolRowsPresent)
            ->toBe([false]);
    });

    it('checks required container providers before Docker-backed install side effects', function (): void {
        $caller = createToolInstallApiCallerNode();
        assignToolInstallApiRole($caller, 'gateway');
        $node = Node::factory()->create([
            'name' => 'mailpit-without-provider',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
        ]);
        assignToolInstallApiRole($node, 'app-dev');
        $shell = new ToolInstallApiRecordingShell([
            'docker info' => new RemoteShellResult(
                exitCode: 67,
                stdout: '',
                stderr: 'Docker-compatible container provider is unreachable.',
                durationMs: 1,
            ),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call(
            'POST',
            '/api/tools/mailpit/install',
            ['node' => $node->name],
            [],
            [],
            tool_install_api_server_headers(),
        );

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'tool.constraint_unsatisfied')
            ->assertJsonPath('error.meta.constraint', 'container_provider')
            ->assertJsonPath('error.meta.required', 'docker-compatible')
            ->assertJsonPath('error.meta.actual', 'unreachable')
            ->assertJsonPath('error.meta.exit_code', 67);

        expect(NodeTool::query()->where('node_id', $node->id)->where('name', 'mailpit')->exists())
            ->toBeFalse()
            ->and($shell->scripts)
            ->toHaveCount(1)
            ->and($shell->scripts[0])
            ->toContain('docker info')
            ->and($shell->toolRowsPresent)
            ->toBe([false]);
    });

    it('completes agent tool preflight before persisting intent and running the installer', function (): void {
        $caller = createToolInstallApiCallerNode();
        assignToolInstallApiRole($caller, 'gateway');
        $node = Node::factory()->create([
            'name' => 'openclaw-preflight-order',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'tld' => 'openclaw-agent',
        ]);
        assignToolInstallApiRole($node, 'agent');
        $shell = new ToolInstallApiRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call(
            'POST',
            '/api/tools/openclaw/install',
            ['node' => $node->name],
            [],
            [],
            tool_install_api_server_headers(),
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.tool.name', 'openclaw')
            ->assertJsonPath('success.data.tool.process.name', 'openclaw-gateway')
            ->assertJsonPath('success.data.tool.process.runtime', 'systemd')
            ->assertJsonPath('success.data.tool.process.tool', 'openclaw')
            ->assertJsonPath('success.data.tool.process.action', 'configured');

        expect($shell->scripts)
            ->toHaveCount(5)
            ->and($shell->scripts[0])
            ->toContain("id -u 'agent'")
            ->and($shell->scripts[1])
            ->toContain('https://openclaw.ai/install.sh')
            ->and($shell->scripts[1])
            ->toContain('openclaw config set gateway.port 18789')
            ->and($shell->toolRowsPresent[0] ?? null)
            ->toBeFalse()
            ->and(NodeTool::query()->where('node_id', $node->id)->where('name', 'openclaw')->exists())
            ->toBeTrue();
    });

    it('configures the related singleton process by default when installing a service tool', function (): void {
        $caller = createToolInstallApiCallerNode();
        assignToolInstallApiRole($caller, 'gateway');
        $node = Node::factory()->create(['name' => 'app-oc-1', 'status' => 'active', 'platform' => 'ubuntu_24-04']);
        assignToolInstallApiRole($node, 'app-dev');
        app()->instance(RemoteShell::class, new ToolInstallApiRecordingShell);

        $response = $this->call(
            'POST',
            '/api/tools/opencode-cli/install',
            [
                'node' => 'app-oc-1',
            ],
            [],
            [],
            tool_install_api_server_headers(),
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.tool.name', 'opencode-cli')
            ->assertJsonPath('success.data.tool.process.name', 'opencode-server')
            ->assertJsonPath('success.data.tool.process.runtime', 'systemd')
            ->assertJsonPath('success.data.tool.process.tool', 'opencode-cli')
            ->assertJsonPath('success.data.tool.process.action', 'configured');

        $tool = NodeTool::query()
            ->where('node_id', $node->id)
            ->where('name', 'opencode-cli')
            ->first();

        expect($tool)
            ->not
            ->toBeNull();

        $process = DB::table('processes')
            ->where('node_id', $node->id)
            ->where('name', 'opencode-server')
            ->first();

        expect($process)
            ->not
            ->toBeNull()
            ->and($process->command)
            ->toBe('opencode serve -a')
            ->and($process->runtime)
            ->toBe('systemd')
            ->and($process->tool)
            ->toBe('opencode-cli');
    });

    it('skips process configuration when with_process is false', function (): void {
        $caller = createToolInstallApiCallerNode();
        assignToolInstallApiRole($caller, 'gateway');
        $node = Node::factory()->create(['name' => 'app-oc-2', 'status' => 'active', 'platform' => 'ubuntu_24-04']);
        assignToolInstallApiRole($node, 'app-dev');
        app()->instance(RemoteShell::class, new ToolInstallApiRecordingShell);

        $response = $this->call(
            'POST',
            '/api/tools/opencode-cli/install',
            [
                'node' => 'app-oc-2',
                'with_process' => false,
            ],
            [],
            [],
            tool_install_api_server_headers(),
        );

        $response->assertOk()
            ->assertJsonPath('success.data.tool.process', null);

        expect(
            DB::table('processes')->where('node_id', $node->id)->where('name', 'opencode-server')->exists(),
        )->toBeFalse();
    });

    it('converges the related process idempotently on re-install', function (): void {
        $caller = createToolInstallApiCallerNode();
        assignToolInstallApiRole($caller, 'gateway');
        $node = Node::factory()->create(['name' => 'app-oc-3', 'status' => 'active', 'platform' => 'ubuntu_24-04']);
        assignToolInstallApiRole($node, 'app-dev');
        app()->instance(RemoteShell::class, new ToolInstallApiRecordingShell);

        $payload = ['node' => 'app-oc-3'];
        $headers = tool_install_api_server_headers();

        $this->call('POST', '/api/tools/opencode-cli/install', $payload, [], [], $headers)->assertOk();

        $response = $this->call('POST', '/api/tools/opencode-cli/install', $payload, [], [], $headers);

        $response->assertOk()
            ->assertJsonPath('success.data.tool.process.action', 'converged');

        expect(DB::table('processes')->where('node_id', $node->id)->where('name', 'opencode-server')->count())->toBe(1);
    });

    it('configures the related singleton process by default when installing polyscope server', function (): void {
        $caller = createToolInstallApiCallerNode();
        assignToolInstallApiRole($caller, 'gateway');
        $node = Node::factory()->create(['name' => 'app-ps-1', 'status' => 'active', 'platform' => 'ubuntu_24-04']);
        assignToolInstallApiRole($node, 'app-dev');
        app()->instance(RemoteShell::class, new ToolInstallApiRecordingShell);

        $response = $this->call(
            'POST',
            '/api/tools/polyscope-server/install',
            [
                'node' => 'app-ps-1',
            ],
            [],
            [],
            tool_install_api_server_headers(),
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.tool.name', 'polyscope-server')
            ->assertJsonPath('success.data.tool.process.name', 'polyscope-server')
            ->assertJsonPath('success.data.tool.process.runtime', 'systemd')
            ->assertJsonPath('success.data.tool.process.tool', 'polyscope-server')
            ->assertJsonPath('success.data.tool.process.action', 'configured');

        $process = DB::table('processes')
            ->where('node_id', $node->id)
            ->where('name', 'polyscope-server')
            ->first();

        expect($process)
            ->not
            ->toBeNull()
            ->and($process->command)
            ->toBe('polyscope-server')
            ->and($process->runtime)
            ->toBe('systemd')
            ->and($process->tool)
            ->toBe('polyscope-server');
    });

    it('skips polyscope server process configuration when with_process is false', function (): void {
        $caller = createToolInstallApiCallerNode();
        assignToolInstallApiRole($caller, 'gateway');
        $node = Node::factory()->create(['name' => 'app-ps-2', 'status' => 'active', 'platform' => 'ubuntu_24-04']);
        assignToolInstallApiRole($node, 'app-dev');
        app()->instance(RemoteShell::class, new ToolInstallApiRecordingShell);

        $response = $this->call(
            'POST',
            '/api/tools/polyscope-server/install',
            [
                'node' => 'app-ps-2',
                'with_process' => false,
            ],
            [],
            [],
            tool_install_api_server_headers(),
        );

        $response->assertOk()
            ->assertJsonPath('success.data.tool.process', null);

        expect(
            DB::table('processes')->where('node_id', $node->id)->where('name', 'polyscope-server')->exists(),
        )->toBeFalse();
    });

    it('converges the polyscope server related process idempotently on re-install', function (): void {
        $caller = createToolInstallApiCallerNode();
        assignToolInstallApiRole($caller, 'gateway');
        $node = Node::factory()->create(['name' => 'app-ps-3', 'status' => 'active', 'platform' => 'ubuntu_24-04']);
        assignToolInstallApiRole($node, 'app-dev');
        app()->instance(RemoteShell::class, new ToolInstallApiRecordingShell);

        $payload = ['node' => 'app-ps-3'];
        $headers = tool_install_api_server_headers();

        $this->call('POST', '/api/tools/polyscope-server/install', $payload, [], [], $headers)->assertOk();

        $response = $this->call('POST', '/api/tools/polyscope-server/install', $payload, [], [], $headers);

        $response->assertOk()
            ->assertJsonPath('success.data.tool.process.action', 'converged');

        expect(DB::table('processes')->where('node_id', $node->id)->where('name', 'polyscope-server')->count())
            ->toBe(1);
    });

    it('does not treat an unassigned caller as gateway tool authority', function (): void {
        createToolInstallApiCallerNode([
            'name' => 'plain-gateway-install-api-caller',
        ]);
        $node = Node::factory()->create([
            'name' => 'app-install-api-1',
            'status' => 'active',
        ]);
        assignToolInstallApiRole($node, 'app-dev');
        $shell = new ToolInstallApiRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call(
            'POST',
            '/api/tools/php-cli/install',
            [
                'node' => 'app-install-api-1',
            ],
            [],
            [],
            tool_install_api_server_headers(),
        );

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed');

        expect(NodeTool::query()->count())->toBe(0)->and($shell->scripts)->toBe([]);
    });

    it('rejects invalid status before row writes or remote shell actions', function (): void {
        $caller = createToolInstallApiCallerNode();
        $node = Node::factory()->create(['name' => 'app-install-api-1', 'status' => 'active']);
        assignToolInstallApiRole($node, 'app-dev');
        grantToolInstallApiAccess($caller, $node);
        $shell = new ToolInstallApiRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call(
            'POST',
            '/api/tools/php-cli/install',
            [
                'node' => 'app-install-api-1',
                'status' => 'foo',
            ],
            [],
            [],
            tool_install_api_server_headers(),
        );

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'status')
            ->assertJsonPath('error.meta.value', 'foo')
            ->assertJsonPath('error.meta.reason', 'unsupported_value');

        expect(NodeTool::query()->count())->toBe(0)->and($shell->scripts)->toBe([]);
    });

    it('rejects runtime options for tool installs before side effects', function (
        array $payload,
        string $field,
        ?string $reason,
    ): void {
        $caller = createToolInstallApiCallerNode();
        $node = Node::factory()->create(['name' => 'app-install-api-1', 'status' => 'active']);
        assignToolInstallApiRole($node, 'app-dev');
        grantToolInstallApiAccess($caller, $node);
        $shell = new ToolInstallApiRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call(
            'POST',
            '/api/tools/php-cli/install',
            [
                'node' => 'app-install-api-1',
                ...$payload,
            ],
            [],
            [],
            tool_install_api_server_headers(),
        );

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', $field);

        if ($reason === null) {
            $response->assertJsonMissingPath('error.meta.reason');
        } else {
            $response->assertJsonPath('error.meta.reason', $reason);
        }

        expect(NodeTool::query()->count())->toBe(0)->and($shell->scripts)->toBe([]);
    })->with([
        'runtime' => [['runtime' => 'docker'], 'runtime', 'unsupported_field'],
    ]);

    it('rejects database and cache services as tool installs before side effects', function (string $tool): void {
        $caller = createToolInstallApiCallerNode();
        $node = Node::factory()->create(['name' => 'app-install-api-1', 'status' => 'active']);
        assignToolInstallApiRole($node, 'app-dev');
        grantToolInstallApiAccess($caller, $node);
        $shell = new ToolInstallApiRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call(
            'POST',
            "/api/tools/{$tool}/install",
            [
                'node' => 'app-install-api-1',
            ],
            [],
            [],
            tool_install_api_server_headers(),
        );

        $response
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'tool.unsupported_action')
            ->assertJsonPath('error.meta.tool', $tool)
            ->assertJsonPath('error.meta.action', 'install');

        expect(NodeTool::query()->count())->toBe(0)->and($shell->scripts)->toBe([]);
    })->with([
        'mysql',
        'postgres',
        'redis',
        'valkey',
    ]);

    it('persists claude-code install config with node default user and sanitized additional users', function (): void {
        $caller = createToolInstallApiCallerNode();
        assignToolInstallApiRole($caller, 'gateway');
        $node = Node::factory()->create([
            'name' => 'app-claude-1',
            'status' => 'active',
            'user' => 'deploy',
        ]);
        assignToolInstallApiRole($node, 'app-dev');
        $shell = new ToolInstallApiRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call(
            'POST',
            '/api/tools/claude-code/install',
            [
                'node' => 'app-claude-1',
                'config' => [
                    'install_users' => ['agent', 'deploy', 'agent'],
                ],
            ],
            [],
            [],
            tool_install_api_server_headers(),
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.tool.name', 'claude-code')
            ->assertJsonPath('success.data.tool.node', 'app-claude-1')
            ->assertJsonPath('success.data.tool.state', 'installed');

        $tool = NodeTool::query()
            ->where('node_id', $node->id)
            ->where('name', 'claude-code')
            ->firstOrFail();

        expect($tool->config)
            ->toBe([
                'default_user' => 'deploy',
                'install_users' => ['agent', 'deploy'],
            ])
            ->and($shell->scripts)
            ->toHaveCount(1)
            ->and($shell->scripts[0])
            ->toContain("sudo -u 'deploy' -H bash -lc")
            ->toContain("sudo -u 'agent' -H bash -lc")
            ->toContain('https://claude.ai/install.sh')
            ->toContain('claude --version');
    });

    it('persists agent coding CLI install config with node default user and sanitized additional users', function (
        string $tool,
        string $installNeedle,
        string $verifyNeedle,
    ): void {
        $caller = createToolInstallApiCallerNode();
        assignToolInstallApiRole($caller, 'gateway');
        $node = Node::factory()->create([
            'name' => "app-{$tool}-install",
            'status' => 'active',
            'user' => 'deploy',
        ]);
        assignToolInstallApiRole($node, 'app-dev');
        $shell = new ToolInstallApiRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call(
            'POST',
            "/api/tools/{$tool}/install",
            [
                'node' => "app-{$tool}-install",
                'config' => [
                    'install_users' => ['agent', 'deploy', 'agent'],
                ],
            ],
            [],
            [],
            tool_install_api_server_headers(),
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.tool.name', $tool)
            ->assertJsonPath('success.data.tool.node', "app-{$tool}-install")
            ->assertJsonPath('success.data.tool.state', 'installed');

        $nodeTool = NodeTool::query()
            ->where('node_id', $node->id)
            ->where('name', $tool)
            ->firstOrFail();

        expect($nodeTool->config)
            ->toBe([
                'default_user' => 'deploy',
                'install_users' => ['agent', 'deploy'],
            ])
            ->and($shell->scripts)
            ->toHaveCount(1)
            ->and($shell->scripts[0])
            ->toContain("sudo -u 'deploy' -H bash -lc")
            ->toContain("sudo -u 'agent' -H bash -lc")
            ->toContain($installNeedle)
            ->toContain($verifyNeedle)
            ->not->toContain('API_KEY')
            ->not->toContain('TOKEN')
            ->not->toContain('auth.json')
            ->not->toContain('keychain');
    })->with([
        'codex cli' => ['codex-cli', 'https://chatgpt.com/codex/install.sh', 'codex --version'],
        'grok cli' => ['grok-cli', 'https://x.ai/cli/install.sh', 'grok --version'],
        'antigravity cli' => ['antigravity-cli', 'https://antigravity.google/cli/install.sh', 'agy --version'],
        'cursor cli' => ['cursor-cli', 'https://cursor.com/install', 'cursor-agent'],
    ]);

    it('rejects unverified agent coding CLI install versions before side effects', function (string $tool): void {
        $caller = createToolInstallApiCallerNode();
        assignToolInstallApiRole($caller, 'gateway');
        $node = Node::factory()->create([
            'name' => "app-{$tool}-version",
            'status' => 'active',
            'user' => 'deploy',
        ]);
        assignToolInstallApiRole($node, 'app-dev');
        $shell = new ToolInstallApiRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call(
            'POST',
            "/api/tools/{$tool}/install",
            [
                'node' => "app-{$tool}-version",
                'version' => '1.2.3',
            ],
            [],
            [],
            tool_install_api_server_headers(),
        );

        $response
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'version')
            ->assertJsonPath('error.meta.reason', 'unsupported_field');

        expect(NodeTool::query()->where('node_id', $node->id)->where('name', $tool)->exists())
            ->toBeFalse()
            ->and($shell->scripts)
            ->toBe([]);
    })->with([
        'codex cli' => ['codex-cli'],
        'grok cli' => ['grok-cli'],
        'antigravity cli' => ['antigravity-cli'],
        'cursor cli' => ['cursor-cli'],
    ]);

    it('falls back to orbit when claude-code install target node user is missing', function (): void {
        $caller = createToolInstallApiCallerNode();
        assignToolInstallApiRole($caller, 'gateway');
        $node = Node::factory()->create([
            'name' => 'app-claude-2',
            'status' => 'active',
            'user' => null,
        ]);
        assignToolInstallApiRole($node, 'app-dev');
        $shell = new ToolInstallApiRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call(
            'POST',
            '/api/tools/claude-code/install',
            [
                'node' => 'app-claude-2',
            ],
            [],
            [],
            tool_install_api_server_headers(),
        );

        $response->assertOk();

        $tool = NodeTool::query()
            ->where('node_id', $node->id)
            ->where('name', 'claude-code')
            ->firstOrFail();

        expect($tool->config)
            ->toBe([
                'default_user' => 'orbit',
                'install_users' => [],
            ])
            ->and($shell->scripts[0])
            ->toContain("sudo -u 'orbit' -H bash -lc");
    });

    it('falls back to orbit when claude-code install target node user is unsafe', function (): void {
        $caller = createToolInstallApiCallerNode();
        assignToolInstallApiRole($caller, 'gateway');
        $node = Node::factory()->create([
            'name' => 'app-claude-unsafe-user',
            'status' => 'active',
            'user' => 'bad$user',
        ]);
        assignToolInstallApiRole($node, 'app-dev');
        $shell = new ToolInstallApiRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call(
            'POST',
            '/api/tools/claude-code/install',
            [
                'node' => 'app-claude-unsafe-user',
            ],
            [],
            [],
            tool_install_api_server_headers(),
        );

        $response->assertOk();

        $tool = NodeTool::query()
            ->where('node_id', $node->id)
            ->where('name', 'claude-code')
            ->firstOrFail();

        expect($tool->config)
            ->toBe([
                'default_user' => 'orbit',
                'install_users' => [],
            ])
            ->and($shell->scripts[0])
            ->toContain("sudo -u 'orbit' -H bash -lc")
            ->not->toContain('bad$user');
    });

    it('rejects install users for non-claude-code tools before row writes or remote shell actions', function (): void {
        $caller = createToolInstallApiCallerNode();
        assignToolInstallApiRole($caller, 'gateway');
        $node = Node::factory()->create([
            'name' => 'app-install-api-claude-scope',
            'status' => 'active',
        ]);
        assignToolInstallApiRole($node, 'app-dev');
        $shell = new ToolInstallApiRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call(
            'POST',
            '/api/tools/php-cli/install',
            [
                'node' => 'app-install-api-claude-scope',
                'config' => [
                    'install_users' => ['agent'],
                ],
            ],
            [],
            [],
            ['REMOTE_ADDR' => TOOL_INSTALL_API_CALLER_WG_IP],
        );

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'config.install_users')
            ->assertJsonPath('error.meta.reason', 'unsupported_field');

        expect(NodeTool::query()->count())->toBe(0)->and($shell->scripts)->toBe([]);
    });

    it('rejects unsafe claude-code install users before row writes or remote shell actions', function (string $username): void {
        $caller = createToolInstallApiCallerNode();
        assignToolInstallApiRole($caller, 'gateway');
        $node = Node::factory()->create([
            'name' => 'app-claude-3',
            'status' => 'active',
        ]);
        assignToolInstallApiRole($node, 'app-dev');
        $shell = new ToolInstallApiRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call(
            'POST',
            '/api/tools/claude-code/install',
            [
                'node' => 'app-claude-3',
                'config' => [
                    'install_users' => [$username],
                ],
            ],
            [],
            [],
            ['REMOTE_ADDR' => TOOL_INSTALL_API_CALLER_WG_IP],
        );

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'config.install_users')
            ->assertJsonPath('error.meta.reason', 'unsupported_value');

        expect(NodeTool::query()->count())->toBe(0)->and($shell->scripts)->toBe([]);
    })->with([
        'shell metacharacters' => ['agent;id'],
        'path traversal' => ['../root'],
        'empty string' => [''],
    ]);

    it('rejects install users for tools that do not support user-scoped installs before side effects', function (): void {
        $caller = createToolInstallApiCallerNode();
        assignToolInstallApiRole($caller, 'gateway');
        $node = Node::factory()->create(['name' => 'app-install-api-users', 'status' => 'active']);
        assignToolInstallApiRole($node, 'app-dev');
        $shell = new ToolInstallApiRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call(
            'POST',
            '/api/tools/composer/install',
            [
                'node' => 'app-install-api-users',
                'config' => [
                    'install_users' => ['agent'],
                ],
            ],
            [],
            [],
            ['REMOTE_ADDR' => TOOL_INSTALL_API_CALLER_WG_IP],
        );

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'config.install_users')
            ->assertJsonPath('error.meta.reason', 'unsupported_field');

        expect(NodeTool::query()->count())->toBe(0)->and($shell->scripts)->toBe([]);
    });

    it('rejects update-only version intent before side effects', function (array $payload): void {
        $caller = createToolInstallApiCallerNode();
        $node = Node::factory()->create(['name' => 'app-install-api-1', 'status' => 'active']);
        assignToolInstallApiRole($node, 'app-dev');
        grantToolInstallApiAccess($caller, $node);
        $shell = new ToolInstallApiRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call(
            'POST',
            '/api/tools/php-cli/install',
            [
                'node' => 'app-install-api-1',
                ...$payload,
            ],
            [],
            [],
            ['REMOTE_ADDR' => TOOL_INSTALL_API_CALLER_WG_IP],
        );

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.reason', 'unsupported_field');

        expect(NodeTool::query()->count())->toBe(0)->and($shell->scripts)->toBe([]);
    })->with([
        'expected_version' => [['expected_version' => '1.0.0']],
        'expected-version' => [['expected-version' => '1.0.0']],
    ]);
});

final class ToolInstallApiRecordingShell implements RemoteShell
{
    /**
     * @var list<string>
     */
    public array $scripts = [];

    /**
     * @var list<bool>
     */
    public array $toolRowsPresent = [];

    /**
     * @param  array<string, RemoteShellResult>  $resultsForScriptContaining
     */
    public function __construct(
        private readonly array $resultsForScriptContaining = [],
    ) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;
        $this->toolRowsPresent[] = NodeTool::query()->where('node_id', $node->id)->exists();

        foreach ($this->resultsForScriptContaining as $needle => $result) {
            if (str_contains($script, $needle)) {
                return $result;
            }
        }

        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}
