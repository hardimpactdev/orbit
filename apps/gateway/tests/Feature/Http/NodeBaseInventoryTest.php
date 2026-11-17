<?php

declare(strict_types=1);

use App\Domain\AgentView\AgentNodeView;
use App\Domain\AgentView\AgentStateView;
use App\Domain\AgentView\AgentViewFreshness;
use App\Domain\AgentView\AgentViewSubscriberHealth;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\NodeFacts\NodeFactKind;
use App\Domain\Nodes\NodeBaseInventoryProjection;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\DatabaseServer;
use App\Models\Node;
use App\Models\NodeFact;
use App\Models\Operation;
use App\Models\Process as ProcessModel;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

function inventoryNode(int $id, string $ip): Node
{
    return Node::withoutEvents(fn (): Node => Node::query()->forceCreate([
        'id' => $id, 'name' => 'inventory-'.$id, 'status' => 'active', 'platform' => 'linux',
        'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => $ip, 'user' => 'orbit',
        'ssh_host_fingerprint' => 'fixture-fingerprint', 'ssh_host_key_type' => 'ssh-ed25519',
        'ssh_host_key' => 'pinned', 'architecture' => 'x86_64',
        'agent_secret_hash' => hash('sha256', 'private-agent-fixture'),
    ]));
}

describe('rollout base inventory HTTP admission', function (): void {
    beforeEach(function (): void {
        $this->caller = inventoryNode(2, '10.44.0.2');
        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);
        Http::preventStrayRequests();
        Process::preventStrayProcesses();
    });

    it('refuses callers without target access for each protected Node', function (int $id): void {
        inventoryNode($id, '10.44.0.3');
        $this->call('POST', "/api/v1/nodes/{$id}/base-inventory", server: ['HTTP_ACCEPT' => 'application/json'])
            ->assertForbidden()->assertJsonPath('error.code', 'node_access.required');
        expect(Operation::query()->count())->toBe(0);
    })->with([4, 17, 209]);

    it('refuses query and raw body inputs even for a granted target', function (string $query, ?string $body): void {
        $target = inventoryNode(17, '10.44.0.3');
        $this->caller->accessibleNodes()->attach($target);
        $this->call('POST', '/api/v1/nodes/17/base-inventory'.$query, server: [
            'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
        ], content: $body)->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
        expect(Operation::query()->count())->toBe(0);
    })->with([
        'path query' => ['?path=/etc/shadow', null],
        'malformed JSON' => ['', '{'],
        'empty object' => ['', '{}'],
        'plain text' => ['', 'hostname'],
    ]);

    it('refuses a granted Node outside the fixed rollout', function (): void {
        $target = inventoryNode(21, '10.44.0.3');
        $this->caller->accessibleNodes()->attach($target);
        $this->call('POST', '/api/v1/nodes/21/base-inventory', server: ['HTTP_ACCEPT' => 'application/json'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'node.base_inventory_unsupported');
        expect(Operation::query()->count())->toBe(0);
    });

    it('records one Node-bound intent and keeps the verifier in the private vault frame', function (int $id, string $profile): void {
        Queue::fake();
        config([
            'swamp.run_token' => 'test-token', 'swamp.read_token' => 'read-token', 'swamp.address' => 'swamp.test',
            'swamp.ca_path' => '/test/ca.pem',
            'swamp.ssh_public_key' => 'ssh-ed25519 '.base64_encode(pack('N', 11).'ssh-ed25519'.pack('N', 32).str_repeat('k', 32)),
        ]);
        $frames = [];
        $socket = Mockery::mock(WebSocketClient::class);
        $socket->shouldReceive('connect', 'close')->andReturnNull();
        $socket->shouldReceive('isConnected')->andReturnTrue();
        $socket->shouldReceive('send')->once()->andReturnUsing(function (array $frame) use (&$frames): void {
            $frames[] = $frame;
        });
        $socket->shouldReceive('receive')->andReturnUsing(function () use (&$frames): array {
            $frame = end($frames);

            return [['type' => 'vault.put', 'id' => $frame['id'], 'payload' => ['data' => [
                'vaultName' => 'orbit-control-inputs', 'secretKey' => $frame['payload']['key'], 'vaultType' => 'local_encryption',
            ]]]];
        });
        app()->instance(WebSocketClient::class, $socket);
        $this->mock(LeafCertificateSigner::class, fn ($mock) => $mock->shouldReceive('rootCertificate')->andReturn("-----BEGIN CERTIFICATE-----\nfixture\n-----END CERTIFICATE-----\n"));
        $control = inventoryNode(1, '10.44.0.1');
        $control->roles()->create(['role' => 'swamp', 'status' => 'active']);
        $control->roles()->create(['role' => 'gateway', 'status' => 'active']);
        $target = inventoryNode($id, '10.44.0.3');
        $this->caller->accessibleNodes()->attach($target);
        $backing = [];
        if ($id === 17) {
            $process = ProcessModel::query()->create(['owner_type' => Node::class, 'owner_id' => $id, 'name' => 'primary-storage', 'runtime' => 'docker',
                'runtime_config' => ['image' => 'postgres:18', 'command' => ['postgres']], 'desired_state' => 'running', 'status' => 'active', 'keep_alive' => true]);
            DatabaseServer::query()->create(['node_id' => $id, 'process_id' => $process->id, 'slug' => 'primary-storage',
                'driver' => 'pgsql', 'tag' => '18', 'port' => 5432, 'status' => 'active', 'root_password' => 'fixture-password']);
            $backing[] = 'orbit-process-'.$process->id.'-primary-storage';
        }
        $headers = ['HTTP_ACCEPT' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => 'rollout-inventory-'.$id];

        $response = $this->call('POST', "/api/v1/nodes/{$id}/base-inventory", server: $headers);
        expect($response->status())->toBe(202, $response->getContent());
        $response->assertJsonPath('data.target_id', $id);
        $operation = Operation::query()->sole();
        $this->call('POST', "/api/v1/nodes/{$id}/base-inventory", server: $headers)
            ->assertAccepted()->assertJsonPath('data.id', $operation->id);
        $verifier = hash('sha256', 'private-agent-fixture');
        $private = json_decode($frames[0]['payload']['value'], true, flags: JSON_THROW_ON_ERROR);
        expect($frames)->toHaveCount(1)
            ->and($frames[0]['type'])->toBe('vault.put')
            ->and($private['verifier'])->toBe($verifier)
            ->and($private['admission']['operation_id'])->toBe($operation->id)
            ->and($private['admission']['node_id'])->toBe($id)
            ->and($private['admission']['profile'])->toBe($profile)
            ->and($private['admission']['target']['node_id'])->toBe($id)
            ->and($private['admission']['target']['host_keys'])->toBe(['ssh-ed25519 pinned'])
            ->and($private['admission']['backing_containers'])->toBe($backing)
            ->and(json_encode($operation->input))->not->toContain($verifier, 'private-agent-fixture')
            ->and($response->getContent())->not->toContain($verifier, 'execution_secrets');
    })->with([[4, 'router'], [17, 'services'], [209, 'swamp']]);

    it('only accepts native operation results', function (): void {
        $operation = new Operation(['kind' => 'node.base_inventory']);
        expect(app(NodeBaseInventoryProjection::class)->native($operation))->toBeTrue();
    });
    it('does not mark retained Doctor facts fresh just because the agent is online', function (): void {
        $node = inventoryNode(17, '10.44.0.3');
        $clock = now();
        $viewTime = (float) $clock->format('U.u');
        $view = $this->mock(AgentStateView::class);
        $view->shouldReceive('subscriber')->andReturn(new AgentViewSubscriberHealth($viewTime, true, true, 1));
        $view->shouldReceive('node')->andReturn(new AgentNodeView($node->id, AgentViewFreshness::Fresh, receivedAt: $viewTime));
        $view->shouldReceive('lastSeen')->with(17)->andReturn($viewTime);
        NodeFact::query()->create(['node_id' => 17, 'kind' => NodeFactKind::Host, 'digest' => str_repeat('a', 64),
            'data' => ['os' => 'linux', 'arch' => 'x86_64', 'filesystems' => [['mount' => '/', 'size' => 100_000_000_000, 'free' => 90_000_000_000]]],
            'observed_at' => $clock->copy()->subHour(), 'received_at' => $clock]);
        $run = '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
        $operation = Operation::query()->create(['kind' => 'node.base_inventory', 'node_id' => 17,
            'target_type' => 'node', 'target_id' => 17, 'status' => 'running',
            'input' => ['profile' => 'services'], 'state' => ['swamp_run_id' => $run]]);
        $projection = app(NodeBaseInventoryProjection::class);
        $receipt = $projection->apply($operation, ['run_id' => $run, 'status' => 'succeeded',
            'started_at' => $clock->toISOString(), 'finished_at' => $clock->toISOString(),
            'result' => ['node_id' => 17, 'profile' => 'services', 'captured_at' => $clock->toISOString()]]);
        expect($receipt->result['doctor']['agent_fresh_for_capture'])->toBeTrue()
            ->and($receipt->result['doctor']['facts']['host']['fresh_for_capture'])->toBeFalse()
            ->and($receipt->result['doctor']['doctor_fresh_for_capture'])->toBeFalse();
    });
});
