<?php

declare(strict_types=1);

use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Enums\Apps\AppInstanceDriver;
use App\Enums\Apps\AppRuntimeKind;
use App\Models\App;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

const APP_INSTANCE_CALLER_WG_IP = '10.6.0.98';

/**
 * @param  array<string, mixed>  $overrides
 */
function create_app_instance_caller(array $overrides = []): Node
{
    $attributes = array_replace([
        'name' => 'caller',
        'host' => APP_INSTANCE_CALLER_WG_IP,
        'wireguard_address' => APP_INSTANCE_CALLER_WG_IP,
    ], $overrides);

    /** @var Node $caller */
    $caller = Node::factory()->create($attributes);

    return $caller;
}

/**
 * @param  list<string>  $permissions
 */
function grant_app_instance_access(Node $caller, Node $appNode, array $permissions = ['app:read']): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
        'permissions' => json_encode($permissions, JSON_THROW_ON_ERROR),
        'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function get_app_instance_json(string $uri): TestResponse
{
    /** @var TestResponse $response */
    $response = test()->call(
        'GET',
        $uri,
        [],
        [],
        [],
        [
            'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => APP_INSTANCE_CALLER_WG_IP,
        ],
    );

    return $response;
}

describe('AppInstanceController', function (): void {
    it('reports configured mounts from each app instance', function (): void {
        $caller = create_app_instance_caller();
        $appNode = createTestAppHostNode(['name' => 'NMBP', 'platform' => 'macos_14', 'user' => 'nckrtl']);
        grant_app_instance_access($caller, $appNode);

        /** @var App $app */
        $app = App::factory()->for($appNode, 'node')->create([
            'name' => 'hauser',
            'path' => '/Users/nckrtl/apps/hauser',
            'document_root' => 'public',
            'runtime' => AppRuntimeKind::Php,
        ]);
        $nmbp = $app->instances()->create([
            'name' => 'nmbp',
            'driver' => AppInstanceDriver::Orbit,
            'driver_config' => new OrbitAppInstanceDriverConfigData(
                node_id: $appNode->id,
                node: 'NMBP',
                path: '/Users/nckrtl/apps/hauser',
                document_root: 'public',
                domain: 'hauser.nmbp',
            ),
        ]);
        $development = $app->instances()->create([
            'name' => 'development',
            'driver' => AppInstanceDriver::Orbit,
            'driver_config' => new OrbitAppInstanceDriverConfigData(
                node_id: $appNode->id,
                node: 'NMBP',
                path: '/Users/nckrtl/apps/hauser',
                document_root: 'public',
                domain: 'hauser.test',
            ),
        ]);

        $nmbp->runtimeMounts()->create([
            'source' => '/Users/nckrtl/projects',
            'target' => '/projects',
            'read_only' => true,
        ]);

        $list = get_app_instance_json('/api/apps/hauser/instances');

        $list->assertOk();

        /** @var list<array<string, mixed>> $payload */
        $payload = $list->json('success.data.instances');

        $instances = collect($payload)->keyBy('name')->all();

        expect(data_get($instances, 'nmbp.runtime.configured_mounts.0.source'))
            ->toBe('/Users/nckrtl/projects')
            ->and(data_get($instances, 'nmbp.runtime.configured_mounts.0.target'))
            ->toBe('/projects')
            ->and(data_get($instances, 'nmbp.runtime.configured_mounts.0.read_only'))
            ->toBeTrue()
            ->and(data_get($instances, 'development.runtime.configured_mounts'))
            ->toBe([]);

        get_app_instance_json('/api/apps/hauser/instances/nmbp')
            ->assertOk()
            ->assertJsonPath('success.data.instance.runtime.configured_mounts.0.source', '/Users/nckrtl/projects')
            ->assertJsonPath('success.data.instance.runtime.configured_mounts.0.target', '/projects');

        expect($development->runtimeMounts()->count())->toBe(0);
    });

    it('filters list output by each instance serving node', function (): void {
        $caller = create_app_instance_caller();
        $legacyNode = createTestAppHostNode(['name' => 'legacy']);
        $visibleNode = createTestAppHostNode(['name' => 'visible']);
        $hiddenNode = createTestAppHostNode(['name' => 'hidden']);
        grant_app_instance_access($caller, $visibleNode);
        $app = App::factory()->for($legacyNode, 'node')->create(['name' => 'docs']);

        foreach (['visible' => $visibleNode, 'hidden' => $hiddenNode] as $name => $node) {
            AppInstance::factory()->for($app)->create([
                'name' => $name,
                'driver_config' => new OrbitAppInstanceDriverConfigData(
                    node_id: $node->id,
                    node: $node->name,
                    path: "/srv/docs-{$name}",
                    document_root: 'public',
                ),
            ]);
        }

        get_app_instance_json('/api/apps/docs/instances')
            ->assertOk()
            ->assertJsonCount(1, 'success.data.instances')
            ->assertJsonPath('success.data.instances.0.name', 'visible')
            ->assertJsonMissing(['name' => 'hidden']);
    });

    it('authorizes show against the selected instance serving node', function (): void {
        $caller = create_app_instance_caller();
        $legacyNode = createTestAppHostNode(['name' => 'legacy']);
        $servingNode = createTestAppHostNode(['name' => 'serving']);
        grant_app_instance_access($caller, $servingNode);
        $app = App::factory()->for($legacyNode, 'node')->create(['name' => 'docs']);

        AppInstance::factory()->for($app)->create([
            'name' => 'production',
            'driver_config' => new OrbitAppInstanceDriverConfigData(
                node_id: $servingNode->id,
                node: $servingNode->name,
                path: '/srv/docs',
                document_root: 'public',
            ),
        ]);

        get_app_instance_json('/api/apps/docs/instances/production')
            ->assertOk()
            ->assertJsonPath('success.data.instance.name', 'production');
    });

    it('authorizes add against the explicit target node', function (): void {
        $caller = create_app_instance_caller();
        $legacyNode = createTestAppHostNode(['name' => 'legacy']);
        $targetNode = createTestAppHostNode(['name' => 'target']);
        grant_app_instance_access($caller, $targetNode, ['app:write']);
        App::factory()->for($legacyNode, 'node')->create(['name' => 'docs']);

        $this
            ->call(
                'POST',
                '/api/apps/docs/instances',
                [
                    'name' => 'production',
                    'driver' => 'orbit',
                    'node' => 'target',
                    'path' => '/srv/docs',
                    'root' => 'public',
                ],
                [],
                [],
                [
                    'HTTP_ACCEPT' => 'application/json',
                    'REMOTE_ADDR' => APP_INSTANCE_CALLER_WG_IP,
                ],
            )
            ->assertOk()
            ->assertJsonPath('success.data.instance.name', 'production');
    });

    it('keeps external instances gateway-only', function (): void {
        $caller = create_app_instance_caller();
        $legacyNode = createTestAppHostNode(['name' => 'legacy']);
        grant_app_instance_access($caller, $legacyNode, ['app:read', 'app:write']);
        $app = App::factory()->for($legacyNode, 'node')->create(['name' => 'docs']);
        AppInstance::factory()->for($app)->create([
            'name' => 'cloud',
            'driver' => AppInstanceDriver::LaravelCloud,
        ]);

        get_app_instance_json('/api/apps/docs/instances/cloud')
            ->assertForbidden()
            ->assertJsonPath('error.meta.reason', 'gateway_only_external_instance');

        NodeRoleAssignment::factory()->create([
            'node_id' => $caller->id,
            'role' => 'gateway',
            'status' => 'active',
        ]);

        get_app_instance_json('/api/apps/docs/instances/cloud')
            ->assertOk()
            ->assertJsonPath('success.data.instance.name', 'cloud');
    });

    it('stops when an instance hostname matches more than one app', function (): void {
        $caller = create_app_instance_caller();
        $firstNode = createTestAppHostNode(['name' => 'first']);
        $secondNode = createTestAppHostNode(['name' => 'second']);
        grant_app_instance_access($caller, $firstNode, ['app:read', 'app:write']);
        grant_app_instance_access($caller, $secondNode, ['app:read', 'app:write']);

        foreach (['first-app' => $firstNode, 'second-app' => $secondNode] as $appName => $node) {
            $app = App::factory()->for($node, 'node')->create(['name' => $appName]);
            AppInstance::factory()->for($app)->create([
                'name' => 'production',
                'driver_config' => new OrbitAppInstanceDriverConfigData(
                    node_id: $node->id,
                    node: $node->name,
                    path: "/srv/{$appName}",
                    document_root: 'public',
                    domain: 'shared.test',
                ),
            ]);
        }

        get_app_instance_json('/api/apps/shared.test/instances/production')
            ->assertUnprocessable()
            ->assertJsonPath('error.meta.reason', 'ambiguous_app_selector');

        $this
            ->call(
                'POST',
                '/api/apps/shared.test/instances',
                ['name' => 'staging'],
                [],
                [],
                [
                    'HTTP_ACCEPT' => 'application/json',
                    'REMOTE_ADDR' => APP_INSTANCE_CALLER_WG_IP,
                ],
            )
            ->assertUnprocessable()
            ->assertJsonPath('error.meta.reason', 'ambiguous_app_selector');

        $this
            ->call(
                'DELETE',
                '/api/apps/shared.test/instances/production',
                ['force' => true],
                [],
                [],
                [
                    'HTTP_ACCEPT' => 'application/json',
                    'REMOTE_ADDR' => APP_INSTANCE_CALLER_WG_IP,
                ],
            )
            ->assertUnprocessable()
            ->assertJsonPath('error.meta.reason', 'ambiguous_app_selector');

        expect(AppInstance::query()->count())->toBe(2);
    });
});
