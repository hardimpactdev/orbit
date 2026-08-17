<?php

declare(strict_types=1);

use App\Data\Apps\LaravelCloudInstanceDriverConfigData;
use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process as OrbitProcess;
use App\Models\ProxyRoute;
use App\Models\Workspace;
use App\Services\Authorization\ServingNodeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

#[RequiresPermission('node:remove')]
final class DefaultRequiresPermissionFixture {}

#[RequiresPermission('workspace:setup', servingNode: ServingNode::WorkspaceOwning)]
final class WorkspaceRequiresPermissionFixture {}

function servingNodeRequest(array $routeParameters = [], array $input = []): Request
{
    $request = Request::create('/test', 'POST', $input);
    $route = new Route(['POST'], '/test', []);
    $route->bind($request);

    foreach ($routeParameters as $name => $value) {
        $route->setParameter($name, $value);
    }

    $request->setRouteResolver(static fn (): Route => $route);

    return $request;
}

describe('RequiresPermission attribute', function (): void {
    it('defaults to target serving-node resolution', function (): void {
        $attributes = new ReflectionClass(DefaultRequiresPermissionFixture::class)
            ->getAttributes(RequiresPermission::class);

        $attribute = $attributes[0]->newInstance();

        expect($attribute->permission)->toBe('node:remove')->and($attribute->servingNode)->toBe(ServingNode::Target);
    });

    it('stores explicit serving-node resolution', function (): void {
        $attributes = new ReflectionClass(WorkspaceRequiresPermissionFixture::class)
            ->getAttributes(RequiresPermission::class);

        $attribute = $attributes[0]->newInstance();

        expect($attribute->permission)
            ->toBe('workspace:setup')
            ->and($attribute->servingNode)
            ->toBe(ServingNode::WorkspaceOwning);
    });
});

describe('ServingNodeResolver', function (): void {
    it('resolves the active gateway node', function (): void {
        $gateway = Node::factory()->gateway()->create(['name' => 'gateway-1']);

        $resolved = new ServingNodeResolver()->resolve(servingNodeRequest(), ServingNode::Gateway);

        expect($resolved?->is($gateway))->toBeTrue();
    });

    it('resolves target nodes from route parameters', function (): void {
        $target = Node::factory()->create(['name' => 'app-1']);

        $resolved = new ServingNodeResolver()->resolve(
            servingNodeRequest(['name' => 'app-1']),
            ServingNode::Target,
        );

        expect($resolved?->is($target))->toBeTrue();
    });

    it('resolves project-owning nodes from project parameters', function (): void {
        $node = Node::factory()->create(['name' => 'app-node']);
        $app = App::factory()->create(['name' => 'docs']);
        Instance::factory()->for($app, 'app')->create([
            'driver_config' => new OrbitInstanceDriverConfigData(
                node_id: $node->id,
                node: $node->name,
                path: '/home/orbit/apps/docs',
                document_root: 'public',
            ),
        ]);

        $resolved = new ServingNodeResolver()->resolve(
            servingNodeRequest(['app' => 'docs']),
            ServingNode::AppOwning,
        );

        expect($resolved?->is($node))->toBeTrue();
    });

    it('resolves the serving node from instance placement, not a stale app node_id', function (): void {
        $realNode = Node::factory()->create(['name' => 'real-node']);
        // The app is logical-only; the concrete instance is placed on the real
        // node, which is the serving-node authority.
        $app = App::factory()->create(['name' => 'docs']);
        Instance::factory()->for($app, 'app')->create([
            'driver_config' => new OrbitInstanceDriverConfigData(
                node_id: $realNode->id,
                node: $realNode->name,
                path: '/home/orbit/apps/docs',
                document_root: 'public',
            ),
        ]);

        $resolved = new ServingNodeResolver()->resolve(
            servingNodeRequest(['app' => 'docs']),
            ServingNode::AppOwning,
        );

        expect($resolved?->is($realNode))->toBeTrue();
    });

    it('uses the gateway grant boundary for an external instance', function (): void {
        $gateway = Node::factory()->gateway()->create(['name' => 'gateway-1']);
        $app = App::factory()->create([
            'name' => 'billing',
        ]);
        $instance = Instance::factory()->create([
            'app_id' => $app->id,
            'name' => 'cloud',
            'driver' => 'laravel-cloud',
            'driver_config' => new LaravelCloudInstanceDriverConfigData(
                application_id: 'app_123',
                environment_id: 'env_123',
            ),
        ]);

        $resolved = new ServingNodeResolver()->resolve(
            servingNodeRequest(['instance' => 'billing.cloud']),
            ServingNode::InstanceOwning,
        );

        expect($resolved?->is($gateway))->toBeTrue();
    });

    it('resolves instance-owning nodes from separate project and instance route parameters', function (): void {
        $instanceNode = Node::factory()->create(['name' => 'instance-node']);
        $app = App::factory()->create(['name' => 'billing']);
        Instance::factory()->for($app)->create([
            'name' => 'development',
            'driver_config' => new OrbitInstanceDriverConfigData(
                node_id: $instanceNode->id,
                path: '/srv/billing-development',
                document_root: 'public',
                domain: 'billing-development.test',
            ),
        ]);

        $resolved = new ServingNodeResolver()->resolve(
            servingNodeRequest([
                'app' => 'billing',
                'instance' => 'development',
            ]),
            ServingNode::InstanceOwning,
        );

        expect($resolved?->is($instanceNode))->toBeTrue();
    });

    it('resolves project-owning nodes from process identity', function (): void {
        $node = Node::factory()->create(['name' => 'process-node']);
        $app = App::factory()->placedOn($node)->create(['name' => 'docs']);
        OrbitProcess::factory()->forOwner($app)->create(['name' => 'queue']);

        $resolved = new ServingNodeResolver()->resolve(
            servingNodeRequest(['name' => 'queue']),
            ServingNode::AppOwning,
        );

        expect($resolved?->is($node))->toBeTrue();
    });

    it('resolves workspace-owning nodes from workspace and instance parameters', function (): void {
        $node = Node::factory()->create(['name' => 'docs-node']);
        $otherNode = Node::factory()->create(['name' => 'other-node']);
        $app = App::factory()->create(['name' => 'docs']);
        $otherApp = App::factory()->create(['name' => 'other']);

        $instance = Instance::factory()->for($app)->create([
            'name' => 'development',
            'driver_config' => new OrbitInstanceDriverConfigData(
                node_id: $node->id,
                path: '/srv/docs-development',
                document_root: 'public',
                domain: 'docs.test',
            ),
        ]);
        $otherInstance = Instance::factory()->for($otherApp)->create([
            'name' => 'development',
            'driver_config' => new OrbitInstanceDriverConfigData(
                node_id: $otherNode->id,
                path: '/srv/other-development',
                document_root: 'public',
                domain: 'other.test',
            ),
        ]);

        Workspace::factory()->create([
            'app_id' => $app->id,
            'instance_id' => $instance->id,
            'name' => 'feature',
        ]);
        Workspace::factory()->create([
            'app_id' => $otherApp->id,
            'instance_id' => $otherInstance->id,
            'name' => 'feature',
        ]);

        $resolved = new ServingNodeResolver()->resolve(
            servingNodeRequest(['workspace' => 'feature'], ['instance' => 'docs.development']),
            ServingNode::WorkspaceOwning,
        );

        expect($resolved?->is($node))->toBeTrue();
    });

    it('does not resolve the removed project request alias', function (): void {
        App::factory()->create(['name' => 'docs']);

        $resolved = new ServingNodeResolver()->resolve(
            servingNodeRequest(['project' => 'docs']),
            ServingNode::AppOwning,
        );

        expect($resolved)->toBeNull();
    });

    it('resolves app-owning nodes from a registered app hostname selector before process name', function (): void {
        $targetNode = Node::factory()->create(['name' => 'target-node']);
        $targetApp = App::factory()->create(['name' => 'docs']);
        $otherApp = App::factory()->create(['name' => 'other']);
        $instance = Instance::factory()->create([
            'app_id' => $targetApp->id,
            'name' => 'development',
            'driver_config' => new OrbitInstanceDriverConfigData(node_id: $targetNode->id),
        ]);
        OrbitProcess::factory()->forOwner($otherApp)->create(['name' => 'vite']);
        OrbitProcess::factory()->forOwner($targetApp)->create(['name' => 'vite']);
        ProxyRoute::factory()->create([
            'node_id' => $targetNode->id,
            'domain' => 'docs-dev.example',
            'app_id' => $targetApp->id,
            'instance_id' => $instance->id,
            'owner_type' => 'app',
            'kind' => 'app',
            'config' => [
                'instance' => [
                    'name' => 'development',
                    'selector' => 'docs.development',
                ],
            ],
        ]);

        $resolved = new ServingNodeResolver()->resolve(
            servingNodeRequest([], [
                'app' => 'docs-dev.example',
                'name' => 'vite',
            ]),
            ServingNode::AppOwning,
        );

        expect($resolved?->is($targetNode))->toBeTrue();
    });

    it('does not resolve an app hostname whose compatibility app_id disagrees with its instance', function (): void {
        $targetNode = Node::factory()->create(['name' => 'target-node-mismatch']);
        $owner = App::factory()->create(['name' => 'owner']);
        $compatibility = App::factory()->create(['name' => 'compatibility']);
        $instance = Instance::factory()->for($owner)->create([
            'driver_config' => new OrbitInstanceDriverConfigData(node_id: $targetNode->id),
        ]);
        $route = ProxyRoute::factory()->create([
            'node_id' => $targetNode->id,
            'domain' => 'mismatch.example',
            'app_id' => $owner->id,
            'instance_id' => $instance->id,
            'owner_type' => 'app',
            'kind' => 'app',
        ]);
        $route->forceFill(['app_id' => $compatibility->id])->save();

        $resolved = new ServingNodeResolver()->resolve(
            servingNodeRequest([], ['app' => 'mismatch.example']),
            ServingNode::AppOwning,
        );

        expect($resolved)->toBeNull();
    });

    it('resolves app-owning nodes from a registered workspace hostname selector', function (): void {
        $targetNode = Node::factory()->create(['name' => 'workspace-node']);
        $app = App::factory()->create(['name' => 'docs']);
        $instance = Instance::factory()->create([
            'app_id' => $app->id,
            'name' => 'development',
            'driver_config' => new OrbitInstanceDriverConfigData(node_id: $targetNode->id),
        ]);
        $workspace = Workspace::factory()->create([
            'name' => 'feature-docs',
            'app_id' => $app->id,
            'instance_id' => $instance->id,
        ]);
        ProxyRoute::factory()->create([
            'node_id' => $targetNode->id,
            'domain' => 'feature-docs.example',
            'app_id' => $app->id,
            'workspace_id' => $workspace->id,
            'instance_id' => $instance->id,
            'owner_type' => 'workspace',
            'kind' => 'workspace',
        ]);

        $resolved = new ServingNodeResolver()->resolve(
            servingNodeRequest([], [
                'app' => 'feature-docs.example',
                'name' => 'vite',
            ]),
            ServingNode::AppOwning,
        );

        expect($resolved?->is($targetNode))->toBeTrue();
    });

    it('resolves the caller node from the request user', function (): void {
        $caller = Node::factory()->create(['name' => 'caller-1']);
        $request = servingNodeRequest();
        $request->setUserResolver(static fn (): Node => $caller);

        $resolved = new ServingNodeResolver()->resolve($request, ServingNode::Caller);

        expect($resolved?->is($caller))->toBeTrue();
    });

    it('returns null when the serving node cannot be resolved', function (): void {
        $resolved = new ServingNodeResolver()->resolve(servingNodeRequest(), ServingNode::Target);

        expect($resolved)->toBeNull();
    });
});
