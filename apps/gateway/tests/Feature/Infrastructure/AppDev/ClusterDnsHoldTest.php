<?php

declare(strict_types=1);

use App\Domain\AppDev\ClusterTldTargets;
use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Clusters\ClusterState;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\NativeClusterRouterDnsSelectionReconciler;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\NodeRole;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;

uses(LazilyRefreshDatabase::class);

function holdCluster(string $tld = 'test', ClusterState $state = ClusterState::Active): Cluster
{
    $cluster = Cluster::query()->create(['name' => 'dev-'.bin2hex(random_bytes(3)), 'tld' => $tld]);
    $cluster->forceFill(['state' => $state])->save();

    return $cluster;
}

function holdRouter(Cluster $cluster, string $name, string $address): Node
{
    $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => $address, 'wireguard_ip' => $address, 'user' => 'orbit', 'cluster_id' => $cluster->id, 'wireguard_public_key' => $name.'-key']));
    NodeRole::query()->create(['node_id' => $node->id, 'role' => RoleName::Router, 'status' => LifecycleStatus::Active, 'cluster_id' => $cluster->id]);

    return $node;
}

/** @return object{seen: ArrayObject<int, array<string, mixed>>, manager: PrivateDnsManager} */
function recordingDnsManager(Cluster $cluster): object
{
    $seen = new ArrayObject;
    $manager = new class($cluster, $seen) implements PrivateDnsManager
    {
        /** @param ArrayObject<int, array<string, mixed>> $seen */
        public function __construct(private readonly Cluster $cluster, private readonly ArrayObject $seen) {}

        public function converge(): void
        {
            $this->seen[] = $this->cluster->refresh()->pendingDns();
        }
    };

    return (object) ['seen' => $seen, 'manager' => $manager];
}

describe('Cluster DNS hold', function (): void {
    it('stores the pending TLD, state and Router, and releases them', function (): void {
        $cluster = holdCluster();

        $cluster->holdDns(['tld' => 'dev', 'state' => ClusterState::Inactive, 'router_node_id' => null]);

        expect($cluster->refresh()->pendingDns())->toBe(['tld' => 'dev', 'state' => ClusterState::Inactive->value, 'router_node_id' => null]);

        $cluster->releaseDns();

        expect($cluster->refresh()->pendingDns())->toBe([])->and($cluster->dns_pending_at)->toBeNull();
    });

    it('ignores a hold that nothing cleared for more than an hour', function (): void {
        $cluster = holdCluster();
        $cluster->holdDns(['tld' => 'dev']);

        Carbon::setTestNow(now()->addMinutes(59));
        expect($cluster->refresh()->pendingDns())->toBe(['tld' => 'dev']);

        Carbon::setTestNow(now()->addMinutes(2));
        expect($cluster->refresh()->pendingDns())->toBe([]);
    });
});

describe('ClusterTldTargets', function (): void {
    it('maps the TLD of each active Cluster to the WireGuard address of its Router', function (): void {
        $cluster = holdCluster('test');
        holdRouter($cluster, 'beast', '10.44.0.7');
        holdRouter(holdCluster('prod-dev', ClusterState::Inactive), 'idle', '10.44.0.9');

        expect(new ClusterTldTargets()->all())->toBe(['test' => '10.44.0.7']);
    });

    it('answers a pending TLD or state before the change commits', function (): void {
        $cluster = holdCluster('test');
        holdRouter($cluster, 'beast', '10.44.0.7');

        $cluster->holdDns(['tld' => 'Dev.']);
        expect(new ClusterTldTargets()->all())->toBe(['dev' => '10.44.0.7']);

        $cluster->holdDns(['state' => ClusterState::Inactive]);
        expect(new ClusterTldTargets()->all())->toBe([]);

        $cluster->holdDns(['tld' => null]);
        expect(new ClusterTldTargets()->all())->toBe([]);
    });

    it('answers a pending Router, and no Router when the pending Router is none', function (): void {
        $cluster = holdCluster('test');
        holdRouter($cluster, 'beast', '10.44.0.7');
        $candidate = holdRouter(holdCluster('other'), 'sabre', '10.44.0.8');

        $cluster->holdDns(['router_node_id' => $candidate->id]);
        expect(new ClusterTldTargets()->all()['test'])->toBe('10.44.0.8');

        $cluster->holdDns(['router_node_id' => null]);
        expect(new ClusterTldTargets()->all())->not->toHaveKey('test');
    });

    it('reads the stored rows again once a hold has expired', function (): void {
        $cluster = holdCluster('test');
        holdRouter($cluster, 'beast', '10.44.0.7');
        $cluster->holdDns(['tld' => 'dev']);

        Carbon::setTestNow(now()->addHours(2));

        expect(new ClusterTldTargets()->all())->toBe(['test' => '10.44.0.7']);
    });
});

describe('NativeClusterRouterDnsSelectionReconciler', function (): void {
    it('publishes with the change held, then publishes again with the hold released', function (): void {
        $cluster = holdCluster();
        $recording = recordingDnsManager($cluster);
        $reconciler = new NativeClusterRouterDnsSelectionReconciler($recording->manager);

        $reconciler->expand([$cluster->id => ['tld' => 'dev', 'state' => ClusterState::Active]]);
        $reconciler->prune([$cluster->id]);

        expect($recording->seen->getArrayCopy())->toBe([['tld' => 'dev', 'state' => 'active'], []])
            ->and($cluster->refresh()->dns_pending)->toBeNull();
    });
});
