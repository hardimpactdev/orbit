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

const TOOL_START_PROCESS_CALLER_WG_IP = '10.6.0.201';

function createToolStartProcessGatewayCaller(): Node
{
    $caller = Node::factory()->create([
        'name' => 'tool-start-process-caller',
        'host' => TOOL_START_PROCESS_CALLER_WG_IP,
        'wireguard_address' => TOOL_START_PROCESS_CALLER_WG_IP,
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $caller->id,
        'role' => 'gateway',
        'status' => 'active',
    ]);

    return $caller;
}

function createToolStartProcessTarget(string $name = 'app-start-process-1'): Node
{
    $node = createTestAppHostNode([
        'name' => $name,
        'status' => 'active',
    ]);

    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'opencode-server',
        'expected_state' => 'installed',
    ]);

    Process::factory()->forOwner($node)->create([
        'name' => 'opencode-server',
        'tool' => 'opencode',
        'runtime' => ProcessRuntime::Systemd,
        'command' => 'opencode serve -a',
    ]);

    return $node;
}

describe('ToolStartController process-backed lifecycle', function (): void {
    it('starts the related process instead of mutating tool expected state', function (): void {
        createToolStartProcessGatewayCaller();
        $node = createToolStartProcessTarget();
        $shell = new ToolStartProcessRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call('POST', '/api/tools/opencode-server/start', [
            'node' => 'app-start-process-1',
        ], [], [], ['REMOTE_ADDR' => TOOL_START_PROCESS_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.tool.name', 'opencode-server')
            ->assertJsonPath('success.data.tool.node', 'app-start-process-1');

        expect($shell->scripts)->toBe(["sudo systemctl start 'opencode-server.service'"])
            ->and(NodeTool::query()->where('node_id', $node->id)->where('name', 'opencode-server')->value('expected_state'))->toBe('installed');
    });

    it('fails explicitly when the tool has no related lifecycle process', function (): void {
        createToolStartProcessGatewayCaller();
        $node = createTestAppHostNode([
            'name' => 'app-start-process-1',
            'status' => 'active',
        ]);
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'opencode-server',
            'expected_state' => 'installed',
        ]);
        $shell = new ToolStartProcessRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call('POST', '/api/tools/opencode-server/start', [
            'node' => 'app-start-process-1',
        ], [], [], ['REMOTE_ADDR' => TOOL_START_PROCESS_CALLER_WG_IP]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'tool.process_missing')
            ->assertJsonPath('error.meta.tool', 'opencode-server')
            ->assertJsonPath('error.meta.node', 'app-start-process-1');

        expect($shell->scripts)->toBe([]);
    });

});

final class ToolStartProcessRecordingShell implements RemoteShell
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
