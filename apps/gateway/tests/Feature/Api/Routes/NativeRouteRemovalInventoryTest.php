<?php

declare(strict_types=1);

use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\NativeRouteRemovalCompletion;
use App\Domain\Routes\NativeRouteRemovalIntent;
use App\Domain\Routes\RouteStatus;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Support\Collection;

beforeEach(function (): void {
    $this->manifest = tempnam(sys_get_temp_dir(), 'removal-agent-');
    file_put_contents($this->manifest, json_encode(['commit' => str_repeat('b', 40), 'version' => '0.5.0', 'artifacts' => ['linux-x86_64' => ['sha256' => str_repeat('a', 64), 'url' => 'https://example.test/agent']]], JSON_THROW_ON_ERROR));
    app()->instance(AgentDelivery::class, new AgentDelivery($this->manifest, sys_get_temp_dir()));
    app()->instance(ManagedUserAccountResolver::class, new class implements ManagedUserAccountResolver
    {
        public function resolve(Node $node): ManagedUserAccount
        {
            return new ManagedUserAccount('orbit', 'orbit', '/home/orbit');
        }
    });
});

it('captures every current and transition placement without changing associations or Route state', function (): void {
    $cluster = Cluster::query()->create(['name' => 'current']);
    $oldCluster = Cluster::query()->create(['name' => 'old']);
    $workload = Node::query()->create(['name' => fake()->unique()->word(), 'public_ssh_host' => fake()->unique()->ipv4(), 'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active']);
    $basis = Node::query()->create(['name' => fake()->unique()->word(), 'public_ssh_host' => fake()->unique()->ipv4(), 'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active']);
    $router = Node::query()->create(['name' => 'current-router', 'public_ssh_host' => '192.0.2.1', 'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active', 'cluster_id' => $cluster->id]);
    $oldRouter = Node::query()->create(['name' => 'old-router', 'public_ssh_host' => '192.0.2.2', 'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active', 'cluster_id' => $oldCluster->id]);
    foreach ([$router, $oldRouter] as $node) {
        $node->roles()->create(['role' => RoleName::Router, 'cluster_id' => $node->cluster_id, 'status' => 'active']);
    }
    $project = Project::query()->create(['name' => 'Demo', 'slug' => 'demo', 'repository_url' => 'https://example.test/demo.git']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $workload->id, 'name' => 'dev', 'status' => 'active', 'checkout_path' => '/home/orbit/demo']);
    $route = Route::query()->create(['domain' => 'demo.orbit', 'publication' => 'private', 'provenance' => 'generated', 'project_id' => $project->id, 'node_id' => null,
        'cluster_id' => $cluster->id, 'transition_cluster_id' => $oldCluster->id, 'generation_basis_node_id' => $basis->id,
        'status' => RouteStatus::Pending, 'sites_published' => false, 'web_root' => 'docs/public']);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);

    $route->update(['status' => RouteStatus::Active, 'sites_published' => true]);
    $input = app(NativeRouteRemovalIntent::class)->capture($route);

    expect(array_column($input['nodes'], 'node_id'))->toContain($workload->id, $basis->id, $router->id, $oldRouter->id)
        ->and($route->fresh()->status)->toBe(RouteStatus::Active)
        ->and($route->fresh()->sites_published)->toBeTrue()
        ->and($route->targets()->count())->toBe(1);
    $nodes = collect($input['nodes'])->keyBy('node_id');
    expect($nodes[$workload->id]['certificate_scopes'])->toContain("route-{$route->id}")
        ->and($nodes[$router->id]['certificate_scopes'])->toContain("route-{$route->id}-router", "route-{$route->id}-router-hostname-change")
        ->and($nodes[$oldRouter->id]['certificate_scopes'])->toContain("route-{$route->id}-router", "route-{$route->id}-router-hostname-change")
        ->and($nodes[$basis->id]['lan_route_ids'])->toBe([$route->id]);
});

it('keeps PHP withdrawal when the parent has no Routes', function (): void {
    $node = Node::query()->create(['name' => fake()->unique()->word(), 'public_ssh_host' => fake()->unique()->ipv4(), 'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active']);
    $input = app(NativeRouteRemovalIntent::class)->captureMany(new Collection, [$node]);
    expect($input['routes'])->toBe([])->and($input['nodes'][0]['node_id'])->toBe($node->id)
        ->and($input['nodes'][0]['shared_php'])->toBeTrue()->and($input['dns_node_ids'])->toBe([]);
});

it('deletes only the captured Routes when the admitting parent records native success', function (): void {
    $node = Node::query()->create(['name' => 'proxy', 'public_ssh_host' => '192.0.2.44', 'user' => 'orbit', 'platform' => 'linux', 'status' => 'active']);
    $captured = Route::query()->create(['kind' => 'custom_proxy', 'node_id' => $node->id, 'domain' => fake()->unique()->domainName(), 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
    $other = Route::query()->create(['kind' => 'custom_proxy', 'node_id' => $node->id, 'domain' => fake()->unique()->domainName(), 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
    app(NativeRouteRemovalCompletion::class)->apply(['routes' => [['route_id' => $captured->id, 'domain' => $captured->domain, 'replacement_of' => null]]]);
    expect(Route::query()->whereKey($captured->id)->exists())->toBeFalse()->and(Route::query()->whereKey($other->id)->exists())->toBeTrue();
});

afterEach(function (): void {
    unlink($this->manifest);
});
