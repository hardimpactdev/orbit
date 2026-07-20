<?php

declare(strict_types=1);

use App\Actions\Workspaces\RemoveWorkspace;
use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Exceptions\WorkspaceUnsupportedForProduction;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\NodeRoleAssignment;
use App\Models\Project;
use App\Models\Workspace;
use App\Models\WorkspaceRun;
use App\Services\Nodes\Roles\NodeRoleAssignmentService;
use App\Services\Nodes\Roles\NodeRoleBaselineConverger;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const WORKSPACE_PRODUCTION_BOUNDARY_CALLER_IP = '10.6.0.141';

/**
 * @return array{caller: Node, node: Node, app: Project, workspace: Workspace}
 */
function createProductionWorkspaceBoundaryCrossNodeFixture(): array
{
    $productionCaller = Node::factory()->create([
        'name' => 'app-prod-caller',
        'host' => '10.6.0.142',
        'wireguard_address' => '10.6.0.142',
        'status' => 'active',
    ]);
    NodeRoleAssignment::factory()->create([
        'node_id' => $productionCaller->id,
        'role' => 'app-prod',
        'status' => 'active',
    ]);

    $developmentNode = Node::factory()->create([
        'name' => 'app-dev-1',
        'status' => 'active',
    ]);
    NodeRoleAssignment::factory()->create([
        'node_id' => $developmentNode->id,
        'role' => 'app-dev',
        'status' => 'active',
    ]);
    NodeAccess::query()->create([
        'consumer_node_id' => $productionCaller->id,
        'serving_node_id' => $developmentNode->id,
        'permissions' => ['workspace:read'],
    ]);

    $app = Project::factory()->for($developmentNode, 'node')->create([
        'name' => 'dev-site',
        'environment' => 'development',
    ]);
    $workspace = Workspace::factory()->for($app, 'app')->create([
        'name' => 'dev-feature',
    ]);

    return [
        'caller' => $productionCaller,
        'node' => $developmentNode,
        'app' => $app,
        'workspace' => $workspace,
    ];
}

beforeEach(function (): void {
    $caller = Node::factory()->create([
        'name' => 'gateway',
        'host' => WORKSPACE_PRODUCTION_BOUNDARY_CALLER_IP,
        'wireguard_address' => WORKSPACE_PRODUCTION_BOUNDARY_CALLER_IP,
        'status' => 'active',
    ]);
    NodeRoleAssignment::factory()->create([
        'node_id' => $caller->id,
        'role' => 'gateway',
        'status' => 'active',
    ]);

    $productionNode = Node::factory()->create([
        'name' => 'app-prod-1',
        'status' => 'active',
    ]);
    NodeRoleAssignment::factory()->create([
        'node_id' => $productionNode->id,
        'role' => 'app-prod',
        'status' => 'active',
    ]);

    $app = Project::factory()->for($productionNode, 'node')->create([
        'name' => 'site',
        'environment' => 'production',
    ]);
    $workspace = Workspace::factory()->for($app, 'app')->create([
        'name' => 'feature',
        'path' => '/srv/site/.worktrees/feature',
    ]);
    WorkspaceRun::factory()->for($workspace)->create();
});

it('rejects selected production workspace HTTP surfaces with the stable boundary error', function (
    string $method,
    string $uri,
    array $parameters,
): void {
    $response = $this->call(
        $method,
        $uri,
        $parameters,
        [],
        [],
        [
            'REMOTE_ADDR' => WORKSPACE_PRODUCTION_BOUNDARY_CALLER_IP,
            'HTTP_ACCEPT' => 'application/json',
        ],
    );

    $response
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'workspace.unsupported_for_production')
        ->assertJsonPath('error.meta.node', 'app-prod-1')
        ->assertJsonPath('error.meta.role', 'app-prod');
})->with([
    'show by name' => ['GET', '/api/workspaces/feature?app=site.development', []],
    'show by path' => [
        'GET',
        '/api/workspaces/resolve-by-path?path=/srv/site/.worktrees/feature/src&app=site.development',
        [],
    ],
    'history by name' => ['GET', '/api/workspaces/feature/history?app=site.development', []],
    'history by path' => [
        'GET',
        '/api/workspaces/history/resolve-by-path?path=/srv/site/.worktrees/feature/src&app=site.development',
        [],
    ],
    'env list' => ['GET', '/api/workspaces/feature/env?app=site&instance=development', []],
    'env write' => [
        'POST',
        '/api/workspaces/feature/env',
        ['app' => 'site', 'instance' => 'development', 'key' => 'APP_ENV', 'value' => 'local'],
    ],
    'env render' => ['GET', '/api/workspaces/feature/env/render?app=site&instance=development', []],
    'env resolve by path' => [
        'GET',
        '/api/workspaces/env/resolve-by-path?path=/srv/site/.worktrees/feature/src&app=site&instance=development',
        [],
    ],
    'remove' => [
        'DELETE',
        '/api/workspaces/feature?app=site.development',
        ['destructive_consent' => true],
    ],
]);

it('rejects production workspace run logs with the stable boundary error', function (): void {
    $run = WorkspaceRun::query()->firstOrFail();

    $response = $this->call(
        'GET',
        "/api/workspaces/runs/{$run->id}/log",
        [],
        [],
        [],
        [
            'REMOTE_ADDR' => WORKSPACE_PRODUCTION_BOUNDARY_CALLER_IP,
            'HTTP_ACCEPT' => 'application/json',
        ],
    );

    $response
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'workspace.unsupported_for_production')
        ->assertJsonPath('error.meta.node', 'app-prod-1')
        ->assertJsonPath('error.meta.role', 'app-prod');
});

it('rejects production app callers before they operate development workspaces', function (): void {
    createProductionWorkspaceBoundaryCrossNodeFixture();

    $response = $this->call(
        'GET',
        '/api/workspaces/dev-feature?app=dev-site',
        [],
        [],
        [],
        [
            'REMOTE_ADDR' => '10.6.0.142',
            'HTTP_ACCEPT' => 'application/json',
        ],
    );

    $response
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'workspace.unsupported_for_production')
        ->assertJsonPath('error.meta.node', 'app-prod-caller')
        ->assertJsonPath('error.meta.role', 'app-prod');
});

it('rejects production app callers from workspace registry reads', function (): void {
    createProductionWorkspaceBoundaryCrossNodeFixture();

    $response = $this->call(
        'GET',
        '/api/workspaces',
        [],
        [],
        [],
        [
            'REMOTE_ADDR' => '10.6.0.142',
            'HTTP_ACCEPT' => 'application/json',
        ],
    );

    $response
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'workspace.unsupported_for_production')
        ->assertJsonPath('error.meta.node', 'app-prod-caller')
        ->assertJsonPath('error.meta.role', 'app-prod');
});

it('rejects production app callers from app-owned workspace policy reads', function (): void {
    createProductionWorkspaceBoundaryCrossNodeFixture();

    $response = $this->call(
        'GET',
        '/api/workspaces/steps/setup?app=dev-site',
        [],
        [],
        [],
        [
            'REMOTE_ADDR' => '10.6.0.142',
            'HTTP_ACCEPT' => 'application/json',
        ],
    );

    $response
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'workspace.unsupported_for_production')
        ->assertJsonPath('error.meta.node', 'app-prod-caller')
        ->assertJsonPath('error.meta.role', 'app-prod');
});

it('blocks app production role activation while the node still owns a workspace', function (): void {
    $node = Node::factory()->create([
        'name' => 'future-app-prod',
        'platform' => 'ubuntu_24-04',
        'status' => 'active',
    ]);
    $app = Project::factory()->for($node, 'node')->create(['name' => 'docs']);
    $instance = AppInstance::factory()->for($app)->create([
        'name' => 'development',
        'driver_config' => new OrbitAppInstanceDriverConfigData(
            node_id: $node->id,
            node: $node->name,
            path: '/srv/docs',
        ),
    ]);
    Workspace::factory()->for($app)->create([
        'name' => 'feature-docs',
        'app_instance_id' => $instance->id,
    ]);

    expect(fn () => app(NodeRoleAssignmentService::class)->addDuringCreation($node, 'app-prod', []))
        ->toThrow(InvalidArgumentException::class, 'feature-docs');

    expect($node->roleAssignments()->where('role', 'app-prod')->exists())->toBeFalse();
});

it('blocks every app production reactivation path while the node still owns a workspace', function (
    string $operation,
): void {
    $node = Node::factory()->create([
        'name' => "future-app-prod-{$operation}",
        'platform' => 'ubuntu_24-04',
        'status' => 'active',
    ]);
    NodeRoleAssignment::factory()->for($node)->create([
        'role' => 'ingress',
        'status' => 'active',
    ]);
    $assignment = NodeRoleAssignment::factory()->for($node)->create([
        'role' => 'app-prod',
        'status' => 'error',
        'settings' => ['ingress_node_id' => $node->id],
        'last_error' => 'baseline failed',
    ]);
    $app = Project::factory()->for($node, 'node')->create(['name' => "docs-{$operation}"]);
    $instance = AppInstance::factory()->for($app)->create([
        'name' => 'development',
        'driver_config' => new OrbitAppInstanceDriverConfigData(
            node_id: $node->id,
            node: $node->name,
            path: "/srv/docs-{$operation}",
        ),
    ]);
    Workspace::factory()->for($app)->create([
        'name' => "feature-docs-{$operation}",
        'app_instance_id' => $instance->id,
    ]);

    app()->instance(NodeRoleBaselineConverger::class, new class extends NodeRoleBaselineConverger {
        public function __construct() {}

        public function converge(Node $node, NodeRoleAssignment $assignment): void {}
    });

    expect(fn () => app(NodeRoleAssignmentService::class)->{$operation}(
        $node,
        'app-prod',
        ['ingress_node_id' => $node->id],
    ))
        ->toThrow(InvalidArgumentException::class, "feature-docs-{$operation}");

    expect($assignment->fresh())
        ->status->value->toBe('error')
        ->last_error->toBe('baseline failed');
})->with([
    'update' => ['update'],
    'creation retry' => ['retryDuringCreation'],
]);

it('rejects unresolved workspace placement before removal mutates registry state', function (): void {
    $developmentNode = Node::factory()->appDev()->create(['name' => 'app-dev-unresolved']);
    $app = Project::factory()->for($developmentNode, 'node')->create(['name' => 'unresolved-docs']);
    $instance = AppInstance::factory()->for($app)->create([
        'name' => 'development',
        'driver_config' => new OrbitAppInstanceDriverConfigData(
            node_id: 999_999,
            node: 'missing-app-dev-node',
            path: '/srv/unresolved-docs',
        ),
    ]);
    $workspace = Workspace::factory()->for($app)->create([
        'name' => 'unresolved-feature',
        'app_instance_id' => $instance->id,
    ]);

    expect(fn () => app(RemoveWorkspace::class)->handle($workspace))
        ->toThrow(WorkspaceUnsupportedForProduction::class);

    expect($workspace->fresh())->not->toBeNull();
});
