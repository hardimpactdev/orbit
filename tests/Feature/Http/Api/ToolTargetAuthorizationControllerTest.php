<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Models\NodeTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const TOOL_TARGET_AUTH_CALLER_WG_IP = '10.6.0.95';

function createToolTargetAuthCaller(): Node
{
    return Node::factory()->create([
        'name' => 'caller',
        'role' => 'control',
        'host' => TOOL_TARGET_AUTH_CALLER_WG_IP,
        'wireguard_address' => TOOL_TARGET_AUTH_CALLER_WG_IP,
    ]);
}

function grantToolTargetAuthAccess(Node $caller, Node $appNode): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

describe('tool API target authorization', function (): void {
    it('rejects hidden target selectors before tool side effects', function (string $method, string $uri, array $parameters): void {
        $caller = createToolTargetAuthCaller();
        $visibleNode = Node::factory()->create(['name' => 'visible-node', 'role' => 'app', 'status' => 'active']);
        $hiddenNode = Node::factory()->create(['name' => 'hidden-node', 'role' => 'app', 'status' => 'active']);
        grantToolTargetAuthAccess($caller, $visibleNode);

        NodeTool::factory()->create([
            'node_id' => $hiddenNode->id,
            'name' => str_contains($uri, 'polyscope-server') ? 'polyscope-server' : 'redis',
            'expected_state' => 'running',
            'config' => ['compose_path' => '/opt/orbit/docker-compose.yml'],
            'credentials' => [
                'fields' => [
                    'password' => 'secret',
                ],
            ],
        ]);

        $shell = new ToolTargetAuthorizationRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call($method, $uri, $parameters, [], [], ['REMOTE_ADDR' => TOOL_TARGET_AUTH_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed');

        expect($shell->scripts)->toBe([]);
    })->with([
        'install' => ['POST', '/api/tools/redis/install', ['node' => 'hidden-node']],
        'update' => ['POST', '/api/tools/redis/update', ['node' => 'hidden-node']],
        'credentials' => ['GET', '/api/tools/redis/credentials', ['node' => 'hidden-node']],
        'logs' => ['GET', '/api/tools/redis/logs', ['node' => 'hidden-node']],
        'logs stream' => ['GET', '/api/tools/redis/logs/stream', ['node' => 'hidden-node']],
        'reload' => ['POST', '/api/tools/redis/reload', ['node' => 'hidden-node']],
        'remove' => ['DELETE', '/api/tools/redis', ['node' => 'hidden-node', 'destructive_consent' => true]],
        'reconfigure' => ['POST', '/api/tools/polyscope-server/reconfigure', ['node' => 'hidden-node']],
        'restart' => ['POST', '/api/tools/redis/restart', ['node' => 'hidden-node']],
        'start' => ['POST', '/api/tools/redis/start', ['node' => 'hidden-node']],
        'stop' => ['POST', '/api/tools/redis/stop', ['node' => 'hidden-node']],
    ]);

    it('uses the only visible target when no selector is supplied', function (): void {
        $caller = createToolTargetAuthCaller();
        $visibleNode = Node::factory()->create(['name' => 'visible-node', 'role' => 'app', 'status' => 'active']);
        $hiddenNode = Node::factory()->create(['name' => 'hidden-node', 'role' => 'app', 'status' => 'active']);
        grantToolTargetAuthAccess($caller, $visibleNode);

        NodeTool::factory()->create([
            'node_id' => $visibleNode->id,
            'name' => 'redis',
            'credentials' => [
                'fields' => [
                    'password' => 'visible-secret',
                ],
            ],
        ]);
        NodeTool::factory()->create([
            'node_id' => $hiddenNode->id,
            'name' => 'redis',
            'credentials' => [
                'fields' => [
                    'password' => 'hidden-secret',
                ],
            ],
        ]);

        $response = $this->call('GET', '/api/tools/redis/credentials', [], [], [], ['REMOTE_ADDR' => TOOL_TARGET_AUTH_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.credentials.node', 'visible-node')
            ->assertJsonPath('success.data.credentials.fields.password', 'visible-secret');
    });
});

final class ToolTargetAuthorizationRecordingShell implements RemoteShell
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
