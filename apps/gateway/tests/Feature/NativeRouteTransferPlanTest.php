<?php

declare(strict_types=1);

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\NativeRouteTransferPlan;
use App\Domain\Routes\NativeRouteTransferPublication;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RoutePublicationSites;
use App\Domain\Routes\RouteReplacementStep;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\InstanceTransfer;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;

beforeEach(function (): void {
    $this->node = Node::query()->create(['name' => 'native-dev', 'public_ssh_host' => '192.0.2.11', 'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => LifecycleStatus::Active]);
    $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $project = Project::query()->create(['name' => 'Native', 'slug' => 'native', 'repository_url' => 'https://example.test/native.git', 'root' => 'public']);
    $this->instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->node->id, 'name' => 'native', 'checkout_path' => '/srv/native', 'branch' => 'main', 'source_is_laravel' => false, 'status' => InstanceState::SourceResolved]);
    $this->route = Route::query()->create(['project_id' => $project->id, 'node_id' => $this->node->id, 'domain' => 'native.test', 'provenance' => 'explicit', 'publication' => RoutePublication::Private, 'status' => RouteStatus::Pending]);
    $this->route->targets()->create(['instance_id' => $this->instance->id, 'position' => 0]);
    $this->manifest = tempnam(sys_get_temp_dir(), 'native-route-agent-');
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
    $this->cluster = Cluster::query()->create(['name' => 'source', 'state' => 'active', 'tld' => 'source.test']);
    $this->destinationCluster = Cluster::query()->create(['name' => 'destination', 'state' => 'active', 'tld' => 'destination.test']);
    $this->node->update(['cluster_id' => $this->cluster->id]);
    $this->node->roles()->create(['cluster_id' => $this->cluster->id, 'role' => 'router', 'status' => 'active']);
    $this->destination = Node::query()->create(['name' => 'destination', 'cluster_id' => $this->destinationCluster->id, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.2']);
    $this->destination->roles()->create(['cluster_id' => $this->destinationCluster->id, 'role' => 'router', 'status' => 'active']);
    $this->route->update(['node_id' => null, 'cluster_id' => $this->cluster->id, 'sites_published' => true, 'status' => 'active']);
    $this->extra = Route::query()->create(['project_id' => $this->instance->project_id, 'cluster_id' => $this->cluster->id, 'domain' => 'extra.test', 'web_root' => 'admin/public', 'status' => 'pending', 'sites_published' => true, 'publication' => 'private', 'provenance' => 'explicit']);
    $this->extra->targets()->create(['instance_id' => $this->instance->id, 'position' => 0]);
    $this->extra->update(['status' => 'active']);
    $this->transfer = InstanceTransfer::query()->create(['instance_id' => $this->instance->id, 'source_node_id' => $this->node->id, 'source_router_node_id' => $this->node->id, 'destination_node_id' => $this->destination->id, 'source_path' => '/srv/native', 'source_layout' => 'checkout', 'source_route_id' => $this->route->id, 'destination_name' => 'native', 'destination_path' => '/srv/new-native', 'destination_domain' => 'native.destination.test', 'status' => 'reserved', 'current_step' => 'reserved']);

});

afterEach(function (): void {
    unlink($this->manifest);
});

it('captures all Route identities and source artifacts without child Operations', function (): void {
    $snapshot = app(NativeRouteTransferPlan::class)->capture($this->transfer);
    expect(array_column($snapshot['routes'], 'route_id'))->toBe([$this->route->id, $this->extra->id])
        ->and($snapshot['participant_node_ids'])->toBe([$this->node->id, $this->destination->id, 209])
        ->and(array_column($snapshot['target']['prepared'], 'node_id'))->toBe([$this->destination->id, $this->destination->id])
        ->and($snapshot['retire_nodes'][0]['certificate_scopes'])->toContain('route-'.$this->extra->id)
        ->and(Operation::query()->count())->toBe(0)->and($this->instance->fresh()->node_id)->toBe($this->node->id);
});
it('moves all Route placements and retires only the replaced primary Route', function (): void {
    $plan = app(NativeRouteTransferPlan::class);
    $snapshot = $plan->capture($this->transfer);
    $this->instance->update(['node_id' => $this->destination->id, 'checkout_path' => $this->transfer->destination_path]);
    $plan->cutover($snapshot);
    expect($this->extra->fresh()->cluster_id)->toBe($this->destinationCluster->id)->and($this->route->fresh()->status)->toBe(RouteStatus::Retiring);
    $plan->retire($snapshot);
    expect(Route::query()->find($this->route->id))->toBeNull()->and($this->extra->fresh()->domain)->toBe('extra.test')
        ->and(Route::query()->findOrFail($snapshot['destination_route_id'])->replaces_route_id)->toBeNull();
});
it('rolls back reserved destination intent and keeps every source Route', function (): void {
    $plan = app(NativeRouteTransferPlan::class);
    $snapshot = $plan->capture($this->transfer);
    $plan->rollback($snapshot);
    expect($this->route->fresh()->replaced_by_route_id)->toBeNull()->and($this->route->fresh()->status)->toBe(RouteStatus::Active)
        ->and($this->extra->fresh()->cluster_id)->toBe($this->cluster->id)->and(Route::query()->find($snapshot['destination_route_id']))->toBeNull();
});
it('refuses pre-cutover rollback after Instance placement changed', function (): void {
    $plan = app(NativeRouteTransferPlan::class);
    $snapshot = $plan->capture($this->transfer);
    $this->instance->update(['node_id' => $this->destination->id]);
    expect(fn () => $plan->rollback($snapshot))->toThrow(ResourceOperationException::class);
    expect(Route::query()->find($snapshot['destination_route_id']))->not->toBeNull();
});
it('refuses a cross-Cluster public web root before reserving any destination Route', function (): void {
    $this->extra->update(['publication' => 'public']);
    expect(fn () => app(NativeRouteTransferPlan::class)->capture($this->transfer))->toThrow(ResourceOperationException::class);
    expect($this->transfer->fresh()->destination_route_id)->toBeNull()->and(Route::query()->count())->toBe(2);
});

it('captures account intent from stored Node users without resolving any host account', function (): void {
    $this->mock(ManagedUserAccountResolver::class)->shouldNotReceive('resolve');
    $snapshot = app(NativeRouteTransferPlan::class)->capture($this->transfer);
    expect($snapshot['target']['certificates'][0])->not->toHaveKey('home')
        ->and($snapshot['target']['certificates'][0]['user'])->toBe('orbit')
        ->and($snapshot['retire_nodes'][0])->not->toHaveKey('home');
});

it('keeps source workload ownership in the fresh pre-cutover restoration view', function (): void {
    $plan = app(NativeRouteTransferPlan::class);
    $snapshot = $plan->capture($this->transfer);
    $operation = new Operation(['kind' => 'instance.transfer', 'node_id' => $this->node->id,
        'input' => ['route_transfer' => $snapshot, 'participant_node_ids' => $snapshot['participant_node_ids']]]);
    $view = $plan->publicInput($operation, $this->node->id);
    expect(array_column($view['workloads'], 'instance_id'))->toBe([$this->instance->id, $this->instance->id])
        ->and(array_column($view['workloads'], 'node_id'))->toBe([$this->node->id, $this->node->id]);
    expect(fn () => $plan->environmentMaterial($operation, $this->destination->id))->toThrow(ResourceOperationException::class);
});

it('exposes only this participant candidate leaf scopes for pre-cutover cleanup', function (): void {
    $plan = app(NativeRouteTransferPlan::class);
    $snapshot = $plan->capture($this->transfer);
    $operation = new Operation(['kind' => 'instance.transfer_rollback', 'node_id' => $this->node->id,
        'input' => ['route_transfer' => $snapshot, 'participant_node_ids' => $snapshot['participant_node_ids']]]);
    $view = $plan->publicInput($operation, $this->destination->id);
    expect($view['route_rollback']['node_id'])->toBe($this->destination->id)
        ->and($view['route_rollback']['user'])->toBe('orbit')
        ->and($view['route_rollback']['certificate_scopes'])->toContain('app-instance-'.$this->instance->id)
        ->and($view['route_rollback'])->not->toHaveKey('home')
        ->and($view['route_rollback']['lan_route_ids'])->toBe([]);
    expect($plan->publicInput($operation, $this->node->id)['route_rollback'])->toBeNull();
});

it('captures prospective complete publications without changing stored placement', function (): void {
    $this->node->update(['wireguard_ip' => '10.44.0.11']);
    $this->instance->update(['selected_php_version' => '8.4']);
    $this->destination->update(['wireguard_ip' => '10.44.0.12']);
    $this->destination->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    $survivorInstance = Instance::query()->create(['project_id' => $this->instance->project_id, 'node_id' => $this->node->id,
        'name' => 'survivor', 'checkout_path' => '/srv/survivor', 'branch' => 'main', 'source_is_laravel' => false, 'status' => 'active']);
    $survivor = Route::query()->create(['project_id' => $this->instance->project_id, 'cluster_id' => $this->cluster->id,
        'domain' => 'survivor.source.test', 'publication' => 'private', 'provenance' => 'explicit', 'status' => 'pending']);
    $survivor->targets()->create(['instance_id' => $survivorInstance->id, 'position' => 0]);
    $survivor->update(['status' => 'active', 'sites_published' => true]);
    $plan = app(NativeRouteTransferPlan::class)->capture($this->transfer);
    $instanceBefore = $this->instance->fresh()->getAttributes();
    $routesBefore = Route::query()->orderBy('id')->get()->map->getAttributes()->all();
    $publications = app(NativeRouteTransferPublication::class)->capture($plan, ['instance_id' => $this->instance->id, 'node_id' => $this->destination->id,
        'target' => ['instance' => ['checkout_path' => $this->transfer->destination_path, 'root' => null, 'source_is_laravel' => false, 'vite_port' => null, 'ssr_port' => null]]]);
    $rows = collect($publications['nodes'])->keyBy('node_id');
    $source = $rows[$this->node->id];
    $destination = $rows[$this->destination->id];
    expect($source['connection'])->toBe(['nodeHost' => '10.44.0.11', 'nodeUser' => 'orbit'])
        ->and($destination['connection'])->toBe(['nodeHost' => '10.44.0.12', 'nodeUser' => 'orbit'])
        ->and($source['before']['caddy']['content'])->toContain('native.test')->toContain('survivor.source.test')
        ->and($source['after']['caddy']['content'])->toContain('survivor.source.test')->not->toContain('https://native.test')
        ->and($destination['after']['caddy']['content'])->toContain('native.destination.test')->toContain('/srv/new-native')->toContain('extra.test')
        ->and($destination['before']['caddy']['content'])->not->toContain('native.destination.test')
        ->and((array) $destination['after']['php']['configurations'])->toHaveKey('8.4')
        ->and($instanceBefore)->toBe($this->instance->fresh()->getAttributes())
        ->and($routesBefore)->toBe(Route::query()->orderBy('id')->get()->map->getAttributes()->all())
        ->and(app(RoutePublicationSites::class)->current())->toBeNull();
    $normal = app(DevelopmentSiteRepository::class)->all();
    expect($normal->where('domain', 'native.test')->pluck('nodeId')->all())->toContain($this->node->id)
        ->and($normal->where('domain', 'native.destination.test'))->toBeEmpty();
});

it('clears the prospective publication view if canonical rendering throws', function (): void {
    $view = app(RoutePublicationSites::class);
    expect(fn () => $view->render(collect(), static fn () => throw new RuntimeException('render failed')))->toThrow(RuntimeException::class)
        ->and($view->current())->toBeNull();
});

it('renders the canonical collision-adjusted destination Vite identity instead of its source port', function (): void {
    $this->node->update(['wireguard_ip' => '10.44.0.11']);
    $this->destination->update(['wireguard_ip' => '10.44.0.12']);
    $this->destination->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    $this->instance->update(['vite_port' => 5173]);
    $plan = app(NativeRouteTransferPlan::class)->capture($this->transfer);
    $batch = ['instance_id' => $this->instance->id, 'node_id' => $this->destination->id,
        'target' => ['instance' => ['checkout_path' => $this->transfer->destination_path, 'root' => null,
            'source_is_laravel' => false, 'vite_port' => 5180, 'ssr_port' => 5181]]];
    $publications = app(NativeRouteTransferPublication::class)->capture($plan, $batch);
    $destination = collect($publications['nodes'])->firstWhere('node_id', $this->destination->id);
    expect($destination['after']['caddy']['content'])->toContain('5180')->not->toContain('5173')
        ->and($this->instance->fresh()->vite_port)->toBe(5173);
    $batch['node_id'] = $this->node->id;
    expect(fn () => app(NativeRouteTransferPublication::class)->capture($plan, $batch))->toThrow(ResourceOperationException::class);
});

it('uses the same staging and secondary placement sites for ordinary and prospective Route rendering', function (): void {
    $this->node->update(['wireguard_ip' => '10.44.0.11']);
    $this->destination->update(['wireguard_ip' => '10.44.0.12']);
    $this->extra->update(['transition_cluster_id' => $this->destinationCluster->id,
        'replacement_step' => RouteReplacementStep::RouterCertificate]);
    $repository = app(DevelopmentSiteRepository::class);
    $route = $this->extra->fresh(['targets.instance.project', 'targets.instance.node', 'cluster.routerAssignment.node', 'transitionCluster.routerAssignment.node']);
    $normal = $repository->all()->where('domain', $route->domain)->values()->all();
    $prospective = $repository->projectSites($route);
    expect(json_encode($prospective, JSON_THROW_ON_ERROR))->toBe(json_encode($normal, JSON_THROW_ON_ERROR))
        ->and(count($prospective))->toBeGreaterThan(1);
});
