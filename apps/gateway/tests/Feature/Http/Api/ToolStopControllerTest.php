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

const TOOL_STOP_PROCESS_CALLER_WG_IP = '10.6.0.202';

function createToolStopProcessGatewayCaller(): Node
{
    $caller = Node::factory()->create([
        'name' => 'tool-stop-process-caller',
        'host' => TOOL_STOP_PROCESS_CALLER_WG_IP,
        'wireguard_address' => TOOL_STOP_PROCESS_CALLER_WG_IP,
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $caller->id,
        'role' => 'gateway',
        'status' => 'active',
    ]);

    return $caller;
}

function createToolStopProcessTarget(): Node
{
    $node = createTestAppHostNode([
        'name' => 'app-stop-process-1',
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

describe('ToolStopController process-backed lifecycle', function (): void {
    it('stops the related process instead of mutating tool expected state', function (): void {
        createToolStopProcessGatewayCaller();
        $node = createToolStopProcessTarget();
        $shell = new ToolStopProcessRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call('POST', '/api/tools/opencode-server/stop', [
            'node' => 'app-stop-process-1',
        ], [], [], ['REMOTE_ADDR' => TOOL_STOP_PROCESS_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.tool.name', 'opencode-server')
            ->assertJsonPath('success.data.tool.node', 'app-stop-process-1');

        expect($shell->scripts)->toBe(["sudo systemctl stop 'opencode-server.service'"])
            ->and(NodeTool::query()->where('node_id', $node->id)->where('name', 'opencode-server')->value('expected_state'))->toBe('running');
    });
});

final class ToolStopProcessRecordingShell implements RemoteShell
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
