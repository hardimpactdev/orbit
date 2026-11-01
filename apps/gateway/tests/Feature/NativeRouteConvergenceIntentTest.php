<?php

declare(strict_types=1);

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\NativeRouteConvergenceEnvironmentMaterial;
use App\Domain\Routes\NativeRouteConvergenceIntent;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Models\Cluster;
use App\Models\Instance;
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
});

afterEach(function (): void {
    unlink($this->manifest);
});

it('captures exact workload and certificate participants without declaring child Operations', function (): void {
    $intent = app(NativeRouteConvergenceIntent::class)->forRoute($this->route, []);
    expect($intent['node_ids'])->toBe([$this->node->id])
        ->and($intent['workloads'])->toBe([['instance_id' => $this->instance->id, 'node_id' => $this->node->id, 'production' => false, 'certificate_scope' => 'app-instance-'.$this->instance->id]])
        ->and($intent['certificates'][0])->toMatchArray(['node_id' => $this->node->id, 'scope' => 'app-instance-'.$this->instance->id, 'domain' => 'native.test'])
        ->and($intent)->not->toHaveKeys(['stages', 'children', 'descriptors'])
        ->and(Operation::query()->count())->toBe(0);
});

it('rejects an Instance outside the admitted Route target set', function (): void {
    expect(fn () => app(NativeRouteConvergenceIntent::class)->forRoute($this->route, ['instance_id' => $this->instance->id + 1]))
        ->toThrow(ResourceOperationException::class);
});

it('renders existing environment secrets only inside the owning Node private material', function (): void {
    $this->route->update(['status' => RouteStatus::Active]);
    $this->instance->update(['status' => InstanceState::Active, 'provisioning_step' => 'active', 'environment' => 'development', 'source_is_laravel' => true]);
    $this->instance->environmentValues()->create(['env_key' => 'APP_URL', 'env_value' => 'https://{{instance.domain}}']);
    $this->instance->environmentValues()->create(['env_key' => 'APP_KEY', 'env_value' => 'private-existing-key']);
    $intent = app(NativeRouteConvergenceIntent::class)->forRoute($this->route, []);
    $operation = new Operation(['kind' => 'route.create', 'target_type' => 'route', 'target_id' => $this->route->id, 'input' => ['route_convergence' => $intent, 'participant_node_ids' => [$this->node->id]]]);
    $writes = app(NativeRouteConvergenceEnvironmentMaterial::class)->forNode($operation, $this->node->id);
    expect($writes[0]['input']['content'])->toContain('private-existing-key')->toContain('https://native.test')
        ->and(json_encode($intent))->not->toContain('private-existing-key');
    expect(fn () => app(NativeRouteConvergenceEnvironmentMaterial::class)->forNode($operation, $this->node->id + 1))->toThrow(ResourceOperationException::class);
});

it('renders a fresh bound participant and rejects moved workloads', function (): void {
    $intent = app(NativeRouteConvergenceIntent::class)->forRoute($this->route, []);
    $operation = new Operation(['kind' => 'route.create', 'target_type' => 'route', 'target_id' => $this->route->id,
        'input' => ['route_convergence' => $intent, 'participant_node_ids' => [$this->node->id], 'environment_fields' => []]]);
    $public = app(NativeRouteConvergenceIntent::class)->publicInputForRoute($operation, $this->node->id, $intent, $this->route);
    expect($public['participant_node_id'])->toBe($this->node->id)
        ->and($public['caddy_publication']['node_id'])->toBe($this->node->id)
        ->and($public['workloads'][0]['instance_id'])->toBe($this->instance->id);
    expect(fn () => app(NativeRouteConvergenceIntent::class)->publicInputForRoute($operation, $this->node->id + 1, $intent, $this->route))
        ->toThrow(ResourceOperationException::class);

    $this->route->targets()->delete();
    expect(fn () => app(NativeRouteConvergenceIntent::class)->publicInputForRoute($operation, $this->node->id, $intent, $this->route))
        ->toThrow(ResourceOperationException::class);
});

it('captures initial LAN rollback without withdrawing an already published Route allowance', function (): void {
    $cluster = Cluster::query()->create(['name' => 'lan-cluster', 'state' => 'active']);
    $router = Node::query()->create(['name' => 'lan-router', 'public_ssh_host' => '192.0.2.12', 'wireguard_ip' => '10.44.0.12',
        'lan_ip' => '10.10.0.12', 'cluster_id' => $cluster->id, 'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active']);
    $router->roles()->create(['role' => RoleName::Router, 'cluster_id' => $cluster->id, 'status' => 'active']);
    $this->node->update(['cluster_id' => $cluster->id, 'lan_ip' => '10.10.0.11']);
    $this->route->update(['cluster_id' => $cluster->id, 'node_id' => null]);
    $intent = app(NativeRouteConvergenceIntent::class)->forRoute($this->route->fresh(), []);
    expect($intent['rollback_lan'])->toBe([['node_id' => $this->node->id, 'operation' => 'remove', 'route_id' => $this->route->id]])
        ->and(array_column($intent['rollback_removals'], 'scope'))->toBe(['route-'.$this->route->id.'-router']);
    $this->route->update(['status' => RouteStatus::Active]);
    expect(app(NativeRouteConvergenceIntent::class)->forRoute($this->route->fresh(), [])['rollback_lan'])->toBe([]);
});

it('prepares unpublished development source and selected PHP without publishing its site', function (): void {
    $this->instance->update(['selected_php_version' => '8.5']);
    $intent = app(NativeRouteConvergenceIntent::class)->forRoute($this->route, []);
    $operation = new Operation(['kind' => 'route.create', 'target_type' => 'route', 'target_id' => $this->route->id,
        'input' => ['route_convergence' => $intent, 'participant_node_ids' => $intent['node_ids']]]);

    $public = app(NativeRouteConvergenceIntent::class)->publicInputForRoute($operation, $this->node->id, $intent, $this->route);

    expect($public['workloads'][0]['source_access']['sites'])->toBe([['checkout' => '/srv/native', 'relative_root' => 'public', 'application' => '/srv/native']])
        ->and($public['workloads'][0]['packages']['configure'][0]['version'])->toBe('8.5')
        ->and($this->route->fresh()->sites_published)->toBeFalse();
});
