<?php

declare(strict_types=1);

use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Enums\Apps\AppInstanceDriver;
use App\Enums\Apps\AppRuntimeKind;
use App\Models\App;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

const APP_RUNTIME_MOUNT_CALLER_WG_IP = '10.6.0.97';

function createAppRuntimeMountCaller(array $overrides = []): Node
{
    return Node::factory()->create(array_merge([
        'name' => 'caller',
        'host' => APP_RUNTIME_MOUNT_CALLER_WG_IP,
        'wireguard_address' => APP_RUNTIME_MOUNT_CALLER_WG_IP,
    ], $overrides));
}

/**
 * @param  list<string>  $permissions
 */
function grantAppRuntimeMountAccess(Node $caller, Node $appNode, array $permissions = ['app:mount']): void
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

/**
 * @param  array<string, mixed>  $data
 */
function postAppRuntimeMountJson(string $uri, array $data): TestResponse
{
    return test()->call(
        'POST',
        $uri,
        $data,
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => APP_RUNTIME_MOUNT_CALLER_WG_IP,
        ],
        json_encode($data, JSON_THROW_ON_ERROR),
    );
}

/**
 * @param  array<string, mixed>  $data
 */
function deleteAppRuntimeMountJson(string $uri, array $data): TestResponse
{
    return test()->call(
        'DELETE',
        $uri,
        $data,
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => APP_RUNTIME_MOUNT_CALLER_WG_IP,
        ],
        json_encode($data, JSON_THROW_ON_ERROR),
    );
}

describe('AppRuntimeMountController', function (): void {
    it('adds lists updates and removes app runtime mounts for app-dev PHP apps', function (): void {
        $caller = createAppRuntimeMountCaller();
        $appNode = Node::factory()->appDev()->create(['name' => 'beast', 'user' => 'nckrtl']);
        grantAppRuntimeMountAccess($caller, $appNode, ['app:read', 'app:mount']);
        App::factory()->for($appNode, 'node')->create([
            'name' => 'nckrtl',
            'path' => '/home/nckrtl/apps/nckrtl',
            'runtime' => AppRuntimeKind::Php,
        ]);

        $created = postAppRuntimeMountJson('/api/apps/nckrtl/mounts', [
            'source' => '/home/nckrtl/packages',
            'target' => '/home/nckrtl/packages',
        ]);

        $created
            ->assertOk()
            ->assertJsonPath('success.data.action', 'created')
            ->assertJsonPath('success.data.mount.source', '/home/nckrtl/packages')
            ->assertJsonPath('success.data.mount.target', '/home/nckrtl/packages')
            ->assertJsonPath('success.data.mount.read_only', true)
            ->assertJsonPath('success.data.inherited_by_workspaces', true);

        $updated = postAppRuntimeMountJson('/api/apps/nckrtl/mounts', [
            'source' => '/home/nckrtl/packages',
            'target' => '/home/nckrtl/packages',
            'read_only' => false,
        ]);

        $updated
            ->assertOk()
            ->assertJsonPath('success.data.action', 'updated')
            ->assertJsonPath('success.data.mount.read_only', false);

        $list = $this->call(
            'GET',
            '/api/apps/nckrtl/mounts',
            [],
            [],
            [],
            [
                'HTTP_ACCEPT' => 'application/json',
                'REMOTE_ADDR' => APP_RUNTIME_MOUNT_CALLER_WG_IP,
            ],
        );

        $list
            ->assertOk()
            ->assertJsonPath('success.data.app.name', 'nckrtl')
            ->assertJsonPath('success.data.mounts.0.source', '/home/nckrtl/packages')
            ->assertJsonPath('success.data.mounts.0.target', '/home/nckrtl/packages')
            ->assertJsonPath('success.data.mounts.0.read_only', false);

        $removed = deleteAppRuntimeMountJson('/api/apps/nckrtl/mounts', [
            'target' => '/home/nckrtl/packages',
        ]);

        $removed
            ->assertOk()
            ->assertJsonPath('success.data.action', 'removed')
            ->assertJsonPath('success.data.mounts', []);
    });

    it('rejects mount mutations without app mount permission', function (): void {
        $caller = createAppRuntimeMountCaller();
        $appNode = Node::factory()->appDev()->create(['name' => 'beast', 'user' => 'nckrtl']);
        grantAppRuntimeMountAccess($caller, $appNode, ['app:read']);
        App::factory()->for($appNode, 'node')->create(['name' => 'nckrtl']);

        $response = postAppRuntimeMountJson('/api/apps/nckrtl/mounts', [
            'source' => '/home/nckrtl/packages',
            'target' => '/home/nckrtl/packages',
        ]);

        $response
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.missing_permission', 'app:mount');
    });

    it('rejects unsafe runtime mount intent before persistence', function (array $payload, string $reason): void {
        $caller = createAppRuntimeMountCaller();
        $appNode = Node::factory()->appDev()->create(['name' => 'beast', 'user' => 'nckrtl']);
        grantAppRuntimeMountAccess($caller, $appNode);
        App::factory()->for($appNode, 'node')->create([
            'name' => 'nckrtl',
            'path' => '/home/nckrtl/apps/nckrtl',
            'runtime' => AppRuntimeKind::Php,
        ]);

        $response = postAppRuntimeMountJson('/api/apps/nckrtl/mounts', $payload);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.reason', $reason);

        expect(DB::table('app_runtime_mounts')->count())->toBe(0);
    })->with([
        'relative source' => [
            [
                'source' => 'packages',
                'target' => '/home/nckrtl/packages',
            ],
            'source_must_be_absolute',
        ],
        'outside node home' => [
            [
                'source' => '/srv/shared',
                'target' => '/srv/shared',
            ],
            'source_outside_app_dev_home',
        ],
        'secret source' => [
            [
                'source' => '/home/nckrtl/.ssh',
                'target' => '/home/nckrtl/.ssh',
            ],
            'source_sensitive',
        ],
        'reserved target' => [
            [
                'source' => '/home/nckrtl/packages',
                'target' => '/packages',
            ],
            'target_reserved',
        ],
        'reserved xdg root' => [
            [
                'source' => '/home/nckrtl/packages',
                'target' => '/tmp/orbit-frankenphp',
            ],
            'target_reserved',
        ],
        'reserved xdg child' => [
            [
                'source' => '/home/nckrtl/packages',
                'target' => '/tmp/orbit-frankenphp/data/cache',
            ],
            'target_reserved',
        ],
    ]);

    it('stores runtime mounts on an app instance independently from legacy app mounts', function (): void {
        $caller = createAppRuntimeMountCaller();
        $appNode = createTestAppHostNode(['name' => 'NMBP', 'platform' => 'macos_14', 'user' => 'nckrtl']);
        grantAppRuntimeMountAccess($caller, $appNode, ['app:read', 'app:mount']);

        $app = App::factory()->for($appNode, 'node')->create([
            'name' => 'hauser',
            'path' => '/Users/nckrtl/apps/hauser',
            'document_root' => 'public',
            'runtime' => AppRuntimeKind::Php,
        ]);
        $instance = $app->instances()->create([
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

        postAppRuntimeMountJson('/api/apps/hauser.nmbp/mounts', [
            'source' => '/Users/nckrtl/projects',
            'target' => '/projects',
            'read_only' => true,
        ])->assertOk();

        expect($app->runtimeMounts()->count())
            ->toBe(0)
            ->and($instance->runtimeMounts()->count())
            ->toBe(1)
            ->and($instance->runtimeMounts()->first()?->source)
            ->toBe('/Users/nckrtl/projects');
    });

    it('returns app instance target metadata and validates source against the instance node home', function (): void {
        $caller = createAppRuntimeMountCaller();
        $appNode = createTestAppHostNode(['name' => 'NMBP', 'platform' => 'macos_14', 'user' => 'nckrtl']);
        grantAppRuntimeMountAccess($caller, $appNode, ['app:read', 'app:mount']);

        $app = App::factory()->for($appNode, 'node')->create([
            'name' => 'hauser',
            'path' => '/Users/nckrtl/apps/hauser',
            'document_root' => 'public',
            'runtime' => AppRuntimeKind::Php,
        ]);
        $app->instances()->create([
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

        postAppRuntimeMountJson('/api/apps/hauser.nmbp/mounts', [
            'source' => '/Users/nckrtl/projects',
            'target' => '/projects',
        ])
            ->assertOk()
            ->assertJsonPath('success.data.target.type', 'app_instance')
            ->assertJsonPath('success.data.target.app', 'hauser')
            ->assertJsonPath('success.data.target.instance', 'nmbp')
            ->assertJsonPath('success.data.mount.source', '/Users/nckrtl/projects');

        postAppRuntimeMountJson('/api/apps/hauser.nmbp/mounts', [
            'source' => '/home/nckrtl/projects',
            'target' => '/projects',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.home', '/Users/nckrtl');
    });

    it('keeps an exact app domain as an app-level mount target before dotted instance selectors', function (): void {
        $caller = createAppRuntimeMountCaller();
        $domainAppNode = createTestAppHostNode([
            'name' => 'domain-node',
            'platform' => 'ubuntu_24-04',
            'user' => 'nckrtl',
        ]);
        $instanceNode = createTestAppHostNode(['name' => 'NMBP', 'platform' => 'macos_14', 'user' => 'nckrtl']);
        grantAppRuntimeMountAccess($caller, $domainAppNode, ['app:read', 'app:mount']);
        grantAppRuntimeMountAccess($caller, $instanceNode, ['app:read', 'app:mount']);

        $domainApp = App::factory()->for($domainAppNode, 'node')->create([
            'name' => 'domain-app',
            'domain' => 'hauser.nmbp',
            'path' => '/home/nckrtl/apps/domain-app',
            'runtime' => AppRuntimeKind::Php,
        ]);

        $hauser = App::factory()->for($instanceNode, 'node')->create([
            'name' => 'hauser',
            'path' => '/Users/nckrtl/apps/hauser',
            'runtime' => AppRuntimeKind::Php,
        ]);
        $hauser
            ->instances()
            ->create([
                'name' => 'nmbp',
                'driver' => AppInstanceDriver::Orbit,
                'driver_config' => new OrbitAppInstanceDriverConfigData(
                    node_id: $instanceNode->id,
                    node: 'NMBP',
                    path: '/Users/nckrtl/apps/hauser',
                    document_root: 'public',
                    domain: 'hauser.nmbp',
                ),
            ]);

        postAppRuntimeMountJson('/api/apps/hauser.nmbp/mounts', [
            'source' => '/home/nckrtl/projects',
            'target' => '/projects',
        ])
            ->assertOk()
            ->assertJsonPath('success.data.target.type', 'app')
            ->assertJsonPath('success.data.target.app', 'domain-app')
            ->assertJsonPath('success.data.mount.source', '/home/nckrtl/projects');

        expect($domainApp->runtimeMounts()->count())
            ->toBe(1)
            ->and($hauser->instances()->first()?->runtimeMounts()->count())
            ->toBe(0);
    });

    it('rejects configurable runtime mounts for static apps and app-prod apps in the first slice', function (
        array $nodeState,
        array $appState,
        string $reason,
    ): void {
        $caller = createAppRuntimeMountCaller();
        $appNode = Node::factory()
            ->{$nodeState['role']}()
            ->create(['name' => 'app-node', 'user' => 'orbit']);
        grantAppRuntimeMountAccess($caller, $appNode);
        App::factory()->for($appNode, 'node')->create(array_merge([
            'name' => 'docs',
            'path' => '/home/orbit/apps/docs',
        ], $appState));

        $response = postAppRuntimeMountJson('/api/apps/docs/mounts', [
            'source' => '/home/orbit/packages',
            'target' => '/home/orbit/packages',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.reason', $reason);
    })->with([
        'static app' => [['role' => 'appDev'], ['runtime' => AppRuntimeKind::Static], 'app_runtime_not_php'],
        'app-prod app' => [['role' => 'appProd'], ['environment' => 'production'], 'app_mounts_app_dev_only'],
    ]);
});
