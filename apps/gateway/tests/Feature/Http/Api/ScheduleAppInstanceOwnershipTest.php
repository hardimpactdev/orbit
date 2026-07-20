<?php

declare(strict_types=1);

use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Enums\Apps\AppInstanceDriver;
use App\Enums\Nodes\NodeStatus;
use App\Models\App;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Schedule;
use App\Models\SchedulerState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

const SCHEDULE_INSTANCE_CALLER_IP = '10.6.0.91';

it('creates same-named schedules for explicit concrete app instances', function (): void {
    $caller = scheduleInstanceCaller(gateway: true);
    scheduleGatewayHeartbeat($caller);
    [$app, $development, $production] = scheduleAppWithTwoInstances();

    schedulePost([
        'name' => 'nightly',
        'app' => 'docs.production',
        'interval' => 'daily at 02:00',
        'timezone' => 'UTC',
        'command' => 'php artisan reports:send',
    ])
        ->assertCreated()
        ->assertJsonPath('success.data.schedule.target.name', 'docs.production')
        ->assertJsonPath('success.data.schedule.target.node', 'app-prod-1');

    schedulePost([
        'name' => 'nightly',
        'app' => 'docs.development',
        'interval' => 'daily at 02:00',
        'timezone' => 'UTC',
        'command' => 'php artisan reports:send',
    ])->assertCreated();

    expect(Schedule::query()->count())
        ->toBe(2)
        ->and(Schedule::query()->where('app_instance_id', $production->id)->value('schedule_key'))
        ->toBe('app:docs.production:nightly')
        ->and(Schedule::query()->where('app_instance_id', $development->id)->value('schedule_key'))
        ->toBe('app:docs.development:nightly')
        ->and(Schedule::query()->where('app_id', $app->id)->count())
        ->toBe(2);
});

it('stores and returns a bounded schedule execution timeout', function (): void {
    $caller = scheduleInstanceCaller(gateway: true);
    scheduleGatewayHeartbeat($caller);
    scheduleAppWithTwoInstances();

    schedulePost([
        'name' => 'catalogue-sync',
        'app' => 'docs.production',
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
        'app' => 'docs.production',
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
]);

it('accepts a bare app selector when exactly one eligible instance is visible', function (): void {
    $caller = scheduleInstanceCaller();
    $gateway = scheduleGatewayNode();
    scheduleGatewayHeartbeat($gateway);
    [, $development] = scheduleAppWithTwoInstances();
    $developmentNode = Node::query()->where('name', 'app-dev-1')->firstOrFail();

    grantSchedulePermission($caller, $developmentNode, 'schedule:add');

    schedulePost([
        'name' => 'nightly',
        'app' => 'docs',
        'interval' => 'daily at 02:00',
        'timezone' => 'UTC',
        'command' => 'php artisan reports:send',
    ])
        ->assertCreated()
        ->assertJsonPath('success.data.schedule.target.name', 'docs.development')
        ->assertJsonPath('success.data.schedule.target.node', 'app-dev-1');

    expect(Schedule::query()->sole()->app_instance_id)->toBe($development->id);
});

it('does not reveal whether an unauthorized explicit app instance exists', function (): void {
    $caller = scheduleInstanceCaller();
    scheduleGatewayHeartbeat(scheduleGatewayNode());
    scheduleAppWithTwoInstances();
    $developmentNode = Node::query()->where('name', 'app-dev-1')->firstOrFail();

    grantSchedulePermission($caller, $developmentNode, 'schedule:add');

    $hidden = schedulePost([
        'name' => 'nightly',
        'app' => 'docs.production',
        'interval' => 'daily at 02:00',
        'timezone' => 'UTC',
        'command' => 'php artisan reports:send',
    ]);
    $missing = schedulePost([
        'name' => 'nightly',
        'app' => 'docs.does-not-exist',
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
        'app' => 'docs.production',
        'interval' => 'daily at 02:00',
        'timezone' => 'UTC',
        'command' => 'php artisan reports:send',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.meta.reason', 'app_instance_unavailable')
        ->assertJsonPath('error.meta.app', 'docs')
        ->assertJsonPath('error.meta.instance', 'production');
})->with([
    'gateway node' => true,
    'gateway-admin grant' => false,
]);

it('excludes inactive app instance placements from bare selector eligibility', function (): void {
    $caller = scheduleInstanceCaller(gateway: true);
    scheduleGatewayHeartbeat($caller);
    [, $development] = scheduleAppWithTwoInstances();
    Node::query()
        ->where('name', 'app-prod-1')
        ->update(['status' => NodeStatus::Inactive]);

    schedulePost([
        'name' => 'nightly',
        'app' => 'docs',
        'interval' => 'daily at 02:00',
        'timezone' => 'UTC',
        'command' => 'php artisan reports:send',
    ])
        ->assertCreated()
        ->assertJsonPath('success.data.schedule.target.name', 'docs.development');

    expect(Schedule::query()->sole()->app_instance_id)->toBe($development->id);
});

it('rejects an ambiguous bare app selector before creating schedule intent', function (): void {
    $caller = scheduleInstanceCaller(gateway: true);
    scheduleGatewayHeartbeat($caller);
    scheduleAppWithTwoInstances();

    schedulePost([
        'name' => 'nightly',
        'app' => 'docs',
        'interval' => 'daily at 02:00',
        'timezone' => 'UTC',
        'command' => 'php artisan reports:send',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.meta.field', 'app')
        ->assertJsonPath('error.meta.reason', 'app_instance_required')
        ->assertJsonPath('error.meta.instances.0', 'development')
        ->assertJsonPath('error.meta.instances.1', 'production');

    expect(Schedule::query()->count())->toBe(0);
});

it('rejects a bare app selector when no eligible instance is visible to the caller', function (): void {
    $caller = scheduleInstanceCaller();
    scheduleGatewayHeartbeat(scheduleGatewayNode());
    scheduleAppWithTwoInstances();

    schedulePost([
        'name' => 'nightly',
        'app' => 'docs',
        'interval' => 'daily at 02:00',
        'timezone' => 'UTC',
        'command' => 'php artisan reports:send',
    ])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'authorization_failed')
        ->assertJsonPath('error.meta.missing_permission', 'schedule:add');

    expect(Schedule::query()->count())->toBe(0);
});

it('reads an explicit app instance and rejects ambiguous bare app filters', function (): void {
    $caller = scheduleInstanceCaller(gateway: true);
    [, $development, $production] = scheduleAppWithTwoInstances();
    Schedule::factory()->forAppInstance($development)->create(['name' => 'nightly']);
    Schedule::factory()->forAppInstance($production)->create(['name' => 'nightly']);

    scheduleGet('/api/schedules/nightly?app=docs.production')
        ->assertOk()
        ->assertJsonPath('success.data.schedule.target.name', 'docs.production')
        ->assertJsonPath('success.meta.app', 'docs.production');

    scheduleGet('/api/schedules/nightly?app=docs')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.meta.reason', 'app_instance_required');

    scheduleGet('/api/schedules?app=docs')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.meta.reason', 'app_instance_required');
});

it('lists schedules only for app instances visible to a non-gateway caller', function (): void {
    $caller = scheduleInstanceCaller();
    [, $development, $production] = scheduleAppWithTwoInstances();
    $developmentNode = Node::query()->where('name', 'app-dev-1')->firstOrFail();
    Schedule::factory()->forAppInstance($development)->create(['name' => 'development-nightly']);
    Schedule::factory()->forAppInstance($production)->create(['name' => 'production-nightly']);

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
 * @return array{App, AppInstance, AppInstance}
 */
function scheduleAppWithTwoInstances(): array
{
    $developmentNode = scheduleAppNode('app-dev-1', 'app-dev');
    $productionNode = scheduleAppNode('app-prod-1', 'app-prod');
    $app = App::factory()->for($developmentNode, 'node')->create([
        'name' => 'docs',
        'path' => '/srv/docs-development',
    ]);
    $development = scheduleAppInstance($app, $developmentNode, 'development', '/srv/docs-development');
    $production = scheduleAppInstance($app, $productionNode, 'production', '/srv/docs-production');

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

function scheduleAppInstance(App $app, Node $node, string $name, string $path): AppInstance
{
    return AppInstance::factory()->for($app)->create([
        'name' => $name,
        'driver' => AppInstanceDriver::Orbit,
        'driver_config' => new OrbitAppInstanceDriverConfigData(
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
