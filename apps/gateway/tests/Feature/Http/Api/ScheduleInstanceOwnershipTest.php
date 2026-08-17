<?php

declare(strict_types=1);

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Enums\Apps\InstanceDriver;
use App\Enums\Nodes\NodeStatus;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Schedule;
use App\Models\SchedulerState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

const SCHEDULE_INSTANCE_CALLER_IP = '10.6.0.91';

it('creates same-named schedules for explicit concrete instances', function (): void {
    $caller = scheduleInstanceCaller(gateway: true);
    scheduleGatewayHeartbeat($caller);
    [$app, $development, $production] = scheduleAppWithTwoInstances();

    schedulePost([
        'name' => 'nightly',
        'instance' => 'docs.production',
        'interval' => 'daily at 02:00',
        'timezone' => 'UTC',
        'command' => 'php artisan reports:send',
    ])
        ->assertCreated()
        ->assertJsonPath('success.data.schedule.target.name', 'docs.production')
        ->assertJsonPath('success.data.schedule.target.node', 'app-prod-1');

    schedulePost([
        'name' => 'nightly',
        'instance' => 'docs.development',
        'interval' => 'daily at 02:00',
        'timezone' => 'UTC',
        'command' => 'php artisan reports:send',
    ])->assertCreated();

    expect(Schedule::query()->count())
        ->toBe(2)
        ->and(Schedule::query()->where('instance_id', $production->id)->value('schedule_key'))
        ->toBe('instance:docs.production:nightly')
        ->and(Schedule::query()->where('instance_id', $development->id)->value('schedule_key'))
        ->toBe('instance:docs.development:nightly')
        ->and($app->schedules()->count())
        ->toBe(2);
});

it('records the concrete instance selector in schedule activity', function (): void {
    $caller = scheduleInstanceCaller(gateway: true);
    scheduleGatewayHeartbeat($caller);
    scheduleAppWithTwoInstances();

    schedulePost([
        'name' => 'nightly',
        'instance' => 'docs.production',
        'interval' => 'daily at 02:00',
        'timezone' => 'UTC',
        'command' => 'php artisan reports:send',
    ])->assertCreated();

    $activity = Activity::query()->sole();

    expect($activity->properties->get('instance'))
        ->toBe('docs.production')
        ->and($activity->properties->has('app'))
        ->toBeFalse();
});

it('stores and returns a bounded schedule execution timeout', function (): void {
    $caller = scheduleInstanceCaller(gateway: true);
    scheduleGatewayHeartbeat($caller);
    scheduleAppWithTwoInstances();

    schedulePost([
        'name' => 'catalogue-sync',
        'instance' => 'docs.production',
        'interval' => 'weekly on monday at 02:30',
        'timezone' => 'Europe/Amsterdam',
        'timeout' => 7_200,
        'command' => 'php artisan food-catalog:sync --json',
    ])
        ->assertCreated()
        ->assertJsonPath('success.data.schedule.execution.timeout_seconds', 7_200);

    expect(Schedule::query()->sole()->timeout_seconds)->toBe(7_200);
});

it('rejects schedule execution timeouts outside the supported range', function (mixed $timeout): void {
    $caller = scheduleInstanceCaller(gateway: true);
    scheduleGatewayHeartbeat($caller);
    scheduleAppWithTwoInstances();

    schedulePost([
        'name' => 'catalogue-sync',
        'instance' => 'docs.production',
        'interval' => 'weekly on monday at 02:30',
        'timezone' => 'Europe/Amsterdam',
        'timeout' => $timeout,
        'command' => 'php artisan food-catalog:sync --json',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.meta.field', 'timeout');

    expect(Schedule::query()->count())->toBe(0);
})->with([
    'zero' => 0,
    'above one day' => 86_401,
    'not numeric' => 'slow',
    'decimal' => 1.5,
    'boolean' => true,
    'mixed numeric string' => '7200oops',
]);

it('accepts a bare project selector when exactly one eligible instance is visible', function (): void {
    $caller = scheduleInstanceCaller();
    $gateway = scheduleGatewayNode();
    scheduleGatewayHeartbeat($gateway);
    [, $development] = scheduleAppWithTwoInstances();
    $developmentNode = Node::query()->where('name', 'app-dev-1')->firstOrFail();

    grantSchedulePermission($caller, $developmentNode, 'schedule:add');

    schedulePost([
        'name' => 'nightly',
        'instance' => 'docs',
        'interval' => 'daily at 02:00',
        'timezone' => 'UTC',
        'command' => 'php artisan reports:send',
    ])
        ->assertCreated()
        ->assertJsonPath('success.data.schedule.target.name', 'docs.development')
        ->assertJsonPath('success.data.schedule.target.node', 'app-dev-1');

    expect(Schedule::query()->sole()->instance_id)->toBe($development->id);
});

it('does not reveal whether an unauthorized explicit instance exists', function (): void {
    $caller = scheduleInstanceCaller();
    scheduleGatewayHeartbeat(scheduleGatewayNode());
    scheduleAppWithTwoInstances();
    $developmentNode = Node::query()->where('name', 'app-dev-1')->firstOrFail();

    grantSchedulePermission($caller, $developmentNode, 'schedule:add');

    $hidden = schedulePost([
        'name' => 'nightly',
        'instance' => 'docs.production',
        'interval' => 'daily at 02:00',
        'timezone' => 'UTC',
        'command' => 'php artisan reports:send',
    ]);
    $missing = schedulePost([
        'name' => 'nightly',
        'instance' => 'docs.does-not-exist',
        'interval' => 'daily at 02:00',
        'timezone' => 'UTC',
        'command' => 'php artisan reports:send',
    ]);
    $normalize = static function (TestResponse $response): array {
        /** @var array<string, mixed> $error */
        $error = $response->json('error');
        unset($error['message']);

        if (is_array($error['meta'] ?? null)) {
            $error['meta']['instance'] = '<selector>';
        }

        return $error;
    };

    $hidden->assertUnprocessable();
    $missing->assertUnprocessable();

    expect($normalize($hidden))
        ->toBe($normalize($missing))
        ->and($hidden->json('error.code'))
        ->toBe('validation_failed')
        ->and($hidden->json('error.meta'))
        ->not
        ->toHaveKeys(['missing_permission', 'serving_node'])
        ->and(Schedule::query()->count())
        ->toBe(0);
});

it('preserves explicit unavailable instance diagnostics for privileged callers', function (bool $gatewayCaller): void {
    $caller = scheduleInstanceCaller(gateway: $gatewayCaller);
    $gateway = $gatewayCaller ? $caller : scheduleGatewayNode();
    scheduleGatewayHeartbeat($gateway);
    scheduleAppWithTwoInstances();

    if (! $gatewayCaller) {
        grantSchedulePermission($caller, $gateway, '*');
    }

    Node::query()
        ->where('name', 'app-prod-1')
        ->update(['status' => NodeStatus::Inactive]);

    schedulePost([
        'name' => 'nightly',
        'instance' => 'docs.production',
        'interval' => 'daily at 02:00',
        'timezone' => 'UTC',
        'command' => 'php artisan reports:send',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.meta.reason', 'instance_unavailable')
        ->assertJsonPath('error.meta.app', 'docs')
        ->assertJsonPath('error.meta.instance', 'production');
})->with([
    'gateway node' => true,
    'gateway-admin grant' => false,
]);

it('excludes inactive instance placements from bare selector eligibility', function (): void {
    $caller = scheduleInstanceCaller(gateway: true);
    scheduleGatewayHeartbeat($caller);
    [, $development] = scheduleAppWithTwoInstances();
    Node::query()
        ->where('name', 'app-prod-1')
        ->update(['status' => NodeStatus::Inactive]);

    schedulePost([
        'name' => 'nightly',
        'instance' => 'docs',
        'interval' => 'daily at 02:00',
        'timezone' => 'UTC',
        'command' => 'php artisan reports:send',
    ])
        ->assertCreated()
        ->assertJsonPath('success.data.schedule.target.name', 'docs.development');

    expect(Schedule::query()->sole()->instance_id)->toBe($development->id);
});

it('rejects an ambiguous bare project selector before creating schedule intent', function (): void {
    $caller = scheduleInstanceCaller(gateway: true);
    scheduleGatewayHeartbeat($caller);
    scheduleAppWithTwoInstances();

    schedulePost([
        'name' => 'nightly',
        'instance' => 'docs',
        'interval' => 'daily at 02:00',
        'timezone' => 'UTC',
        'command' => 'php artisan reports:send',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.meta.field', 'instance')
        ->assertJsonPath('error.meta.reason', 'instance_required')
        ->assertJsonPath('error.meta.instances.0', 'development')
        ->assertJsonPath('error.meta.instances.1', 'production');

    expect(Schedule::query()->count())->toBe(0);
});

it('rejects a bare project selector when no eligible instance is visible to the caller', function (): void {
    $caller = scheduleInstanceCaller();
    scheduleGatewayHeartbeat(scheduleGatewayNode());
    scheduleAppWithTwoInstances();

    schedulePost([
        'name' => 'nightly',
        'instance' => 'docs',
        'interval' => 'daily at 02:00',
        'timezone' => 'UTC',
        'command' => 'php artisan reports:send',
    ])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'authorization_failed')
        ->assertJsonPath('error.meta.missing_permission', 'schedule:add');

    expect(Schedule::query()->count())->toBe(0);
});

it('reads an explicit instance and rejects ambiguous bare project filters', function (): void {
    $caller = scheduleInstanceCaller(gateway: true);
    [, $development, $production] = scheduleAppWithTwoInstances();
    Schedule::factory()->forInstance($development)->create(['name' => 'nightly']);
    Schedule::factory()->forInstance($production)->create(['name' => 'nightly']);

    scheduleGet('/api/schedules/nightly?instance=docs.production')
        ->assertOk()
        ->assertJsonPath('success.data.schedule.target.name', 'docs.production')
        ->assertJsonPath('success.meta.instance', 'docs.production');

    scheduleGet('/api/schedules/nightly?instance=docs')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.meta.reason', 'instance_required');

    scheduleGet('/api/schedules?instance=docs')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.meta.reason', 'instance_required');
});

it('lists schedules only for instances visible to a non-gateway caller', function (): void {
    $caller = scheduleInstanceCaller();
    [, $development, $production] = scheduleAppWithTwoInstances();
    $developmentNode = Node::query()->where('name', 'app-dev-1')->firstOrFail();
    Schedule::factory()->forInstance($development)->create(['name' => 'development-nightly']);
    Schedule::factory()->forInstance($production)->create(['name' => 'production-nightly']);

    grantSchedulePermission($caller, $developmentNode, 'schedule:read');

    scheduleGet('/api/schedules')
        ->assertOk()
        ->assertJsonCount(1, 'success.data.schedules')
        ->assertJsonPath('success.data.schedules.0.name', 'development-nightly')
        ->assertJsonPath('success.data.schedules.0.target.name', 'docs.development')
        ->assertJsonMissing(['name' => 'production-nightly']);
});

function scheduleInstanceCaller(bool $gateway = false): Node
{
    $caller = Node::factory()->create([
        'name' => $gateway ? 'gateway-1' : 'operator-1',
        'host' => SCHEDULE_INSTANCE_CALLER_IP,
        'wireguard_address' => SCHEDULE_INSTANCE_CALLER_IP,
    ]);

    if ($gateway) {
        NodeRoleAssignment::factory()->create([
            'node_id' => $caller->id,
            'role' => 'gateway',
            'status' => 'active',
        ]);
    }

    return $caller;
}

function scheduleGatewayNode(): Node
{
    $gateway = Node::factory()->create(['name' => 'gateway-1']);
    NodeRoleAssignment::factory()->create([
        'node_id' => $gateway->id,
        'role' => 'gateway',
        'status' => 'active',
    ]);

    return $gateway;
}

function scheduleGatewayHeartbeat(Node $gateway): void
{
    SchedulerState::factory()->create([
        'node_id' => $gateway->id,
        'heartbeat_at' => now(),
        'registry_synced_at' => now(),
    ]);
}

/**
 * @return array{App, Instance, Instance}
 */
function scheduleAppWithTwoInstances(): array
{
    $developmentNode = scheduleAppNode('app-dev-1', 'app-dev');
    $productionNode = scheduleAppNode('app-prod-1', 'app-prod');
    $app = App::factory()->create([
        'name' => 'docs',
    ]);
    $development = scheduleInstance($app, $developmentNode, 'development', '/srv/docs-development');
    $production = scheduleInstance($app, $productionNode, 'production', '/srv/docs-production');

    return [$app, $development, $production];
}

function scheduleAppNode(string $name, string $role): Node
{
    $node = Node::factory()->create(['name' => $name]);
    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => $role,
        'status' => 'active',
        'settings' => $role === 'app-dev' ? ['tld' => 'test'] : [],
    ]);

    return $node;
}

function scheduleInstance(App $app, Node $node, string $name, string $path): Instance
{
    return Instance::factory()->for($app)->create([
        'name' => $name,
        'driver' => InstanceDriver::Orbit,
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $node->id,
            node: $node->name,
            path: $path,
            document_root: 'public',
            domain: "docs.{$name}.test",
        ),
    ]);
}

function grantSchedulePermission(Node $caller, Node $servingNode, string $permission): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $servingNode->id,
        'permissions' => json_encode([$permission], JSON_THROW_ON_ERROR),
        'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @param  array<string, mixed>  $payload
 */
function schedulePost(array $payload): TestResponse
{
    return test()->withServerVariables([
        'REMOTE_ADDR' => SCHEDULE_INSTANCE_CALLER_IP,
    ])->postJson('/api/schedules', $payload);
}

function scheduleGet(string $uri): TestResponse
{
    return test()->withServerVariables([
        'REMOTE_ADDR' => SCHEDULE_INSTANCE_CALLER_IP,
    ])->getJson($uri);
}
