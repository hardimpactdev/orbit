<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeEnrollmentRoutes;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\GeneratedRouteUpdateOwnership;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
use App\Models\RouteCustomProxy;
use Tests\Support\Fleet\FleetFixtures;

it('captures a generated domain change without changing the Node or Route', function (): void {
    $node = FleetFixtures::node('enrollment-domain', [RoleName::AppDev]);
    $node->update(['tld' => 'before.test']);
    $project = Project::query()->create(['slug' => 'enrollment', 'name' => 'Enrollment', 'repository_url' => 'https://example.test/enrollment.git']);
    $route = Route::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'generation_basis_node_id' => $node->id,
        'domain' => 'enrollment.before.test', 'provenance' => 'generated', 'publication' => 'private', 'status' => 'pending']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default', 'checkout_path' => '/srv/enrollment', 'status' => 'active']);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => 'active']);
    $capture = app(NodeEnrollmentRoutes::class)->capture($node, 'after.test', null);

    expect($capture)->toBe([['route_id' => $route->id, 'domain' => 'enrollment.after.test', 'node_id' => $node->id]]);
    $steps = app(NodeEnrollmentRoutes::class)->steps($capture);
    expect($steps)->toBe([['type' => 'child', 'key' => 'add-route-'.$route->id, 'kind' => 'route.update', 'node_id' => $node->id,
        'input' => ['route_id' => $route->id, 'domain' => 'enrollment.after.test'], 'optional' => false]]);
    $parent = new Operation(['kind' => 'node.add', 'target_type' => 'node', 'target_id' => $node->id,
        'state' => ['enrollment_intent' => ['route_changes' => $capture]]]);
    expect(app(GeneratedRouteUpdateOwnership::class)->allows($parent, $route, $steps[0]))->toBeTrue();
    expect($node->fresh()->tld)->toBe('before.test');
    expect($route->fresh()->domain)->toBe('enrollment.before.test');
});

it('uses the lowest stored target Instance id before Route placement or generation basis', function (): void {
    $basis = FleetFixtures::node('enrollment-basis');
    $lowest = FleetFixtures::node('enrollment-lowest');
    $positionFirst = FleetFixtures::node('enrollment-position');
    $cluster = Cluster::query()->create(['name' => 'Ordering cluster']);
    foreach ([$basis, $lowest, $positionFirst] as $node) {
        $node->update(['cluster_id' => $cluster->id]);
        $node->roles()->create(['role' => RoleName::AppProd, 'status' => 'active']);
    }
    $project = Project::query()->create(['slug' => 'ordering', 'name' => 'Ordering', 'repository_url' => 'https://example.test/ordering.git']);
    $route = Route::query()->create(['project_id' => $project->id, 'cluster_id' => $cluster->id,
        'domain' => 'ordering.test', 'provenance' => 'explicit', 'publication' => 'private']);
    $first = Instance::query()->create(['project_id' => $project->id, 'node_id' => $lowest->id, 'name' => 'lowest', 'checkout_path' => '/srv/lowest']);
    $second = Instance::query()->create(['project_id' => $project->id, 'node_id' => $positionFirst->id, 'name' => 'position', 'checkout_path' => '/srv/position']);
    $route->targets()->create(['instance_id' => $second->id, 'position' => 0]);
    $association = $route->targets()->create(['instance_id' => $first->id, 'position' => 1]);

    expect(app(NodeEnrollmentRoutes::class)->targetNode($route))->toBe($lowest->id);
    $association->delete();
    expect(app(NodeEnrollmentRoutes::class)->targetNode($route->fresh()))->toBe($positionFirst->id);
    $route->targets()->delete();
    $route->update(['node_id' => $basis->id, 'cluster_id' => null]);
    expect(app(NodeEnrollmentRoutes::class)->targetNode($route->fresh()))->toBe($basis->id);
});

it('falls back from the Route Node to the active stored Router and custom proxy', function (): void {
    $direct = FleetFixtures::node('enrollment-direct');
    $router = FleetFixtures::node('enrollment-router');
    $proxy = FleetFixtures::node('enrollment-proxy');
    $cluster = Cluster::query()->create(['name' => 'Enrollment routing']);
    $router->update(['cluster_id' => $cluster->id]);
    $router->roles()->create(['role' => RoleName::Router, 'cluster_id' => $cluster->id, 'status' => 'active']);
    $route = Route::query()->create(['kind' => 'custom_proxy', 'node_id' => $direct->id,
        'domain' => 'proxy.example.test', 'provenance' => 'explicit', 'publication' => 'private']);
    RouteCustomProxy::query()->create(['route_id' => $route->id, 'node_id' => $proxy->id, 'upstream' => 'http://127.0.0.1:8080']);

    expect(app(NodeEnrollmentRoutes::class)->targetNode($route))->toBe($direct->id);
    $route->update(['node_id' => null, 'cluster_id' => $cluster->id, 'publication' => 'public']);
    expect(app(NodeEnrollmentRoutes::class)->targetNode($route->fresh()))->toBe($router->id);
    $router->roles()->update(['status' => 'provisioning']);
    expect(app(NodeEnrollmentRoutes::class)->targetNode($route->fresh()))->toBe($proxy->id);
    $route->customProxy()->delete();
    expect(fn () => app(NodeEnrollmentRoutes::class)->targetNode($route->fresh()))->toThrow(ResourceOperationException::class);
});
