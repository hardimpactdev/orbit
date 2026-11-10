<?php

declare(strict_types=1);

use App\Actions\AppDev\AdmitPrivateDnsPublicationAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Routes\ClusterRouterTransition;
use App\Domain\Routes\NativeClusterRouterProjection;
use App\Infrastructure\Swamp\SwampDispatch;
use App\Jobs\AppDev\PublishPrivateDns;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

/** A running Cluster Router transition. */
function clusterRouterSetOperation(string $kind = 'cluster.router_set'): Operation
{
    $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'router', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '10.44.0.5',
        'wireguard_ip' => '10.44.0.5', 'user' => 'orbit']));

    return Operation::query()->create(['kind' => $kind, 'target_type' => 'cluster', 'target_id' => 1, 'node_id' => $node->id,
        'status' => 'running', 'sequence' => 1, 'attempts' => 1, 'state' => ['swamp_run_id' => 'fixture-router'],
        'input' => ['cluster_router' => ['candidate' => null, 'old_nodes' => []]]]);
}

/** @return array<string, mixed> */
function clusterRouterTerminal(string $status): array
{
    return ['run_id' => 'fixture-router', 'status' => $status, 'started_at' => now()->subSeconds(5)->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'failure' => ['failed_step' => 'Probe the workloads-0', 'detail' => 'a probe failed', 'stderr_recorded' => true],
        'result' => ['steps' => ['Restore the before state' => ['status' => 'succeeded']]]];
}

describe('Private DNS after a failed run', function (): void {
    it('retains the candidate when DNS publication may have selected consumers before interruption', function (string $status, string $dnsStatus): void {
        Queue::fake();
        $operation = clusterRouterSetOperation();
        $cluster = Cluster::query()->create(['name' => 'dev']);
        Node::query()->whereKey($operation->node_id)->update(['cluster_id' => $cluster->id]);
        $role = NodeRole::query()->create(['node_id' => $operation->node_id, 'cluster_id' => $cluster->id, 'role' => RoleName::Router, 'status' => 'provisioning']);
        $operation->update(['input' => ['cluster_router' => ['candidate' => ['assignment_id' => $role->id], 'old_nodes' => []]]]);
        $data = clusterRouterTerminal($status);
        $data['failure'] = null;
        $data['result']['steps'] = ['Select the Router in DNS' => ['status' => $dnsStatus]];

        app(NativeClusterRouterProjection::class)->apply($operation, $data);

        expect($role->refresh()->failed_step)->toBe('dns-publication')
            ->and(ClusterRouterTransition::candidateServes($role))->toBeTrue();
    })->with([
        ['interrupted', 'running'], ['interrupted', 'succeeded'],
        ['failed', 'failed'], ['cancelled', 'interrupted'],
    ]);

    it('publishes a fresh render once a failed Router change is projected, so a restore that a newer publication superseded is corrected', function (): void {
        Queue::fake();
        $operation = clusterRouterSetOperation();

        $projected = app(NativeClusterRouterProjection::class)->apply($operation, clusterRouterTerminal('failed'));

        expect($projected->status)->toBe(OperationStatus::Failed);
        Queue::assertPushed(PublishPrivateDns::class, 1);
    });

    it('publishes nothing more after a succeeded change', function (): void {
        Queue::fake();
        $operation = clusterRouterSetOperation();

        $projected = app(NativeClusterRouterProjection::class)->apply($operation, [...clusterRouterTerminal('succeeded'), 'failure' => null]);

        expect($projected->status)->toBe(OperationStatus::Succeeded);
        Queue::assertNotPushed(PublishPrivateDns::class);
    });

    it('publishes once for a terminal result that arrives twice', function (): void {
        Queue::fake();
        $operation = clusterRouterSetOperation();
        $projection = app(NativeClusterRouterProjection::class);

        $projection->apply($operation, clusterRouterTerminal('failed'));
        $projection->apply($operation, clusterRouterTerminal('failed'));

        Queue::assertPushed(PublishPrivateDns::class, 1);
    });

    it('publishes after a failed Router unset as well', function (): void {
        Queue::fake();
        $operation = clusterRouterSetOperation('cluster.router_unset');

        app(NativeClusterRouterProjection::class)->apply($operation, clusterRouterTerminal('failed'));

        Queue::assertPushed(PublishPrivateDns::class, 1);
    });
});

/** An active vpn Node that a publication entry can name. */
function dnsJobVpnNode(string $name, string $address): Node
{
    $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => $address, 'wireguard_ip' => $address, 'user' => 'orbit', 'wireguard_public_key' => $name.'-key',
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => $name.'-host-key', 'ssh_host_fingerprint' => 'SHA256:'.$name]));
    $node->roles()->create(['role' => RoleName::Vpn, 'status' => 'active']);

    return $node;
}

describe('PublishPrivateDns job', function (): void {
    beforeEach(function (): void {
        Queue::fake();
        SwampNodeFixture::ensure();
        app()->instance(SwampDispatch::class, new class
        {
            public function start(Operation $operation): void {}
        });
    });

    it('only admits a publication for each vpn Node and does not wait for its end', function (): void {
        $first = dnsJobVpnNode('vpn-a', '10.44.0.1');
        $second = dnsJobVpnNode('vpn-b', '10.44.0.8');

        new PublishPrivateDns()->handle(app(AdmitPrivateDnsPublicationAction::class));

        $operations = Operation::query()->where('kind', 'dns.publish')->orderBy('node_id')->get();

        expect($operations->pluck('node_id')->all())->toBe([$first->id, $second->id])
            ->and($operations->pluck('status')->every(fn (OperationStatus $status): bool => ! $status->terminal()))->toBeTrue();
        Queue::assertNotPushed(PublishPrivateDns::class);
    });

    it('comes back for a Node that is busy, and only for that Node', function (): void {
        $busy = dnsJobVpnNode('vpn-a', '10.44.0.1');
        $free = dnsJobVpnNode('vpn-b', '10.44.0.8');
        app(AdmitPrivateDnsPublicationAction::class)->execute($busy);

        new PublishPrivateDns()->handle(app(AdmitPrivateDnsPublicationAction::class));

        expect(Operation::query()->where('kind', 'dns.publish')->where('node_id', $free->id)->count())->toBe(1);
        Queue::assertPushed(PublishPrivateDns::class, fn (PublishPrivateDns $job): bool => $job->nodeIds === [$busy->id] && $job->attempt === 2);
    });

    it('stops after its attempts', function (): void {
        $busy = dnsJobVpnNode('vpn-a', '10.44.0.1');
        app(AdmitPrivateDnsPublicationAction::class)->execute($busy);

        new PublishPrivateDns([$busy->id], PublishPrivateDns::MaximumAttempts)->handle(app(AdmitPrivateDnsPublicationAction::class));

        Queue::assertNotPushed(PublishPrivateDns::class);
    });
});
