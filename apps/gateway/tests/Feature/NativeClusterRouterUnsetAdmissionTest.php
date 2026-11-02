<?php

declare(strict_types=1);

use App\Actions\Routes\AdmitClusterRouterOperationAction;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Operations\OperationStatus;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $this->manifest = tempnam(sys_get_temp_dir(), 'router-unset-agent-');
    file_put_contents($this->manifest, json_encode(['commit' => str_repeat('b', 40), 'version' => '0.5.0', 'artifacts' => ['linux-x86_64' => ['sha256' => str_repeat('a', 64), 'url' => 'https://example.test/agent']]], JSON_THROW_ON_ERROR));
    app()->instance(AgentDelivery::class, new AgentDelivery($this->manifest, sys_get_temp_dir()));
    app()->instance(ManagedUserAccountResolver::class, new class implements ManagedUserAccountResolver
    {
        public function resolve(Node $node): ManagedUserAccount
        {
            return new ManagedUserAccount('orbit', 'orbit', '/home/orbit');
        }
    });
    $this->mock(LeafCertificateSigner::class)->shouldReceive('rootCertificate')->andReturn('public-root');
    $this->socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $this->socket);
    config(['swamp.run_token' => 'fixture.fake']);
});

afterEach(function (): void {
    unlink($this->manifest);
});

it('clears a Router that left nothing on any Node at once, with no run', function (): void {
    $cluster = Cluster::query()->create(['name' => 'Empty router', 'state' => 'inactive']);
    $node = Node::query()->create(['name' => 'empty-router', 'cluster_id' => $cluster->id, 'public_ssh_host' => '192.0.2.51', 'wireguard_ip' => '10.44.0.51',
        'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-host-key']);
    $role = $node->roles()->create(['role' => 'router', 'cluster_id' => $cluster->id, 'status' => 'active']);

    $operation = app(AdmitClusterRouterOperationAction::class)->unset($cluster);

    // R1: no Route left Router sites, certificates or LAN rules, so no Node has anything to remove.
    expect($operation->kind)->toBe('cluster.router_unset')
        ->and($operation->status)->toBe(OperationStatus::Succeeded)
        ->and($operation->result)->toBe(['ready' => true, 'selected' => true, 'cleanup_complete' => true])
        ->and($operation->input['cluster_router_plan']['check_nodes'])->toBe([])
        ->and($operation->state ?? [])->not->toHaveKey('swamp_run_id')
        ->and($this->socket->sent)->toHaveCount(0)
        ->and(NodeRole::query()->find($role->id))->toBeNull()
        ->and(Operation::query()->count())->toBe(1);
});
