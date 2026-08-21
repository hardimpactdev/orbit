<?php

declare(strict_types=1);

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Enums\Apps\AppRuntimeKind;
use App\Enums\Apps\InstanceDriver;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;

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
function grantAppRuntimeMountAccess(Node $caller, Node $appNode, array $permissions = ['instance:mount']): void
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

function create_app_runtime_mount_instance(
    App $app,
    Node $node,
    string $name = 'development',
    ?string $path = null,
    string $documentRoot = 'public',
): void {
    $app->instances()->create([
        'name' => $name,
        'driver' => InstanceDriver::Orbit,
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $node->id,
            node: $node->name,
            path: $path ?? '/home/orbit/apps/'.$app->name,
            document_root: $documentRoot,
            domain: "{$app->name}.{$name}",
        ),
    ]);
}

describe('AppRuntimeMountController', function (): void {
    it('rejects app-level runtime mount reads and mutations', function (): void {
        $caller = createAppRuntimeMountCaller();
        $appNode = Node::factory()->appDev()->create(['name' => 'beast', 'user' => 'nckrtl']);
        grantAppRuntimeMountAccess($caller, $appNode, ['instance:read', 'instance:mount']);
        App::factory()->create([
            'name' => 'nckrtl',
            'runtime' => AppRuntimeKind::Php,
        ]);
        $list = $this->call(
            'GET',
            '/api/instances/nckrtl/mounts',
            [],
            [],
            [],
            [
                'HTTP_ACCEPT' => 'application/json',
                'REMOTE_ADDR' => APP_RUNTIME_MOUNT_CALLER_WG_IP,
            ],
        );

        $list
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.reason', 'instance_required');

        postAppRuntimeMountJson('/api/instances/nckrtl/mounts', [
            'source' => '/home/nckrtl/packages',
            'target' => '/home/nckrtl/packages',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.reason', 'instance_required');

        deleteAppRuntimeMountJson('/api/instances/nckrtl/mounts', [
            'target' => '/home/nckrtl/packages',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.reason', 'instance_required');

        expect(Schema::hasTable('app_runtime_mounts'))->toBeFalse();
    });

    it('rejects mount mutations without app mount permission', function (): void {
        $caller = createAppRuntimeMountCaller();
        $appNode = Node::factory()->appDev()->create(['name' => 'beast', 'user' => 'nckrtl']);
        grantAppRuntimeMountAccess($caller, $appNode, ['instance:read']);
        $app = App::factory()->create(['name' => 'nckrtl']);
        create_app_runtime_mount_instance($app, $appNode);

        $response = postAppRuntimeMountJson('/api/instances/nckrtl/mounts', [
            'source' => '/home/nckrtl/packages',
            'target' => '/home/nckrtl/packages',
        ]);

        $response
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.missing_permission', 'instance:mount');
    });

    it('rejects unsafe runtime mount intent before persistence', function (array $payload, string $reason): void {
        $caller = createAppRuntimeMountCaller();
        $appNode = Node::factory()->appDev()->create(['name' => 'beast', 'user' => 'nckrtl']);
        grantAppRuntimeMountAccess($caller, $appNode);
        $app = App::factory()->create([
            'name' => 'nckrtl',
            'runtime' => AppRuntimeKind::Php,
        ]);
        create_app_runtime_mount_instance($app, $appNode, 'development', '/home/nckrtl/apps/nckrtl');

        $response = postAppRuntimeMountJson('/api/instances/nckrtl.development/mounts', $payload);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.reason', $reason);

        expect(DB::table('instance_runtime_mounts')->count())->toBe(0);
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

    it('stores runtime mounts on an app instance', function (): void {
        $caller = createAppRuntimeMountCaller();
        $appNode = createTestAppHostNode(['name' => 'NMBP', 'platform' => 'macos_14', 'user' => 'nckrtl']);
        grantAppRuntimeMountAccess($caller, $appNode, ['instance:read', 'instance:mount']);

        $app = App::factory()->create([
            'name' => 'hauser',
            'runtime' => AppRuntimeKind::Php,
        ]);
        $instance = $app->instances()->create([
            'name' => 'nmbp',
            'driver' => InstanceDriver::Orbit,
            'driver_config' => new OrbitInstanceDriverConfigData(
                node_id: $appNode->id,
                node: 'NMBP',
                path: '/Users/nckrtl/apps/hauser',
                document_root: 'public',
                domain: 'hauser.nmbp',
            ),
        ]);

        postAppRuntimeMountJson('/api/instances/hauser.nmbp/mounts', [
            'source' => '/Users/nckrtl/apps',
            'target' => '/apps',
            'read_only' => true,
        ])->assertOk();

        $entry = Activity::query()->latest('id')->firstOrFail();

        expect($instance->runtimeMounts()->count())
            ->toBe(1)
            ->and($instance->runtimeMounts()->first()?->source)
            ->toBe('/Users/nckrtl/apps')
            ->and($entry->subject_type)
            ->toBe(Instance::class)
            ->and($entry->subject_id)
            ->toBe($instance->id)
            ->and($entry->properties->get('target'))
            ->toBe('/apps');
    });

    it('returns app instance target metadata and validates source against the instance node home', function (): void {
        $caller = createAppRuntimeMountCaller();
        $appNode = createTestAppHostNode(['name' => 'NMBP', 'platform' => 'macos_14', 'user' => 'nckrtl']);
        grantAppRuntimeMountAccess($caller, $appNode, ['instance:read', 'instance:mount']);

        $app = App::factory()->create([
            'name' => 'hauser',
            'runtime' => AppRuntimeKind::Php,
        ]);
        $app->instances()->create([
            'name' => 'nmbp',
            'driver' => InstanceDriver::Orbit,
            'driver_config' => new OrbitInstanceDriverConfigData(
                node_id: $appNode->id,
                node: 'NMBP',
                path: '/Users/nckrtl/apps/hauser',
                document_root: 'public',
                domain: 'hauser.nmbp',
            ),
        ]);

        postAppRuntimeMountJson('/api/instances/hauser.nmbp/mounts', [
            'source' => '/Users/nckrtl/apps',
            'target' => '/apps',
        ])
            ->assertOk()
            ->assertJsonPath('success.data.target.type', 'instance')
            ->assertJsonPath('success.data.target.app', 'hauser')
            ->assertJsonPath('success.data.target.instance', 'nmbp')
            ->assertJsonPath('success.data.mount.source', '/Users/nckrtl/apps');

        postAppRuntimeMountJson('/api/instances/hauser.nmbp/mounts', [
            'source' => '/home/nckrtl/apps',
            'target' => '/apps',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.home', '/Users/nckrtl');
    });

    it('does not reinterpret an exact app domain as an instance selector', function (): void {
        $caller = createAppRuntimeMountCaller();
        $domainAppNode = createTestAppHostNode([
            'name' => 'domain-node',
            'platform' => 'ubuntu_24-04',
            'user' => 'nckrtl',
        ]);
        $instanceNode = createTestAppHostNode(['name' => 'NMBP', 'platform' => 'macos_14', 'user' => 'nckrtl']);
        grantAppRuntimeMountAccess($caller, $domainAppNode, ['instance:read', 'instance:mount']);
        grantAppRuntimeMountAccess($caller, $instanceNode, ['instance:read', 'instance:mount']);

        $domainApp = App::factory()->create([
            'name' => 'domain-app',
            'runtime' => AppRuntimeKind::Php,
        ]);
        $domainApp
            ->instances()
            ->create([
                'name' => 'development',
                'driver' => InstanceDriver::Orbit,
                'driver_config' => new OrbitInstanceDriverConfigData(
                    node_id: $domainAppNode->id,
                    node: $domainAppNode->name,
                    path: '/home/nckrtl/apps/domain-app',
                    document_root: 'public',
                    domain: 'hauser.nmbp',
                ),
            ]);

        $hauser = App::factory()->create([
            'name' => 'hauser',
            'runtime' => AppRuntimeKind::Php,
        ]);
        $hauser
            ->instances()
            ->create([
                'name' => 'nmbp',
                'driver' => InstanceDriver::Orbit,
                'driver_config' => new OrbitInstanceDriverConfigData(
                    node_id: $instanceNode->id,
                    node: 'NMBP',
                    path: '/Users/nckrtl/apps/hauser',
                    document_root: 'public',
                    domain: 'hauser.nmbp',
                ),
            ]);

        // The exact "hauser.nmbp" app.instance selector takes precedence over
        // domain-app whose instance domain also equals "hauser.nmbp": it must
        // resolve to hauser's nmbp instance (NMBP, home /Users/nckrtl), which is
        // why the /home/nckrtl source is rejected as outside the app-dev home
        // rather than mounted onto domain-app.
        postAppRuntimeMountJson('/api/instances/hauser.nmbp/mounts', [
            'source' => '/home/nckrtl/apps',
            'target' => '/apps',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.reason', 'source_outside_app_dev_home');

        expect($hauser->instances()->first()?->runtimeMounts()->count())
            ->toBe(0)
            ->and($domainApp->instances()->first()?->runtimeMounts()->count())
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
        $app = App::factory()->create(array_merge([
            'name' => 'docs',
        ], $appState));
        create_app_runtime_mount_instance($app, $appNode, 'development', '/home/orbit/apps/docs');

        $response = postAppRuntimeMountJson('/api/instances/docs.development/mounts', [
            'source' => '/home/orbit/packages',
            'target' => '/home/orbit/packages',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.reason', $reason);
    })->with([
        'static app' => [['role' => 'appDev'], ['runtime' => AppRuntimeKind::Static], 'instance_runtime_not_php'],
        'app-prod app' => [['role' => 'appProd'], [], 'instance_mounts_app_dev_only'],
    ]);
});
