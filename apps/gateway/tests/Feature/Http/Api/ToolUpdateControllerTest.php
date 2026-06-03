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

const TOOL_UPDATE_PROCESS_CALLER_WG_IP = '10.6.0.204';

function createToolUpdateProcessGatewayCaller(): Node
{
    $caller = Node::factory()->create([
        'name' => 'tool-update-process-caller',
        'host' => TOOL_UPDATE_PROCESS_CALLER_WG_IP,
        'wireguard_address' => TOOL_UPDATE_PROCESS_CALLER_WG_IP,
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $caller->id,
        'role' => 'gateway',
        'status' => 'active',
    ]);

    return $caller;
}

describe('ToolUpdateController process boundary', function (): void {
    it('updates the tool capability without restarting a related process implicitly', function (): void {
        createToolUpdateProcessGatewayCaller();
        $node = createTestAppHostNode([
            'name' => 'app-update-process-1',
            'status' => 'active',
        ]);
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'redis',
            'expected_state' => 'running',
            'config' => ['compose_path' => '/opt/orbit/docker-compose.yml'],
        ]);
        Process::factory()->forOwner($node)->create([
            'name' => 'redis',
            'tool' => 'redis',
            'runtime' => ProcessRuntime::Systemd,
            'command' => 'redis-server',
        ]);
        $shell = new ToolUpdateProcessRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call('POST', '/api/tools/redis/update', [
            'node' => 'app-update-process-1',
        ], [], [], ['REMOTE_ADDR' => TOOL_UPDATE_PROCESS_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.tool.name', 'redis')
            ->assertJsonPath('success.data.tool.node', 'app-update-process-1');

        expect($shell->scripts)->toHaveCount(1)
            ->and($shell->scripts[0])->toContain("docker compose -f '/opt/orbit/docker-compose.yml' pull 'redis'")
            ->and($shell->scripts[0])->not->toContain('systemctl restart')
            ->and($shell->scripts[0])->not->toContain('supervisorctl restart');
    });
});

final class ToolUpdateProcessRecordingShell implements RemoteShell
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
