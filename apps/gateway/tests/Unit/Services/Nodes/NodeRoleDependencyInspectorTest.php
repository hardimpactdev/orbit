<?php

declare(strict_types=1);

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Enums\Apps\InstanceDriver;
use App\Models\App;
use App\Models\Instance;
use App\Models\NodeRoleAssignment;
use App\Models\ProxyRoute;
use App\Models\Workspace;
use App\Services\Nodes\Roles\NodeRoleDependencyInspector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('removes only the node-bound instance and preserves an app instance on another node', function (): void {
    $prodNode = createTestAppHostNode(['name' => 'prod-node', 'tld' => 'prodtld'], 'app-prod');
    $devNode = createTestAppHostNode(['name' => 'dev-node', 'tld' => 'devtld']);

    // One logical app spanning two nodes: production on the prod node, development
    // on the dev node.
    $app = App::factory()->create(['name' => 'multi']);
    $productionInstance = Instance::factory()->for($app, 'app')->create([
        'name' => 'production',
        'driver' => InstanceDriver::Orbit,
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $prodNode->id,
            node: $prodNode->name,
            path: '/srv/apps/multi',
            document_root: 'public',
            domain: 'multi.example.com',
        ),
    ]);
    $developmentInstance = Instance::factory()->for($app, 'app')->create([
        'name' => 'development',
        'driver' => InstanceDriver::Orbit,
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $devNode->id,
            node: $devNode->name,
            path: '/home/orbit/apps/multi',
            document_root: 'public',
        ),
    ]);

    $assignment = new NodeRoleAssignment(['role' => 'app-prod']);
    $inspector = new NodeRoleDependencyInspector;

    // The production instance on the prod node is the single dependent.
    expect($inspector->dependentSummaries($prodNode, $assignment))
        ->toBe(['1 production app record']);

    $inspector->removeOrbitOwnedDependents($prodNode, $assignment);

    // Per-instance removal: the production instance on the prod node is gone,
    // but the logical app and its development instance on the other node survive.
    expect(Instance::query()->whereKey($productionInstance->id)->exists())
        ->toBeFalse()
        ->and(Instance::query()->whereKey($developmentInstance->id)->exists())
        ->toBeTrue()
        ->and(App::query()->whereKey($app->id)->exists())
        ->toBeTrue();
});

it('deletes the app only once its final instance is removed with the node role', function (): void {
    $devNode = createTestAppHostNode(['name' => 'dev-only-node', 'tld' => 'devonly']);

    $app = App::factory()->create(['name' => 'solo']);
    Instance::factory()->for($app, 'app')->create([
        'name' => 'development',
        'driver' => InstanceDriver::Orbit,
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $devNode->id,
            node: $devNode->name,
            path: '/home/orbit/apps/solo',
            document_root: 'public',
        ),
    ]);

    $inspector = new NodeRoleDependencyInspector;
    $inspector->removeOrbitOwnedDependents($devNode, new NodeRoleAssignment(['role' => 'app-dev']));

    // The app had no other instance, so it is removed entirely.
    expect(App::query()->whereKey($app->id)->exists())
        ->toBeFalse()
        ->and(Instance::query()->where('app_id', $app->id)->exists())
        ->toBeFalse();
});

it('classifies ingress routes by their concrete instance instead of any app sibling', function (): void {
    $ingress = createTestAppHostNode(['name' => 'ingress-node', 'tld' => 'edge'], 'ingress');
    $productionNode = createTestAppHostNode(['name' => 'production-node', 'tld' => 'prod'], 'app-prod');
    $developmentNode = createTestAppHostNode(['name' => 'development-node', 'tld' => 'dev']);
    $app = App::factory()->create(['name' => 'multi']);
    $compatibilityApp = App::factory()->create(['name' => 'compatibility']);
    $production = Instance::factory()->for($app)->create([
        'name' => 'production',
        'driver' => InstanceDriver::Orbit,
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $productionNode->id,
            domain: 'multi.example.com',
        ),
    ]);
    $development = Instance::factory()->for($app)->create([
        'name' => 'development',
        'driver' => InstanceDriver::Orbit,
        'driver_config' => new OrbitInstanceDriverConfigData(node_id: $developmentNode->id),
    ]);
    $developmentRoute = ProxyRoute::factory()->create([
        'node_id' => $ingress->id,
        'app_id' => $app->id,
        'instance_id' => $development->id,
        'domain' => 'development.example.com',
        'owner_type' => 'app',
        'kind' => 'app',
        'config' => ['placement' => 'ingress'],
    ]);
    $validProductionRoute = ProxyRoute::factory()->create([
        'node_id' => $ingress->id,
        'app_id' => $app->id,
        'instance_id' => $production->id,
        'domain' => 'valid-production.example.com',
        'owner_type' => 'app',
        'kind' => 'app',
        'config' => ['placement' => 'ingress'],
    ]);
    $productionRoute = ProxyRoute::factory()->create([
        'node_id' => $ingress->id,
        'app_id' => $app->id,
        'instance_id' => $production->id,
        'domain' => 'production.example.com',
        'owner_type' => 'app',
        'kind' => 'app',
        'config' => ['placement' => 'ingress'],
    ]);
    $productionRoute->forceFill(['app_id' => $compatibilityApp->id])->save();

    new NodeRoleDependencyInspector()->removeOrbitOwnedDependents(
        $ingress,
        new NodeRoleAssignment(['role' => 'ingress']),
    );

    expect(ProxyRoute::query()->whereKey($developmentRoute->id)->exists())
        ->toBeTrue()
        ->and(ProxyRoute::query()->whereKey($validProductionRoute->id)->exists())
        ->toBeFalse()
        ->and(ProxyRoute::query()->whereKey($productionRoute->id)->exists())
        ->toBeTrue();
});

it('does not classify or remove a workspace ingress route with conflicting app ownership', function (): void {
    $ingress = createTestAppHostNode(['name' => 'ingress-node', 'tld' => 'edge'], 'ingress');
    $productionNode = createTestAppHostNode(['name' => 'production-node', 'tld' => 'prod'], 'app-prod');
    $app = App::factory()->create(['name' => 'docs']);
    $otherApp = App::factory()->create(['name' => 'other']);
    $production = Instance::factory()->for($app)->create([
        'name' => 'production',
        'driver' => InstanceDriver::Orbit,
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $productionNode->id,
            domain: 'docs.example.com',
        ),
    ]);
    $workspace = Workspace::factory()->for($app)->create([
        'name' => 'feature',
        'instance_id' => $production->id,
    ]);
    $route = ProxyRoute::factory()->create([
        'node_id' => $ingress->id,
        'app_id' => $app->id,
        'instance_id' => $production->id,
        'workspace_id' => $workspace->id,
        'domain' => 'feature.docs.example.com',
        'owner_type' => 'workspace',
        'kind' => 'workspace',
        'config' => ['placement' => 'ingress'],
    ]);
    $workspace->forceFill(['app_id' => $otherApp->id])->save();
    $assignment = new NodeRoleAssignment(['role' => 'ingress']);
    $inspector = new NodeRoleDependencyInspector;

    expect($inspector->dependentSummaries($ingress, $assignment))->toBe([]);

    $inspector->removeOrbitOwnedDependents($ingress, $assignment);

    expect(ProxyRoute::query()->whereKey($route->id)->exists())->toBeTrue();
});

it('does not summarize or remove an ingress app route with invalid ownership', function (string $invalidity): void {
    $ingress = createTestAppHostNode(['name' => 'ingress-node', 'tld' => 'edge'], 'ingress');
    $productionNode = createTestAppHostNode(['name' => 'production-node', 'tld' => 'prod'], 'app-prod');
    $app = App::factory()->create(['name' => 'docs']);
    $instance = Instance::factory()->for($app)->create([
        'name' => 'production',
        'driver' => InstanceDriver::Orbit,
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $productionNode->id,
            domain: 'docs.example.com',
        ),
    ]);
    $route = ProxyRoute::factory()->create([
        'node_id' => $ingress->id,
        'app_id' => $app->id,
        'instance_id' => $instance->id,
        'domain' => 'docs.example.com',
        'owner_type' => 'app',
        'kind' => 'app',
        'config' => ['placement' => 'ingress'],
    ]);

    if ($invalidity === 'missing app') {
        $route->forceFill(['app_id' => null])->save();
    }

    if ($invalidity === 'missing instance') {
        $route->forceFill(['instance_id' => null])->save();
    }

    if ($invalidity === 'conflicting app') {
        $route->forceFill(['app_id' => App::factory()->create()->id])->save();
    }

    if ($invalidity === 'wrong kind') {
        $route->forceFill(['kind' => 'proxy'])->save();
    }

    if ($invalidity === 'workspace identity') {
        $workspace = Workspace::factory()->for($app)->create(['instance_id' => $instance->id]);
        $route->forceFill(['workspace_id' => $workspace->id])->save();
    }

    $assignment = new NodeRoleAssignment(['role' => 'ingress']);
    $inspector = new NodeRoleDependencyInspector;

    expect($inspector->dependentSummaries($ingress, $assignment))->toBe([]);

    $inspector->removeOrbitOwnedDependents($ingress, $assignment);

    expect(ProxyRoute::query()->whereKey($route->id)->exists())->toBeTrue();
})->with([
    'missing app',
    'missing instance',
    'conflicting app',
    'wrong kind',
    'workspace identity',
]);
