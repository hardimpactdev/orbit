<?php

declare(strict_types=1);

use App\Domain\Clusters\ClusterRouterOperationLock;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\ClusterRouterReplacementStep;
use App\Domain\Routes\ClusterRouterTransition;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Support\Facades\DB;
use Tests\Support\RouteSwampGatewayFixture;

beforeEach(function (): void {
    $this->gateway = $this->markAsGateway(cluster_router_api_node('gateway-router-peer', '10.44.0.1'));
    $this->cluster = Cluster::query()->create(['name' => 'development']);
    $this->first = cluster_router_api_node('first-router', '10.44.0.2', $this->cluster);
    $this->second = cluster_router_api_node('second-router', '10.44.0.3', $this->cluster);
    $this->first->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    $this->swamp = new RouteSwampGatewayFixture($this);
});

it('publishes Router DNS only after the new assignment becomes authoritative', function (): void {
    $this->swamp->foreign->observe = function (Operation $child): void {
        if ($child->kind === 'route.private_dns_publish') {
            expect($this->cluster->routerAssignment()->sole()->node_id)->toBe($this->first->id);
        }
    };
    $op = $this->swamp->finish($this->putJson("/api/v1/clusters/{$this->cluster->id}/router/{$this->first->id}"));
    expect($op->status->value)->toBe('succeeded')->and($op->result['cluster_id'])->toBe($this->cluster->id);
});

it('keeps both Routers serving when DNS fails after cutover and recovers forward', function (): void {
    cluster_router_activate($this->cluster, $this->first);
    $this->swamp->foreign->failures['dns-publication.node-'.$this->gateway->id] = 1;
    $op = $this->swamp->finish($this->putJson("/api/v1/clusters/{$this->cluster->id}/router/{$this->second->id}"));
    expect($op->status->value)->toBe('failed')->and($this->cluster->routerAssignment()->sole()->node_id)->toBe($this->second->id)
        ->and(NodeRole::query()->where('role', RoleName::Router)->count())->toBe(2);
    $retry = $this->swamp->finish($this->putJson("/api/v1/clusters/{$this->cluster->id}/router/{$this->second->id}"));
    expect($retry->status->value)->toBe('succeeded')->and(NodeRole::query()->where('role', RoleName::Router)->count())->toBe(1);
});

it('sets a Router beside an existing database role', function (): void {
    $this->second->roles()->create(['role' => RoleName::Database, 'status' => LifecycleStatus::Active]);
    $op = $this->swamp->finish($this->putJson("/api/v1/clusters/{$this->cluster->id}/router/{$this->second->id}"));
    expect($op->status->value)->toBe('succeeded')->and($this->second->fresh()->roles->pluck('role')->map->value->sort()->values()->all())->toBe(['database', 'router']);
});

it('sets a Router beside an application role and rejects generic Router mutation', function (): void {
    $op = $this->swamp->finish($this->putJson("/api/v1/clusters/{$this->cluster->id}/router/{$this->first->id}"));
    expect($op->status->value)->toBe('succeeded')->and($this->first->fresh()->roles->pluck('role')->map->value->sort()->values()->all())->toBe(['app-dev', 'router']);
    $this->postJson("/api/v1/nodes/{$this->first->id}/roles", ['role' => 'router'])->assertUnprocessable();
});

it('atomically replaces the Router and preserves exactly one assignment', function (): void {
    cluster_router_activate($this->cluster, $this->first);
    $op = $this->swamp->finish($this->putJson("/api/v1/clusters/{$this->cluster->id}/router/{$this->second->id}"));
    expect($op->status->value)->toBe('succeeded')->and($this->cluster->routerAssignment()->sole()->node_id)->toBe($this->second->id)
        ->and(NodeRole::query()->where('role', RoleName::Router)->count())->toBe(1);
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

it('reloads Router assignments after waiting before a replacement', function (): void {
    app()->instance(ClusterRouterOperationLock::class, new ClusterRouterApiOperationLock(fn () => cluster_router_activate($this->cluster, $this->first)));
    $admitted = $this->putJson("/api/v1/clusters/{$this->cluster->id}/router/{$this->second->id}")->assertAccepted();
    expect(Operation::query()->findOrFail($admitted->json('data.id'))->state['route_plan']['old_role_ids'])->toBe([$this->cluster->routerAssignment()->sole()->id]);
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

it('holds the parent target until baseline proof and refuses a competing Router set', function (): void {
    $first = $this->putJson("/api/v1/clusters/{$this->cluster->id}/router/{$this->first->id}")->assertAccepted();
    $this->putJson("/api/v1/clusters/{$this->cluster->id}/router/{$this->second->id}")->assertConflict();
    expect(NodeRole::query()->where('node_id', $this->first->id)->where('role', RoleName::Router)->sole()->status)->toBe(LifecycleStatus::Provisioning);
    $op = $this->swamp->finish($first);
    expect($op->status->value)->toBe('succeeded');
});

it('removes every obsolete Router after several replacement convergence failures', function (): void {
    cluster_router_activate($this->cluster, $this->first);
    $third = cluster_router_api_node('third', '10.44.0.4', $this->cluster);
    $third->roles()->create(['role' => RoleName::Router, 'cluster_id' => $this->cluster->id, 'status' => LifecycleStatus::Failed]);
    $op = $this->swamp->finish($this->putJson("/api/v1/clusters/{$this->cluster->id}/router/{$this->second->id}"));
    expect($op->status->value)->toBe('succeeded')->and(NodeRole::query()->where('role', RoleName::Router)->pluck('node_id')->all())->toBe([$this->second->id]);
});

it('retains a failed initial Router convergence for an identical retry', function (): void {
    $this->swamp->foreign->failures['baseline.router'] = 1;
    $failed = $this->swamp->finish($this->putJson("/api/v1/clusters/{$this->cluster->id}/router/{$this->first->id}"));
    expect($failed->status->value)->toBe('failed')->and($this->cluster->routerAssignment()->exists())->toBeFalse();
    $retry = $this->swamp->finish($this->putJson("/api/v1/clusters/{$this->cluster->id}/router/{$this->first->id}"));
    expect($retry->status->value)->toBe('succeeded')->and($this->cluster->routerAssignment()->sole()->node_id)->toBe($this->first->id);
});

it('keeps the active Router when replacement baseline proof fails', function (): void {
    cluster_router_activate($this->cluster, $this->first);
    $this->swamp->foreign->failures['baseline.router'] = 1;
    $op = $this->swamp->finish($this->putJson("/api/v1/clusters/{$this->cluster->id}/router/{$this->second->id}"));
    expect($op->status->value)->toBe('failed')->and($this->cluster->routerAssignment()->sole()->node_id)->toBe($this->first->id);
});

it('retains failed old Router cleanup with the replacement active and resumes it', function (): void {
    $old = cluster_router_activate($this->cluster, $this->first);
    $this->swamp->foreign->failures['remove-old-baseline.router-'.$old->id] = 1;
    $failed = $this->swamp->finish($this->putJson("/api/v1/clusters/{$this->cluster->id}/router/{$this->second->id}"));
    expect($failed->status->value)->toBe('failed')->and($this->cluster->routerAssignment()->sole()->node_id)->toBe($this->second->id)->and($old->fresh()->status)->toBe(LifecycleStatus::Removing);
    $retry = $this->swamp->finish($this->putJson("/api/v1/clusters/{$this->cluster->id}/router/{$this->second->id}"));
    expect($retry->status->value)->toBe('succeeded')->and($old->fresh())->toBeNull();
});

it('preserves proved child identity across parent re-entry without repeating baseline work', function (): void {
    $op = $this->swamp->finish($this->putJson("/api/v1/clusters/{$this->cluster->id}/router/{$this->first->id}"));
    $baseline = $op->children()->where('kind', 'node.role_converge')->sole();
    expect($baseline->child_key)->toBe('baseline.router')->and($baseline->attempts)->toBe(1)->and($baseline->status->value)->toBe('succeeded')
        ->and(count(array_filter($this->swamp->foreign->calls, fn ($call) => $call['kind'] === 'node.role_converge')))->toBe(1);
});

it('retains failed Router clear cleanup and removes every retained assignment on retry', function (): void {
    $old = cluster_router_activate($this->cluster, $this->first);
    $this->swamp->foreign->failures['remove-old-baseline.router-'.$old->id] = 1;
    $failed = $this->swamp->finish($this->deleteJson("/api/v1/clusters/{$this->cluster->id}/router", ['force' => true]));
    expect($failed->status->value)->toBe('failed')->and($old->fresh()->status)->toBe(LifecycleStatus::Removing);
    $retry = $this->swamp->finish($this->deleteJson("/api/v1/clusters/{$this->cluster->id}/router", ['force' => true]));
    expect($retry->status->value)->toBe('succeeded')->and($old->fresh())->toBeNull();
});

it('lets cluster show succeed after a failed initial Router set', function (): void {
    $this->swamp->foreign->failures['baseline.router'] = 1;
    $failed = $this->swamp->finish($this->putJson("/api/v1/clusters/{$this->cluster->id}/router/{$this->first->id}"));
    expect($failed->status->value)->toBe('failed');
    $this->getJson("/api/v1/clusters/{$this->cluster->id}")->assertOk()->assertJsonPath('data.router', null);
});

it('clears a leftover never-activated Router through a narrow removal child', function (): void {
    $role = $this->first->roles()->create(['role' => RoleName::Router, 'cluster_id' => $this->cluster->id, 'status' => LifecycleStatus::Failed]);
    $op = $this->swamp->finish($this->deleteJson("/api/v1/clusters/{$this->cluster->id}/router", ['force' => true]));
    expect($op->status->value)->toBe('succeeded')->and($role->fresh())->toBeNull()
        ->and(array_column($this->swamp->foreign->calls, 'kind'))->toContain('node.role_remove');
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
    expect($this->swamp->foreign->calls)->toBe([])->and(Operation::query()->count())->toBe(0);
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

it('clears an optional Router while a TLD-less Cluster remains active', function (): void {
    $role = cluster_router_activate($this->cluster, $this->first);
    $this->cluster->update(['state' => 'active']);
    $op = $this->swamp->finish($this->deleteJson("/api/v1/clusters/{$this->cluster->id}/router", ['force' => true]));
    expect($op->status->value)->toBe('succeeded')->and($role->fresh())->toBeNull()->and($this->cluster->fresh()->state->value)->toBe('active');
});

it('prepares Cluster Route children before a replacement becomes authoritative', function (): void {
    cluster_router_activate($this->cluster, $this->first);
    [$route] = cluster_router_owned_route($this->cluster, $this->first);
    $this->swamp->foreign->observe = function (Operation $child): void {
        if (in_array($child->kind, ['certificate.converge', 'route.workload_verify', 'route.caddy_converge'], true) && str_starts_with($child->child_key, 'router-')) {
            expect($this->cluster->routerAssignment()->sole()->node_id)->toBe($this->first->id);
        }
    };
    $op = $this->swamp->finish($this->putJson("/api/v1/clusters/{$this->cluster->id}/router/{$this->second->id}"));
    expect($op->status->value)->toBe('succeeded')->and($route->fresh()->status->value)->toBe('active');
});

it('composes a colocated replacement without a self-proxy child', function (): void {
    cluster_router_activate($this->cluster, $this->second);
    [$route] = cluster_router_owned_route($this->cluster, $this->first);
    $admitted = $this->putJson("/api/v1/clusters/{$this->cluster->id}/router/{$this->first->id}")->assertAccepted();
    $plan = Operation::query()->findOrFail($admitted->json('data.id'))->state['route_plan'];
    expect($plan['stages']['workload-verify'] ?? [])->toBe([])->and($plan['stages']['router-certificate'] ?? [])->toBe([])
        ->and($plan['route_ids'])->toBe([$route->id]);
});

it('uses the recorded workload Node for LAN policy when Router and application roles coexist', function (): void {
    cluster_router_activate($this->cluster, $this->first);
    [$route, $instance] = cluster_router_owned_route($this->cluster, $this->first);
    $admitted = $this->putJson("/api/v1/clusters/{$this->cluster->id}/router/{$this->second->id}")->assertAccepted();
    $plan = Operation::query()->findOrFail($admitted->json('data.id'))->state['route_plan'];
    expect($plan['stages']['firewall-policy'][0]['node_id'])->toBe($instance->node_id)
        ->and($plan['stages']['firewall-policy'][0]['input'])->toBe(['route_id' => $route->id, 'instance_id' => $instance->id, 'router_node_id' => $this->second->id]);
});

it('restores the old Router when candidate Caddy publication fails', function (): void {
    cluster_router_activate($this->cluster, $this->first);
    cluster_router_owned_route($this->cluster, $this->first);
    $this->swamp->foreign->failures['router-caddy.node-'.$this->second->id] = 1;
    $failed = $this->swamp->finish($this->putJson("/api/v1/clusters/{$this->cluster->id}/router/{$this->second->id}"));
    expect($failed->status->value)->toBe('failed')->and($this->cluster->routerAssignment()->sole()->node_id)->toBe($this->first->id)
        ->and(array_column($this->swamp->foreign->calls, 'kind'))->toContain('route.private_dns_publish', 'certificate.remove');
});

it('keeps the replacement when cleanup fails and refuses a conflicting transition', function (): void {
    cluster_router_activate($this->cluster, $this->first);
    $candidate = $this->second->roles()->create(['role' => RoleName::Router, 'cluster_id' => $this->cluster->id, 'status' => LifecycleStatus::Failed, 'failed_step' => ClusterRouterReplacementStep::RouterCaddy->value]);
    $this->putJson("/api/v1/clusters/{$this->cluster->id}/router/{$this->first->id}")->assertConflict()->assertJsonPath('error.code', 'cluster.router_transition_conflict');
    expect($candidate->fresh()->status)->toBe(LifecycleStatus::Failed);
});

it('replaces a Router without probing application HTTP health or changing Route lifecycle', function (): void {
    cluster_router_activate($this->cluster, $this->first);
    [$route] = cluster_router_owned_route($this->cluster, $this->first);
    $op = $this->swamp->finish($this->putJson("/api/v1/clusters/{$this->cluster->id}/router/{$this->second->id}"));
    expect($op->status->value)->toBe('succeeded')->and($route->fresh()->status->value)->toBe('active')
        ->and(array_column($this->swamp->foreign->calls, 'kind'))->not->toContain('route.public_verify');
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
    return Node::query()->create(['cluster_id' => $cluster?->id, 'name' => $name, 'status' => 'active', 'public_ssh_host' => '192.0.2.'.substr($wireguardIp, strrpos($wireguardIp, '.') + 1), 'wireguard_ip' => $wireguardIp, 'platform' => 'linux', 'user' => 'orbit']);
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

it('keeps the candidate Router serving when its restore cannot move private DNS back', function (): void {
    cluster_router_activate($this->cluster, $this->first);
    [$route] = cluster_router_owned_route($this->cluster, $this->first);
    $this->swamp->foreign->failures['router-caddy.node-'.$this->second->id] = 1;
    $this->swamp->foreign->failures['restore-dns.node-'.$this->gateway->id] = 1;
    $failed = $this->swamp->finish($this->putJson("/api/v1/clusters/{$this->cluster->id}/router/{$this->second->id}"));
    $candidate = $this->second->roles()->where('role', RoleName::Router)->sole();
    expect($failed->status->value)->toBe('failed')->and($candidate->status)->toBe(LifecycleStatus::Provisioning)
        ->and(ClusterRouterTransition::candidateServes($candidate))->toBeTrue()
        ->and($this->cluster->routerAssignment()->sole()->node_id)->toBe($this->first->id)
        ->and($failed->state['route_completed_stages']['withdraw-candidate-sites'] ?? false)->toBeFalse();
    $retry = $this->swamp->finish($this->putJson("/api/v1/clusters/{$this->cluster->id}/router/{$this->second->id}"));
    expect($retry->status->value)->toBe('succeeded')->and($route->refresh()->status->value)->toBe('active');
});

it('keeps Router database cutover failure resumable without publishing uncommitted DNS', function (): void {
    cluster_router_activate($this->cluster, $this->first);
    cluster_router_owned_route($this->cluster, $this->first);
    DB::statement("CREATE TRIGGER router_cutover_refused BEFORE UPDATE OF status ON node_roles WHEN NEW.node_id = {$this->second->id} AND NEW.status = 'active' BEGIN SELECT RAISE(ABORT, 'Injected cutover refusal'); END");
    $admitted = $this->putJson("/api/v1/clusters/{$this->cluster->id}/router/{$this->second->id}")->assertAccepted();
    expect(fn () => $this->swamp->finish($admitted))->toThrow(RuntimeException::class, 'operation.execution_failed');
    $op = Operation::query()->findOrFail($admitted->json('data.id'));
    expect($op->status->terminal())->toBeFalse()->and($this->cluster->routerAssignment()->sole()->node_id)->toBe($this->first->id)
        ->and($op->children()->where('kind', 'route.private_dns_publish')->exists())->toBeFalse();
    DB::statement('DROP TRIGGER router_cutover_refused');
    $this->travel(121)->seconds();
    $this->swamp->run($op);
    expect($op->refresh()->status->value)->toBe('succeeded')->and($this->cluster->routerAssignment()->sole()->node_id)->toBe($this->second->id);
});

it('stores which Routers serve the Router sites before each build and cleanup', function (): void {
    cluster_router_activate($this->cluster, $this->first);
    $workload = cluster_router_api_node('independent-workload', '10.44.0.4', $this->cluster);
    $workload->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    [$route] = cluster_router_owned_route($this->cluster, $workload);
    $observed = [];
    $this->swamp->foreign->observe = function (Operation $child) use ($route, &$observed): void {
        if ($child->kind !== 'route.caddy_converge') {
            return;
        }
        $sites = new DevelopmentSiteRepository;
        $observed[$child->child_key] = [
            $sites->forNode($this->first->refresh())->pluck('domain')->contains($route->domain),
            $sites->forNode($this->second->refresh())->pluck('domain')->contains($route->domain),
        ];
    };
    $op = $this->swamp->finish($this->putJson("/api/v1/clusters/{$this->cluster->id}/router/{$this->second->id}"));
    expect($op->status->value)->toBe('succeeded')->and($observed['router-caddy.node-'.$this->second->id])->toBe([true, true])
        ->and($observed['withdraw-old-sites.node-'.$this->first->id])->toBe([false, true]);
});

it('publishes private DNS before a Router replacement withdraws either Router', function (): void {
    cluster_router_activate($this->cluster, $this->first);
    cluster_router_owned_route($this->cluster, $this->first);
    $events = [];
    $this->swamp->foreign->observe = function (Operation $child) use (&$events): void {
        $events[] = $child->child_key;
    };
    $op = $this->swamp->finish($this->putJson("/api/v1/clusters/{$this->cluster->id}/router/{$this->second->id}"));
    expect($op->status->value)->toBe('succeeded')
        ->and(array_search('dns-publication.node-'.$this->gateway->id, $events, true))->toBeLessThan(array_search('withdraw-old-sites.node-'.$this->first->id, $events, true))
        ->and($op->state['route_completed_stages']['await-old-dns-expiry'])->toBeTrue();
});

it('does not return reconciliation_required after Router replacement and still refuses Router removal', function (): void {
    cluster_router_activate($this->cluster, $this->first);
    [$route] = cluster_router_owned_route($this->cluster, $this->first);
    $before = $route->fresh(['targets'])->toArray();
    $op = $this->swamp->finish($this->putJson("/api/v1/clusters/{$this->cluster->id}/router/{$this->second->id}"));
    expect($op->status->value)->toBe('succeeded')->and($this->cluster->routerAssignment()->sole()->node_id)->toBe($this->second->id)
        ->and($route->fresh(['targets'])->toArray())->toBe($before);
    $this->deleteJson("/api/v1/clusters/{$this->cluster->id}/router")->assertConflict()->assertJsonPath('error.code', 'route.reconciliation_required');
    expect($route->fresh(['targets'])->toArray())->toBe($before);
});
