<?php

declare(strict_types=1);

use App\Data\Apps\LaravelCloudInstanceDriverConfigData;
use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Enums\Apps\InstanceDriver;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('stores app instances as concrete runtime targets for a logical app', function (): void {
    $node = Node::factory()->appDev()->create(['name' => 'app-dev-1']);
    $app = App::factory()->for($node, 'node')->create(['name' => 'billing']);

    $instance = Instance::factory()->for($app)->create([
        'name' => 'development',
        'driver' => InstanceDriver::Orbit,
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $node->id,
            node: 'app-dev-1',
            path: '/home/orbit/apps/billing',
            document_root: 'public',
            domain: null,
        ),
    ]);

    expect($app->instances()->pluck('name')->all())
        ->toBe(['development'])
        ->and($instance->app->is($app))
        ->toBeTrue()
        ->and($instance->driver)
        ->toBe(InstanceDriver::Orbit)
        ->and($instance->driver_config)
        ->toBeInstanceOf(OrbitInstanceDriverConfigData::class)
        ->and($instance->driver_config->node)
        ->toBe('app-dev-1');
});

it('keeps instance names unique per app only', function (): void {
    $first = App::factory()->create(['name' => 'billing']);
    $second = App::factory()->create(['name' => 'crm']);

    Instance::factory()->for($first)->create(['name' => 'production']);
    Instance::factory()->for($second)->create(['name' => 'production']);

    expect(fn () => Instance::factory()->for($first)->create(['name' => 'production']))
        ->toThrow(QueryException::class);
});

it('hydrates driver_config through Laravel Data concrete classes', function (): void {
    $app = App::factory()->create(['name' => 'billing']);

    $instance = Instance::factory()
        ->for($app)
        ->create([
            'name' => 'production-cloud',
            'driver' => InstanceDriver::LaravelCloud,
            'driver_config' => new LaravelCloudInstanceDriverConfigData(
                organization_id: 'org_123',
                organization_name: 'Platform 11',
                application_id: 'app_123',
                application_name: 'billing',
                environment_id: 'env_123',
                environment_name: 'production',
                domain: 'platform11.nl',
            ),
        ])
        ->fresh();

    expect($instance)
        ->toBeInstanceOf(Instance::class)
        ->and($instance->driver_config)
        ->toBeInstanceOf(LaravelCloudInstanceDriverConfigData::class)
        ->and($instance->driver_config->environment_name)
        ->toBe('production');

    $stored = json_decode(
        (string) DB::table('instances')->where('id', $instance->id)->value('driver_config'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($stored['type'])
        ->toBe('laravel_cloud_instance_driver_config')
        ->and($stored['data']['application_id'])
        ->toBe('app_123');
});
