<?php

declare(strict_types=1);

use App\Domain\Clusters\ClusterRouterOperationLock;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\ClusterRouterReplacementStep;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;

beforeEach(function (): void {
    $this->gateway = $this->markAsGateway(cluster_router_api_node('gateway-router-peer', '10.44.0.1'));
    $this->gateway->roles()->create(['role' => RoleName::Vpn, 'status' => LifecycleStatus::Active]);
    $this->cluster = Cluster::query()->create(['name' => 'development']);
    $this->first = cluster_router_api_node('first-router', '10.44.0.2', $this->cluster);
    $this->second = cluster_router_api_node('second-router', '10.44.0.3', $this->cluster);
    $this->first->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
});

it('returns 409 before Router validation or mutation while the Cluster owner is busy', function (): void {
    app()->instance(ClusterRouterOperationLock::class, new class implements ClusterRouterOperationLock
    {
        public function run(int $clusterId, Closure $operation): mixed
        {
            throw new ResourceOperationException('cluster.router_busy', 'Another Router operation is active.', 409);
        }
    });
    $outside = cluster_router_api_node('outside', '10.44.0.4');
    $this->putJson("/api/v1/clusters/{$this->cluster->id}/router/{$outside->id}")->assertConflict()->assertJsonPath('error.code', 'cluster.router_busy');
    $this->deleteJson("/api/v1/clusters/{$this->cluster->id}/router", ['force' => true])->assertConflict()->assertJsonPath('error.code', 'cluster.router_busy');
    expect(Operation::query()->count())->toBe(0);
});

it('reloads the requested Node after waiting and rejects stale membership', function (): void {
    app()->instance(ClusterRouterOperationLock::class, new ClusterRouterApiOperationLock(fn () => $this->second->update(['cluster_id' => null])));
    $this->putJson("/api/v1/clusters/{$this->cluster->id}/router/{$this->second->id}")->assertConflict()->assertJsonPath('error.code', 'cluster.router_node_invalid');
    expect(Operation::query()->count())->toBe(0);
});

it('runs Router removal guards against Route state created while waiting', function (): void {
    cluster_router_activate($this->cluster, $this->first);
    app()->instance(ClusterRouterOperationLock::class, new ClusterRouterApiOperationLock(fn () => cluster_router_owned_route($this->cluster, $this->first)));
    $this->deleteJson("/api/v1/clusters/{$this->cluster->id}/router", ['force' => true])->assertConflict()->assertJsonPath('error.code', 'route.reconciliation_required');
    expect(Operation::query()->count())->toBe(0)->and($this->cluster->routerAssignment()->exists())->toBeTrue();
});

it('detaches a member that only has a leftover never-activated Router assignment', function (): void {
    $this->second->roles()->create(['role' => RoleName::Router, 'cluster_id' => $this->cluster->id, 'status' => LifecycleStatus::Failed]);
    $this->deleteJson("/api/v1/clusters/{$this->cluster->id}/nodes/{$this->second->id}", ['force' => true])->assertOk();
    expect($this->second->fresh()->cluster_id)->toBeNull();
});

it('still forbids detaching an active Cluster Router or a failed live removal', function (string $status): void {
    $role = cluster_router_activate($this->cluster, $this->second);
    if ($status !== 'active') {
        $role->update(['status' => $status, 'failed_step' => 'old-router-cleanup']);
    }
    $this->deleteJson("/api/v1/clusters/{$this->cluster->id}/nodes/{$this->second->id}", ['force' => true])->assertConflict();
    expect($this->second->fresh()->cluster_id)->toBe($this->cluster->id);
})->with(['active', 'removing']);

it('does not publish DNS selection for a refused Router assignment', function (): void {
    $outside = cluster_router_api_node('outside', '10.44.0.4');
    $this->putJson("/api/v1/clusters/{$this->cluster->id}/router/{$outside->id}")->assertConflict();
    expect(Operation::query()->count())->toBe(0);
});

it('requires an active member Node for Router assignment', function (string $status): void {
    $this->second->update(['status' => $status]);
    $this->putJson("/api/v1/clusters/{$this->cluster->id}/router/{$this->second->id}")->assertConflict()->assertJsonPath('error.code', 'cluster.router_node_invalid');
    expect(Operation::query()->count())->toBe(0);
})->with(['failed', 'provisioning', 'removing']);

it('refuses to clear an active TLD-bearing Cluster Router until the Cluster is inactive', function (): void {
    cluster_router_activate($this->cluster, $this->first);
    $this->cluster->update(['tld' => 'private.test', 'state' => 'active']);
    $this->deleteJson("/api/v1/clusters/{$this->cluster->id}/router", ['force' => true])->assertConflict()->assertJsonPath('error.code', 'cluster.active_router_required');
    expect($this->cluster->routerAssignment()->exists())->toBeTrue();
});

it('keeps the replacement when cleanup fails and refuses a conflicting transition', function (): void {
    cluster_router_activate($this->cluster, $this->first);
    $candidate = $this->second->roles()->create(['role' => RoleName::Router, 'cluster_id' => $this->cluster->id, 'status' => LifecycleStatus::Failed, 'failed_step' => ClusterRouterReplacementStep::RouterCaddy->value]);
    $this->putJson("/api/v1/clusters/{$this->cluster->id}/router/{$this->first->id}")->assertConflict()->assertJsonPath('error.code', 'cluster.router_transition_conflict');
    expect($candidate->fresh()->status)->toBe(LifecycleStatus::Failed);
});

function cluster_router_activate(Cluster $cluster, Node $node): NodeRole
{
    return $node->roles()->create(['role' => RoleName::Router, 'cluster_id' => $cluster->id, 'status' => LifecycleStatus::Active]);
}

function cluster_router_owned_route(Cluster $cluster, Node $workload): array
{
    $project = Project::query()->create(['name' => 'Router app', 'slug' => 'router-app', 'repository_url' => 'https://example.test/router.git', 'root' => 'public']);
    $target = Instance::query()->create(['project_id' => $project->id, 'node_id' => $workload->id, 'name' => 'default', 'checkout_path' => '/srv/router/default', 'status' => 'active', 'source_is_laravel' => false]);
    $route = Route::query()->create(['project_id' => $project->id, 'cluster_id' => $cluster->id, 'domain' => 'router.private.test', 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
    $route->targets()->create(['instance_id' => $target->id, 'position' => 0]);
    $route->update(['status' => 'active', 'sites_published' => true]);

    return [$route->fresh(), $target];
}

function cluster_router_api_node(string $name, string $wireguardIp, ?Cluster $cluster = null): Node
{
    return Node::query()->create(['architecture' => 'x86_64', 'cluster_id' => $cluster?->id, 'name' => $name, 'status' => 'active', 'public_ssh_host' => '192.0.2.'.substr($wireguardIp, strrpos($wireguardIp, '.') + 1), 'wireguard_ip' => $wireguardIp, 'platform' => 'linux', 'user' => 'orbit']);
}

final class ClusterRouterApiOperationLock implements ClusterRouterOperationLock
{
    private bool $prepared = false;

    public function __construct(private readonly ?Closure $before = null) {}

    public function run(int $clusterId, Closure $operation): mixed
    {
        if (! $this->prepared) {
            ($this->before ?? static function (): void {})();
            $this->prepared = true;
        }

        return $operation();
    }
}
