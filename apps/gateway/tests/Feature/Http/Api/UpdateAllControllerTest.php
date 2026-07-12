<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

const UPDATE_ALL_CALLER_WG_IP = '10.6.0.99';

beforeEach(function (): void {
    app()->instance(
        \App\Services\RemoteShell\RunsInternalCommands::class,
        app(\App\Services\RemoteShell\RemoteLocalExecutor::class),
    );
    createTestGatewayNode([
        'name' => 'gateway',
        'host' => 'gateway',
        'orbit_path' => '/home/gateway/orbit',
        'status' => 'active',
        'wireguard_address' => UPDATE_ALL_CALLER_WG_IP,
    ]);
});

it('updates the local checkout for a gateway caller', function (): void {
    Process::fake(['*' => Process::result()]);
    Process::preventStrayProcesses();

    $this
        ->call('POST', '/api/update/all', server: ['REMOTE_ADDR' => UPDATE_ALL_CALLER_WG_IP])
        ->assertOk()
        ->assertJsonPath('success.data.updates.0.target', 'gateway')
        ->assertJsonPath('success.data.updates.0.status', 'completed')
        ->assertJsonPath('success.meta.summary.total', 1);

    Http::assertNothingSent();
});

it('updates workload nodes with three sequential Agent-pushed stages', function (): void {
    update_all_agent_node();
    Process::fake(['*' => Process::result()]);
    Http::fake(['http://10.44.0.30:9477/v1/commands' => update_all_agent_response()]);

    $this
        ->call('POST', '/api/update/all', server: ['REMOTE_ADDR' => UPDATE_ALL_CALLER_WG_IP])
        ->assertOk()
        ->assertJsonPath('success.data.updates.1.target', 'beast')
        ->assertJsonPath('success.data.updates.1.status', 'completed')
        ->assertJsonPath('success.meta.summary.failed', 0);

    Http::assertSentCount(3);
    Http::assertSent(function (Request $request): bool {
        $input = json_decode((string) $request['input'], true, flags: JSON_THROW_ON_ERROR);

        return $request->url() === 'http://10.44.0.30:9477/v1/commands'
        && $request['argv'][0] === 'internal:tool:run-script'
        && ($input['tool'] ?? null) === 'orbit'
        && ($input['action'] ?? null) === 'update'
        && ! $request->hasHeader('X-Orbit-Node-Transport-Preference');
    });
});

it('reports an Agent-push failure for a workload node without retrying over SSH', function (): void {
    update_all_agent_node();
    Process::fake(['*' => Process::result()]);
    Http::fake([
        'http://10.44.0.30:9477/v1/commands' => Http::response(['error' => 'unreachable'], 503),
    ]);

    $this
        ->call('POST', '/api/update/all', server: ['REMOTE_ADDR' => UPDATE_ALL_CALLER_WG_IP])
        ->assertOk()
        ->assertJsonPath('success.data.updates.1.status', 'failed')
        ->assertJsonPath('success.meta.summary.failed', 1);

    Http::assertSentCount(1);
});

function update_all_agent_node(): Node
{
    $node = Node::factory()->create([
        'name' => 'beast',
        'host' => 'beast',
        'orbit_path' => '/home/nckrtl/orbit',
        'wireguard_address' => '10.44.0.30',
        'platform' => 'ubuntu_24-04',
        'status' => 'active',
        'managed' => true,
    ]);
    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-dev',
        'status' => 'active',
    ]);

    return $node;
}

function update_all_agent_response(): mixed
{
    return Http::response([
        'transport' => 'agent-push',
        'operation_id' => 'tool.update',
        'binary' => 'orbit',
        'status' => 'succeeded',
        'exit_code' => 0,
        'frames' => [
            [
                'type' => 'stdout',
                'message' => json_encode([
                    'success' => ['data' => [
                        'exit_code' => 0,
                        'stdout' => '',
                        'stderr' => '',
                        'duration_ms' => 1,
                    ]],
                ], JSON_THROW_ON_ERROR),
            ],
            ['type' => 'exit', 'message' => '0'],
        ],
    ]);
}
