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

function grant_app_instance_read_access(Node $caller, Node $appNode): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
        'permissions' => json_encode(['app:read'], JSON_THROW_ON_ERROR),
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
        grant_app_instance_read_access($caller, $appNode);

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
});
