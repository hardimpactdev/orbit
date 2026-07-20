<?php

declare(strict_types=1);

use App\Data\Apps\LaravelCloudAppInstanceDriverConfigData;
use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Process as OrbitProcess;
use App\Models\Project;
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
        Project::factory()->create(['name' => 'docs', 'node_id' => $node->id]);

        $resolved = new ServingNodeResolver()->resolve(
            servingNodeRequest(['project' => 'docs']),
            ServingNode::AppOwning,
        );

        expect($resolved?->is($node))->toBeTrue();
    });

    it('uses the gateway grant boundary for an external instance', function (): void {
        $gateway = Node::factory()->gateway()->create(['name' => 'gateway-1']);
        $app = Project::factory()->create([
            'name' => 'billing',
            'environment' => 'production',
        ]);
        AppInstance::factory()->create([
            'app_id' => $app->id,
            'name' => 'cloud',
            'driver' => 'laravel-cloud',
            'driver_config' => new LaravelCloudAppInstanceDriverConfigData(
                application_id: 'app_123',
                environment_id: 'env_123',
            ),
        ]);

        $resolved = new ServingNodeResolver()->resolve(
            servingNodeRequest(['instance' => 'billing.cloud']),
            ServingNode::AppInstanceOwning,
        );

        expect($resolved?->is($gateway))->toBeTrue();
    });

    it('resolves instance-owning nodes from separate project and instance route parameters', function (): void {
        $logicalNode = Node::factory()->create(['name' => 'logical-app-node']);
        $instanceNode = Node::factory()->create(['name' => 'instance-node']);
        $app = Project::factory()->for($logicalNode, 'node')->create(['name' => 'billing']);
        AppInstance::factory()->for($app)->create([
            'name' => 'development',
            'driver_config' => new OrbitAppInstanceDriverConfigData(
                node_id: $instanceNode->id,
                path: '/srv/billing-development',
                document_root: 'public',
                domain: 'billing-development.test',
            ),
        ]);

        $resolved = new ServingNodeResolver()->resolve(
            servingNodeRequest([
                'project' => 'billing',
                'instance' => 'development',
            ]),
            ServingNode::AppInstanceOwning,
        );

        expect($resolved?->is($instanceNode))->toBeTrue();
    });

    it('resolves project-owning nodes from process identity', function (): void {
        $node = Node::factory()->create(['name' => 'process-node']);
        $app = Project::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
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
        $app = Project::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        $otherApp = Project::factory()->create(['name' => 'other', 'node_id' => $otherNode->id]);

        $instance = AppInstance::factory()->for($app)->create([
            'name' => 'development',
            'driver_config' => new OrbitAppInstanceDriverConfigData(
                node_id: $node->id,
                path: '/srv/docs-development',
                document_root: 'public',
                domain: 'docs.test',
            ),
        ]);
        $otherInstance = AppInstance::factory()->for($otherApp)->create([
            'name' => 'development',
            'driver_config' => new OrbitAppInstanceDriverConfigData(
                node_id: $otherNode->id,
                path: '/srv/other-development',
                document_root: 'public',
                domain: 'other.test',
            ),
        ]);

        Workspace::factory()->create([
            'app_id' => $app->id,
            'app_instance_id' => $instance->id,
            'name' => 'feature',
        ]);
        Workspace::factory()->create([
            'app_id' => $otherApp->id,
            'app_instance_id' => $otherInstance->id,
            'name' => 'feature',
        ]);

        $resolved = new ServingNodeResolver()->resolve(
            servingNodeRequest(['workspace' => 'feature'], ['instance' => 'docs.development']),
            ServingNode::WorkspaceOwning,
        );

        expect($resolved?->is($node))->toBeTrue();
    });

    it('does not resolve the removed app request alias', function (): void {
        $node = Node::factory()->create(['name' => 'app-node']);
        Project::factory()->create(['name' => 'docs', 'node_id' => $node->id]);

        $resolved = new ServingNodeResolver()->resolve(
            servingNodeRequest(['app' => 'docs']),
            ServingNode::AppOwning,
        );

        expect($resolved)->toBeNull();
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
