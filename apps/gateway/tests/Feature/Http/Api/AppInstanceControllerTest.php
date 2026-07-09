<?php

declare(strict_types=1);

use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Enums\Apps\AppInstanceDriver;
use App\Enums\Apps\AppRuntimeKind;
use App\Models\App;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const APP_INSTANCE_CALLER_WG_IP = '10.6.0.98';

function createAppInstanceCaller(array $overrides = []): Node
{
    return Node::factory()->create(array_merge([
        'name' => 'caller',
        'host' => APP_INSTANCE_CALLER_WG_IP,
        'wireguard_address' => APP_INSTANCE_CALLER_WG_IP,
    ], $overrides));
}

function grantAppInstanceReadAccess(Node $caller, Node $appNode): void
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

describe('AppInstanceController', function (): void {
    it('reports configured mounts from each app instance instead of legacy app-level mounts', function (): void {
        $caller = createAppInstanceCaller();
        $appNode = createTestAppHostNode(['name' => 'NMBP', 'platform' => 'macos_14', 'user' => 'nckrtl']);
        grantAppInstanceReadAccess($caller, $appNode);

        $app = App::factory()->for($appNode, 'node')->create([
            'name' => 'hauser',
            'path' => '/Users/nckrtl/apps/hauser',
            'document_root' => 'public',
            'runtime' => AppRuntimeKind::Php,
        ]);
        $app->runtimeMounts()->create([
            'source' => '/home/nckrtl/projects',
            'target' => '/projects',
            'read_only' => true,
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

        $list = $this->call(
            'GET',
            '/api/apps/hauser/instances',
            [],
            [],
            [],
            [
                'HTTP_ACCEPT' => 'application/json',
                'REMOTE_ADDR' => APP_INSTANCE_CALLER_WG_IP,
            ],
        );

        $list->assertOk();

        $instances = collect($list->json('success.data.instances'))->keyBy('name');

        expect(data_get($instances, 'nmbp.runtime.configured_mounts.0.source'))
            ->toBe('/Users/nckrtl/projects')
            ->and(data_get($instances, 'nmbp.runtime.configured_mounts.0.target'))
            ->toBe('/projects')
            ->and(data_get($instances, 'nmbp.runtime.configured_mounts.0.read_only'))
            ->toBeTrue()
            ->and(data_get($instances, 'development.runtime.configured_mounts'))
            ->toBe([]);

        $this
            ->call(
                'GET',
                '/api/apps/hauser/instances/nmbp',
                [],
                [],
                [],
                [
                    'HTTP_ACCEPT' => 'application/json',
                    'REMOTE_ADDR' => APP_INSTANCE_CALLER_WG_IP,
                ],
            )
            ->assertOk()
            ->assertJsonPath('success.data.instance.runtime.configured_mounts.0.source', '/Users/nckrtl/projects')
            ->assertJsonPath('success.data.instance.runtime.configured_mounts.0.target', '/projects');

        expect($development->runtimeMounts()->count())->toBe(0);
    });
});
