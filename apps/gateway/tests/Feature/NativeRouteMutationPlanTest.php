<?php

declare(strict_types=1);

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationContribution;
use App\Domain\Routes\NativeRouteAdmission;
use App\Domain\Routes\NativeRouteConvergenceProjection;
use App\Domain\Routes\NativeRouteMutationPlan;
use App\Domain\Routes\RouteEnvironmentMaterialSource;
use App\Domain\Routes\RoutePlacement;
use App\Domain\Routes\RouteReplacementStep;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;

beforeEach(function (): void {
    $this->manifest = tempnam(sys_get_temp_dir(), 'batch-agent-');
    file_put_contents($this->manifest, json_encode(['commit' => str_repeat('b', 40), 'version' => '0.5.0', 'artifacts' => ['linux-x86_64' => ['sha256' => str_repeat('a', 64), 'url' => 'https://example.test/agent']]], JSON_THROW_ON_ERROR));
    app()->instance(AgentDelivery::class, new AgentDelivery($this->manifest, sys_get_temp_dir()));
    $this->mock(LeafCertificateSigner::class)->shouldReceive('rootCertificate')->andReturn('public-root');
    $this->mock(ManagedUserAccountResolver::class)->shouldNotReceive('resolve');
});
afterEach(function (): void {
    unlink($this->manifest);
});

/** @return array{Route,Node,Node,Cluster} */
function mutation_batch_fixture(bool $generated = false): array
{
    $node = Node::query()->create(['name' => 'batch-workload', 'public_ssh_host' => '192.0.2.31', 'wireguard_ip' => '10.44.0.31', 'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active']);
    $node->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    $router = Node::query()->create(['name' => 'batch-router', 'public_ssh_host' => '192.0.2.32', 'wireguard_ip' => '10.44.0.32', 'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active']);
    $cluster = Cluster::query()->create(['name' => 'batch-cluster', 'tld' => 'private.test', 'state' => 'active']);
    $router->update(['cluster_id' => $cluster->id]);
    $router->roles()->create(['role' => RoleName::Router, 'cluster_id' => $cluster->id, 'status' => 'active']);
    $project = Project::query()->create(['name' => 'Batch', 'slug' => 'batch', 'repository_url' => 'https://example.test/batch.git', 'root' => 'public']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'development', 'checkout_path' => '/srv/batch', 'status' => 'active', 'source_is_laravel' => false]);
    $route = Route::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'domain' => 'batch.private.test', 'publication' => 'private', 'provenance' => $generated ? 'generated' : 'explicit', 'generation_basis_node_id' => $generated ? $node->id : null, 'status' => 'pending']);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => 'active', 'sites_published' => true]);

    return [$route, $node, $router, $cluster];
}

it('captures a placement batch without an Operation or a host account lookup', function (): void {
    [$route, $node, $router, $cluster] = mutation_batch_fixture();
    $plan = app(NativeRouteMutationPlan::class)->capture([['route' => $route, 'domain' => $route->domain, 'placement' => new RoutePlacement(null, $cluster->id, null)]]);

    expect($plan['node_ids'])->toContain($node->id, $router->id)
        ->and($plan['changes'][0]['desired']['certificates'][0]['scope'])->toEndWith('-hostname-change')
        ->and($plan['changes'][0]['cleanup']['certificates'][0]['scope'])->not->toEndWith('-hostname-change')
        ->and($route->fresh()->cluster_id)->toBeNull()->and($route->fresh()->transition_cluster_id)->toBeNull()
        ->and(Operation::query()->count())->toBe(0);
});

it('rolls the complete reservation transaction back when a later proposal conflicts', function (): void {
    [$route] = mutation_batch_fixture();
    expect(fn () => app(NativeRouteMutationPlan::class)->capture([
        ['route' => $route, 'domain' => 'new.private.test'], ['route' => $route, 'domain' => 'second.private.test'],
    ]))->toThrow(ResourceOperationException::class);
    expect(Route::query()->count())->toBe(1)->and($route->fresh()->replaced_by_route_id)->toBeNull();
});

it('keeps old and candidate placements serving until native DNS grace and withdrawal', function (): void {
    [$route, $node, $router, $cluster] = mutation_batch_fixture();
    $lower = app(NativeRouteMutationPlan::class);
    $plan = $lower->capture([['route' => $route, 'domain' => $route->domain, 'placement' => new RoutePlacement(null, $cluster->id, null)]]);
    $lower->checkpoint($plan, 'workloads-prepared');
    $lower->checkpoint($plan, 'router-prepared');
    expect($route->fresh()->node_id)->toBe($node->id)->and($route->fresh()->transition_cluster_id)->toBe($cluster->id);
    $lower->checkpoint($plan, 'commit');
    $lower->checkpoint($plan, 'commit');
    expect($route->fresh()->cluster_id)->toBe($cluster->id)->and($route->fresh()->transition_node_id)->toBe($node->id);
    expect(fn () => $lower->checkpoint($plan, 'rollback'))->toThrow(ResourceOperationException::class);
    $lower->checkpoint($plan, 'dns-published');
    expect($route->fresh()->transition_dns_moved_at)->not->toBeNull();
    $lower->checkpoint($plan, 'withdraw');
    expect($route->fresh()->replacement_step)->toBe(RouteReplacementStep::Cleanup);
    $lower->checkpoint($plan, 'complete');
    expect($route->fresh()->status)->toBe(RouteStatus::Active)->and($route->fresh()->transition_node_id)->toBeNull();
});

it('reserves renames and restores the original association before removing a candidate', function (): void {
    [$route] = mutation_batch_fixture();
    $lower = app(NativeRouteMutationPlan::class);
    $plan = $lower->capture([['route' => $route, 'domain' => 'new.private.test']]);
    $candidate = Route::query()->findOrFail($plan['changes'][0]['result_route_id']);
    $lower->checkpoint($plan, 'workloads-prepared');
    expect($candidate->fresh()->sites_published)->toBeTrue()->and($route->fresh()->status)->toBe(RouteStatus::Active);
    $lower->checkpoint($plan, 'rollback');
    expect($candidate->fresh()->sites_published)->toBeFalse()->and($route->fresh()->status)->toBe(RouteStatus::Active);
    $lower->checkpoint($plan, 'rollback-complete');
    $lower->checkpoint($plan, 'rollback-complete');
    $this->assertModelMissing($candidate);
    expect($route->fresh()->replaced_by_route_id)->toBeNull()->and($route->targets()->count())->toBe(1);
});

it('uses a truthful Cluster parent and fresh loaded scopes in a cancelled recovery view', function (): void {
    [$route, $node, $router, $cluster] = mutation_batch_fixture();
    $lower = app(NativeRouteMutationPlan::class);
    $plan = $lower->capture([['route' => $route, 'domain' => 'new.private.test', 'placement' => new RoutePlacement(null, $cluster->id, null)]]);
    $lower->checkpoint($plan, 'workloads-prepared');
    $lower->checkpoint($plan, 'router-prepared');
    $operation = new Operation(['kind' => 'cluster.attach', 'target_type' => 'cluster', 'target_id' => $cluster->id, 'node_id' => $node->id,
        'status' => 'running', 'cancel_requested' => true, 'input' => ['route_mutations' => json_decode(json_encode($plan, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR), 'participant_node_ids' => $plan['node_ids']], 'state' => []]);
    $view = $lower->publicInput($operation, $router->id);
    expect($view['participant_node_id'])->toBe($router->id)->and($operation->kind)->toBe('cluster.attach')
        ->and($operation->node_id)->toBe($node->id)->and($view['route_mutations'][0]['route']['domain'])->toBe('new.private.test');
    $lower->checkpoint($plan, 'rollback');
    $operation->state = ['native_route_mutations_rollback' => true];
    $restored = $lower->publicInput($operation, $node->id);
    $scopes = array_column($restored['route_mutations'][0]['cleanup'], 'scope');
    expect($restored['route_mutations'][0]['route']['domain'])->toBe('batch.private.test')
        ->and($scopes)->not->toContain('app-instance-'.$route->targets()->firstOrFail()->instance_id)
        ->and($scopes)->toContain('app-instance-'.$route->targets()->firstOrFail()->instance_id.'-hostname-change');
    expect(fn () => $lower->publicInput($operation, 999))->toThrow(ResourceOperationException::class);
});

it('commits a generated rename and placement without losing targets or the served web root', function (): void {
    [$route, $node, $router, $cluster] = mutation_batch_fixture(true);
    $route->update(['web_root' => 'docs/public']);
    $lower = app(NativeRouteMutationPlan::class);
    $plan = $lower->capture([['route' => $route, 'domain' => 'new.private.test', 'placement' => new RoutePlacement(null, $cluster->id, null)]]);
    $candidate = Route::query()->findOrFail($plan['changes'][0]['result_route_id']);
    expect($candidate->web_root)->toBe('docs/public');
    $lower->checkpoint($plan, 'workloads-prepared');
    $lower->checkpoint($plan, 'router-prepared');
    $lower->checkpoint($plan, 'commit');
    expect($route->fresh()->status)->toBe(RouteStatus::Retiring);
    $sites = app(DevelopmentSiteRepository::class)->forNode($node);
    expect($sites->contains('domain', $route->domain))->toBeTrue();
    $lower->checkpoint($plan, 'withdraw');
    expect(app(DevelopmentSiteRepository::class)->forNode($node)->contains('domain', $route->domain))->toBeFalse();
    $lower->checkpoint($plan, 'complete');
    $this->assertModelMissing($route);
    $lower->checkpoint($plan, 'complete');
    expect(fn () => $lower->checkpoint($plan, 'router-prepared'))->toThrow(ResourceOperationException::class);
    expect($candidate->fresh()->cluster_id)->toBe($cluster->id)->and($candidate->fresh()->targets()->count())->toBe(1)
        ->and($candidate->fresh()->web_root)->toBe('docs/public');
});

it('uses valid workload staging scopes for a web-root placement without a rename', function (): void {
    [$route, $node, $router, $cluster] = mutation_batch_fixture();
    $route->update(['web_root' => 'docs/public']);
    $plan = app(NativeRouteMutationPlan::class)->capture([['route' => $route, 'domain' => $route->domain, 'placement' => new RoutePlacement(null, $cluster->id, null)]]);
    $instanceId = $route->targets()->firstOrFail()->instance_id;
    expect($plan['changes'][0]['desired']['certificates'][0]['scope'])->toBe('app-instance-'.$instanceId.'-hostname-change');
});

it('retires same-domain detached LAN rules after withdrawal', function (): void {
    [$route, $node, $router, $cluster] = mutation_batch_fixture();
    $route->update(['node_id' => null, 'cluster_id' => $cluster->id]);
    $node->update(['lan_ip' => '192.168.6.18']);
    $router->update(['lan_ip' => '192.168.6.1']);
    $lower = app(NativeRouteMutationPlan::class);
    $plan = $lower->capture([['route' => $route->fresh(), 'domain' => $route->domain, 'placement' => new RoutePlacement($node->id, null, null)]]);
    $lower->checkpoint($plan, 'workloads-prepared');
    $lower->checkpoint($plan, 'router-prepared');
    $lower->checkpoint($plan, 'commit');
    $lower->checkpoint($plan, 'withdraw');
    $operation = new Operation(['kind' => 'cluster.detach', 'node_id' => $node->id, 'input' => ['route_mutations' => $plan, 'participant_node_ids' => $plan['node_ids']]]);
    expect($lower->publicInput($operation, $node->id)['route_mutations'][0]['cleanup_lan'])->toBe([['operation' => 'retire', 'route_id' => $route->id, 'keep' => []]]);
});

it('admits a batch environment owner union for separate Instances on the same Node', function (): void {
    [$first, $node] = mutation_batch_fixture();
    $project = Project::query()->create(['name' => 'Second Batch', 'slug' => 'second-batch', 'repository_url' => 'https://example.test/second.git', 'root' => 'public']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'development', 'checkout_path' => '/srv/second', 'status' => 'active', 'source_is_laravel' => false]);
    $second = Route::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'domain' => 'second.private.test', 'publication' => 'private', 'provenance' => 'explicit', 'status' => 'pending']);
    $second->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $second->update(['status' => 'active', 'sites_published' => true]);
    $lower = app(NativeRouteMutationPlan::class);
    $plan = $lower->capture([['route' => $first, 'domain' => 'first-new.private.test'], ['route' => $second, 'domain' => 'second-new.private.test']]);
    $operation = new Operation(['kind' => 'node.add', 'node_id' => $node->id, 'input' => ['route_mutations' => $plan, 'participant_node_ids' => $plan['node_ids']]]);
    $view = $lower->publicInput($operation, $node->id);
    expect(array_column($view['route_mutations'][0]['environment_workloads'], 'instance_id'))
        ->toBe([$first->targets()->firstOrFail()->instance_id, $instance->id])
        ->and($view['route_mutations'][1]['environment_workloads'])->toBe($view['route_mutations'][0]['environment_workloads']);
});

it('reuses declared environment custody for a truthful composing parent', function (): void {
    [$route, $node, $router, $cluster] = mutation_batch_fixture();
    $lower = app(NativeRouteMutationPlan::class);
    $plan = $lower->capture([['route' => $route, 'domain' => 'new.private.test']]);
    $contribution = new class implements DirectOperationContribution
    {
        public function definitions(): array
        {
            return ['cluster.attach' => ['workflow' => 'operation-cluster-attach', 'projection' => NativeRouteConvergenceProjection::class,
                'private_material' => NativeRouteAdmission::privateMaterial()]];
        }
    };
    app()->instance(DirectOperation::class, new DirectOperation([$contribution]));
    $input = ['route_mutations' => $plan, ...NativeRouteAdmission::environmentBindings($plan['node_ids'], $node->id, $cluster->id)];
    $operation = new Operation(['kind' => 'cluster.attach', 'target_type' => 'cluster', 'target_id' => $cluster->id, 'node_id' => $node->id, 'input' => $input]);
    $field = $input['environment_fields'][(string) $node->id];
    $source = app(RouteEnvironmentMaterialSource::class);
    expect($source->ownerId($operation))->toBe($cluster->id)
        ->and($source->resolve($operation, $cluster->id, '', $field)->value)->toBe([]);
    expect(fn () => $source->resolve($operation, $cluster->id + 1, '', $field))->toThrow(ResourceOperationException::class);
    expect(fn () => $source->resolve($operation, $cluster->id, '', 'not_declared'))->toThrow(ResourceOperationException::class);
    $foreign = clone $operation;
    $foreign->kind = 'instance.transfer';
    expect($source->ownerId($foreign))->toBeNull();
    expect(fn () => NativeRouteAdmission::environmentBindings(range(1, 65), 1, 7))->toThrow(ResourceOperationException::class);
});
