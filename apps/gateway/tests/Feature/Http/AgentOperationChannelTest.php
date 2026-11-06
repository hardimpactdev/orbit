<?php

declare(strict_types=1);

use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WebSocket\WebSocketCredentialManager;
use App\Domain\WebSocket\WebSocketCredentials;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(LazilyRefreshDatabase::class);

function agent_channel_node(): Node
{
    return Node::withoutEvents(function (): Node {
        $node = Node::query()->create([
            'name' => 'app-dev', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'public_ssh_host' => '10.44.0.2',
            'wireguard_ip' => '10.44.0.2', 'user' => 'orbit',
        ]);
        $node->forceFill(['ssh_host_fingerprint' => 'SHA256:fingerprint', 'agent_secret_hash' => hash('sha256', 'agent-secret')])->save();

        return $node;
    });
}

function agent_channel_auth(Node $node, string $channel): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])
        ->withToken('agent-secret')
        ->postJson('/api/v1/agent/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => $channel]);
}

describe('POST /api/v1/agent/broadcasting/auth', function (): void {
    beforeEach(function (): void {
        app()->instance(WebSocketCredentialManager::class, new class implements WebSocketCredentialManager
        {
            public function ensure(Node $node): never
            {
                throw new LogicException('Not used.');
            }

            public function current(): WebSocketCredentials
            {
                return new WebSocketCredentials('app', 'key', 'secret', '10.44.0.5');
            }

            public function purge(Node $node, bool $runtimeStopped = false): void {}
        });
        $this->node = agent_channel_node();
    });

    it('signs the presence channel of the agent Node', function (): void {
        agent_channel_auth($this->node, 'presence-node.'.$this->node->id)->assertOk();
    });

    it('refuses the channel of an operation that runs on the Node, because no agent publishes on it', function (): void {
        $operation = Operation::query()->create(['kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => 1, 'node_id' => $this->node->id,
            'status' => OperationStatus::Running, 'state' => [], 'input' => []]);

        agent_channel_auth($this->node, 'private-operation.'.$operation->id)
            ->assertForbidden()
            ->assertJsonPath('error.code', 'agent.channel_forbidden');
    });
});
