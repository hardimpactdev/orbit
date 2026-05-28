<?php

declare(strict_types=1);

use App\Contracts\RemoteShellStream;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const TOOL_LOGS_STREAM_CALLER_WG_IP = '10.6.0.95';

function createToolLogsStreamCallerNode(array $overrides = []): Node
{
    $node = Node::factory()->create(array_merge([
        'name' => 'caller',
        'host' => TOOL_LOGS_STREAM_CALLER_WG_IP,
        'wireguard_address' => TOOL_LOGS_STREAM_CALLER_WG_IP], $overrides));

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'gateway',
        'status' => 'active']);

    return $node;
}

describe('ToolLogsStreamController', function (): void {
    it('streams followed tool log output through the gateway API', function (): void {
        createToolLogsStreamCallerNode();
        $node = createTestAppHostNode(['name' => 'app-1', 'status' => 'active']);
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'supervisor',
            'expected_state' => 'running']);
        $stream = new ToolLogsStreamRecordingRemoteStream;
        app()->instance(RemoteShellStream::class, $stream);

        $response = $this->call(
            'GET',
            '/api/tools/supervisor/logs/stream?node=app-1&lines=1',
            [],
            [],
            [],
            ['REMOTE_ADDR' => TOOL_LOGS_STREAM_CALLER_WG_IP],
        );

        $response->assertStreamed()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertStreamedContent("streamed supervisor line\n");

        expect($stream->scripts)->toBe(["sudo journalctl _SYSTEMD_UNIT='supervisor.service' + SYSLOG_IDENTIFIER='supervisor' -n 1 -f --no-pager --output=short-iso"]);
    });

    it('returns a gateway error before opening the stream when logs are unsupported', function (): void {
        createToolLogsStreamCallerNode();
        $node = createTestAppHostNode(['name' => 'app-1', 'status' => 'active']);
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'gh',
            'expected_state' => 'running']);
        $stream = new ToolLogsStreamRecordingRemoteStream;
        app()->instance(RemoteShellStream::class, $stream);

        $response = $this->call(
            'GET',
            '/api/tools/gh/logs/stream?node=app-1',
            [],
            [],
            [],
            ['REMOTE_ADDR' => TOOL_LOGS_STREAM_CALLER_WG_IP],
        );

        $response->assertStatus(400)
            ->assertJsonPath('error.code', 'tool.unsupported_action')
            ->assertJsonPath('error.meta.action', 'logs');

        expect($stream->scripts)->toBe([]);
    });
});

final class ToolLogsStreamRecordingRemoteStream implements RemoteShellStream
{
    /**
     * @var list<string>
     */
    public array $scripts = [];

    /**
     * @param  callable(string): void  $onOutput
     * @param  array<string, mixed>  $options
     */
    public function stream(Node $node, string $script, callable $onOutput, array $options = []): int
    {
        $this->scripts[] = $script;
        $onOutput("streamed supervisor line\n");

        return 0;
    }
}
