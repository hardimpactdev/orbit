<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Processes\ProcessRuntime;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Models\Process;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const TOOL_RESTART_PROCESS_CALLER_WG_IP = '10.6.0.203';

function createToolRestartProcessGatewayCaller(): Node
{
    $caller = Node::factory()->create([
        'name' => 'tool-restart-process-caller',
        'host' => TOOL_RESTART_PROCESS_CALLER_WG_IP,
        'wireguard_address' => TOOL_RESTART_PROCESS_CALLER_WG_IP,
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $caller->id,
        'role' => 'gateway',
        'status' => 'active',
    ]);

    return $caller;
}

function createToolRestartProcessTarget(): Node
{
    $node = createTestAppHostNode([
        'name' => 'app-restart-process-1',
        'status' => 'active',
    ]);

    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'opencode-server',
        'expected_state' => 'running',
    ]);

    Process::factory()->forOwner($node)->create([
        'name' => 'opencode-server',
        'tool' => 'opencode',
        'runtime' => ProcessRuntime::Systemd,
        'command' => 'opencode serve -a',
    ]);

    return $node;
}

describe('ToolRestartController process-backed lifecycle', function (): void {
    it('restarts the related process', function (): void {
        createToolRestartProcessGatewayCaller();
        createToolRestartProcessTarget();
        $shell = new ToolRestartProcessRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call('POST', '/api/tools/opencode-server/restart', [
            'node' => 'app-restart-process-1',
        ], [], [], ['REMOTE_ADDR' => TOOL_RESTART_PROCESS_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.tool.name', 'opencode-server')
            ->assertJsonPath('success.data.tool.node', 'app-restart-process-1');

        expect($shell->scripts)->toBe(["sudo systemctl restart 'opencode-server.service'"]);
    });

    it('fails explicitly when multiple related processes exist on the target node', function (): void {
        createToolRestartProcessGatewayCaller();
        $node = createToolRestartProcessTarget();
        Process::factory()->forOwner($node)->create([
            'name' => 'opencode-worker',
            'tool' => 'opencode',
            'runtime' => ProcessRuntime::Systemd,
            'command' => 'opencode serve --worker',
        ]);
        $shell = new ToolRestartProcessRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call('POST', '/api/tools/opencode-server/restart', [
            'node' => 'app-restart-process-1',
        ], [], [], ['REMOTE_ADDR' => TOOL_RESTART_PROCESS_CALLER_WG_IP]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'tool.process_ambiguous')
            ->assertJsonPath('error.meta.tool', 'opencode-server')
            ->assertJsonPath('error.meta.node', 'app-restart-process-1')
            ->assertJsonPath('error.meta.processes', ['opencode-server', 'opencode-worker']);

        expect($shell->scripts)->toBe([]);
    });
});

final class ToolRestartProcessRecordingShell implements RemoteShell
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
