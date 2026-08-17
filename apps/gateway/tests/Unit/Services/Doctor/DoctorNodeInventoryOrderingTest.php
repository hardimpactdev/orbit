<?php

declare(strict_types=1);

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Data\Doctor\DoctorTargetScope;
use App\Models\App;
use App\Models\FirewallRule;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Models\Process;
use App\Models\ProxyRoute;
use App\Models\Schedule;
use App\Models\Workspace;
use App\Services\Doctor\DoctorFirewallRuleFamilyProbe;
use App\Services\Doctor\DoctorNodeProbeRunner;
use App\Services\Doctor\DoctorProcessFamilyProbe;
use App\Services\Doctor\DoctorProxyRouteInventory;
use App\Services\Doctor\DoctorScheduleFamilyProbe;
use App\Services\Doctor\DoctorToolFamilyProbe;
use App\Services\Doctor\DoctorWorkspaceFamilyProbe;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** @mago-expect lint:halstead */
it('reads every node inventory in stable row identity order', function (): void {
    /** @var Node $node */
    $node = Node::factory()->create([
        'name' => 'doctor-ordering-node',
        'status' => 'active',
        'platform' => 'ubuntu_24-04',
    ]);
    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-dev',
        'status' => 'active',
    ]);
    /** @var App $app */
    $app = App::factory()->create();
    /** @var Instance $firstInstance */
    $firstInstance = Instance::factory()->create([
        'app_id' => $app->id,
        'name' => 'zulu',
        'driver_config' => new OrbitInstanceDriverConfigData(node_id: $node->id),
    ]);
    /** @var Instance $secondInstance */
    $secondInstance = Instance::factory()->create([
        'app_id' => $app->id,
        'name' => 'alpha',
        'driver_config' => new OrbitInstanceDriverConfigData(node_id: $node->id),
    ]);
    $instances = collect([$firstInstance, $secondInstance]);
    /** @var Workspace $firstWorkspace */
    $firstWorkspace = Workspace::factory()->create([
        'app_id' => $app->id,
        'instance_id' => $firstInstance->id,
        'name' => 'zulu',
    ]);
    /** @var Workspace $secondWorkspace */
    $secondWorkspace = Workspace::factory()->create([
        'app_id' => $app->id,
        'instance_id' => $firstInstance->id,
        'name' => 'alpha',
    ]);
    $workspaces = collect([$firstWorkspace, $secondWorkspace]);
    /** @var Process $firstProcess */
    $firstProcess = Process::factory()->create([
        'node_id' => $node->id,
        'owner_type' => $node->getMorphClass(),
        'owner_id' => $node->id,
        'name' => 'zulu',
    ]);
    /** @var Process $secondProcess */
    $secondProcess = Process::factory()->create([
        'node_id' => $node->id,
        'owner_type' => $node->getMorphClass(),
        'owner_id' => $node->id,
        'name' => 'alpha',
    ]);
    $processes = collect([$firstProcess, $secondProcess]);
    /** @var ProxyRoute $firstRoute */
    $firstRoute = ProxyRoute::factory()->create(['node_id' => $node->id, 'domain' => 'zulu.test']);
    /** @var ProxyRoute $secondRoute */
    $secondRoute = ProxyRoute::factory()->create(['node_id' => $node->id, 'domain' => 'alpha.test']);
    $routes = collect([$firstRoute, $secondRoute]);
    /** @var FirewallRule $firstFirewallRule */
    $firstFirewallRule = FirewallRule::factory()->create(['node_id' => $node->id, 'name' => 'zulu']);
    /** @var FirewallRule $secondFirewallRule */
    $secondFirewallRule = FirewallRule::factory()->create(['node_id' => $node->id, 'name' => 'alpha']);
    $firewallRules = collect([$firstFirewallRule, $secondFirewallRule]);
    /** @var NodeTool $firstTool */
    $firstTool = NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'zulu']);
    /** @var NodeTool $secondTool */
    $secondTool = NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'alpha']);
    $tools = collect([$firstTool, $secondTool]);
    /** @var Schedule $firstSchedule */
    $firstSchedule = Schedule::factory()->create([
        'name' => 'zulu',
        'scope' => 'node',
        'app_id' => null,
        'instance_id' => null,
        'node_id' => $node->id,
        'target_name' => $node->name,
        'schedule_key' => "node:{$node->name}:zulu",
    ]);
    /** @var Schedule $secondSchedule */
    $secondSchedule = Schedule::factory()->create([
        'name' => 'alpha',
        'scope' => 'node',
        'app_id' => null,
        'instance_id' => null,
        'node_id' => $node->id,
        'target_name' => $node->name,
        'schedule_key' => "node:{$node->name}:alpha",
    ]);
    $schedules = collect([$firstSchedule, $secondSchedule]);

    DB::statement('PRAGMA reverse_unordered_selects = ON');

    try {
        $runner = app(DoctorNodeProbeRunner::class);
        $scope = DoctorTargetScope::none();

        expect(doctor_inventory_ids(
            $runner,
            methodName: 'instancesForNode',
            arguments: [$node],
            orderedTable: 'instances',
        ))
            ->toBe($instances->pluck('id')->all())
            ->and(doctor_inventory_ids(
                app(DoctorWorkspaceFamilyProbe::class),
                methodName: 'workspacesForNode',
                arguments: [$node],
                orderedTable: 'workspaces',
            ))
            ->toBe($workspaces->pluck('id')->all())
            ->and(doctor_inventory_ids(
                app(DoctorProcessFamilyProbe::class),
                methodName: 'processesForNode',
                arguments: [$node],
                orderedTable: 'processes',
            ))
            ->toBe($processes->pluck('id')->all())
            ->and(doctor_inventory_ids(
                app(DoctorProxyRouteInventory::class),
                methodName: 'forScope',
                arguments: [$node, $scope],
                orderedTable: 'proxy_routes',
            ))
            ->toBe($routes->pluck('id')->all())
            ->and(doctor_inventory_ids(
                app(DoctorFirewallRuleFamilyProbe::class),
                methodName: 'firewallRulesForNode',
                arguments: [$node],
                orderedTable: 'firewall_rules',
            ))
            ->toBe($firewallRules->pluck('id')->all())
            ->and(doctor_inventory_ids(
                app(DoctorToolFamilyProbe::class),
                methodName: 'toolsForNode',
                arguments: [$node],
                orderedTable: 'node_tools',
            ))
            ->toBe($tools->pluck('id')->all())
            ->and(doctor_inventory_ids(
                app(DoctorScheduleFamilyProbe::class),
                methodName: 'nodeSchedules',
                arguments: [$node],
                orderedTable: 'schedules',
            ))
            ->toBe($schedules->pluck('id')->all());
    } finally {
        DB::statement('PRAGMA reverse_unordered_selects = OFF');
    }
});

it('scopes proxy routes through the concrete instance app instead of compatibility app_id', function (): void {
    $node = Node::factory()->appDev()->create(['name' => 'doctor-instance-owner']);
    $owner = App::factory()->create(['name' => 'owner']);
    $compatibility = App::factory()->create(['name' => 'compatibility']);
    $instance = Instance::factory()->for($owner)->create([
        'driver_config' => new OrbitInstanceDriverConfigData(node_id: $node->id),
    ]);
    $route = ProxyRoute::factory()->create([
        'node_id' => $node->id,
        'app_id' => $owner->id,
        'instance_id' => $instance->id,
        'owner_type' => 'app',
        'kind' => 'app',
    ]);
    $route->forceFill(['app_id' => $compatibility->id])->save();
    $inventory = app(DoctorProxyRouteInventory::class);

    expect($inventory->forScope($node, DoctorTargetScope::from(app: null, workspace: null))->modelKeys())
        ->toBe([$route->id])
        ->and($inventory->forScope($node, DoctorTargetScope::from(app: 'owner', workspace: null))->modelKeys())
        ->toBe([])
        ->and(
            $inventory
                ->forScope(
                    $node,
                    DoctorTargetScope::from(app: 'compatibility', workspace: null),
                )
                ->modelKeys(),
        )
        ->toBe([]);
});

it('reads the gateway schedule inventory in stable row identity order', function (): void {
    /** @var Node $gateway */
    $gateway = Node::factory()->create([
        'name' => 'gateway',
        'status' => 'active',
    ]);
    NodeRoleAssignment::factory()->create([
        'node_id' => $gateway->id,
        'role' => 'gateway',
        'status' => 'active',
    ]);
    /** @var Schedule $firstSchedule */
    $firstSchedule = Schedule::factory()->create([
        'name' => 'zulu',
        'scope' => 'orbit',
        'app_id' => null,
        'instance_id' => null,
        'node_id' => null,
        'target_name' => 'gateway',
        'schedule_key' => 'orbit:zulu',
    ]);
    /** @var Schedule $secondSchedule */
    $secondSchedule = Schedule::factory()->create([
        'name' => 'alpha',
        'scope' => 'orbit',
        'app_id' => null,
        'instance_id' => null,
        'node_id' => null,
        'target_name' => 'gateway',
        'schedule_key' => 'orbit:alpha',
    ]);

    DB::statement('PRAGMA reverse_unordered_selects = ON');

    try {
        expect(doctor_inventory_ids(
            app(DoctorScheduleFamilyProbe::class),
            methodName: 'gatewaySchedules',
            arguments: [],
            orderedTable: 'schedules',
        ))->toBe([$firstSchedule->id, $secondSchedule->id]);
    } finally {
        DB::statement('PRAGMA reverse_unordered_selects = OFF');
    }
});

/**
 * @param  list<mixed>  $arguments
 * @return list<int>
 */
function doctor_inventory_ids(object $owner, string $methodName, array $arguments, string $orderedTable): array
{
    $connection = DB::connection();
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    $method = new ReflectionMethod($owner, $methodName);
    /** @var Collection<int, Model> $inventory */
    $inventory = $method->invokeArgs($owner, $arguments);

    /** @var list<array{query: string, bindings: array<int, mixed>, time: float|null}> $queries */
    $queries = $connection->getQueryLog();
    $connection->disableQueryLog();

    $inventoryQuery = collect($queries)->first(
        static fn (array $query): bool => str_contains(strtolower($query['query']), 'from "'.$orderedTable.'"'),
    );

    if (! is_array($inventoryQuery)) {
        throw new RuntimeException("No query captured for [{$orderedTable}].");
    }

    expect($inventory)
        ->toBeInstanceOf(Collection::class)
        ->and(strtolower($inventoryQuery['query']))
        ->toContain('order by "id" asc');

    return array_values(
        $inventory
            ->map(static fn (Model $model): int => (int) $model->getKey())
            ->all(),
    );
}
