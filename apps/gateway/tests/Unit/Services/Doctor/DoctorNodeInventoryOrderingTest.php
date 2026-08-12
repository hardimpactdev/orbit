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
use App\Services\Doctor\DoctorProcessFamilyProbe;
use App\Services\Doctor\DoctorReportRunner;
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
    $app = App::factory()->create(['node_id' => $node->id]);
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
        $runner = app(DoctorReportRunner::class);
        $scope = DoctorTargetScope::none();

        expect(doctor_inventory_ids($runner, methodName: 'instancesForNode', arguments: [$node]))
            ->toBe($instances->pluck('id')->all())
            ->and(doctor_inventory_ids($runner, methodName: 'workspacesForNode', arguments: [$node]))
            ->toBe($workspaces->pluck('id')->all())
            ->and(doctor_inventory_ids(
                app(DoctorProcessFamilyProbe::class),
                methodName: 'processesForNode',
                arguments: [$node],
            ))
            ->toBe($processes->pluck('id')->all())
            ->and(doctor_inventory_ids($runner, methodName: 'proxyRoutesForScope', arguments: [$node, $scope]))
            ->toBe($routes->pluck('id')->all())
            ->and(doctor_inventory_ids($runner, methodName: 'firewallRulesForNode', arguments: [$node]))
            ->toBe($firewallRules->pluck('id')->all())
            ->and(doctor_inventory_ids($runner, methodName: 'toolsForNode', arguments: [$node]))
            ->toBe($tools->pluck('id')->all())
            ->and(doctor_inventory_ids($runner, methodName: 'schedulesForNode', arguments: [$node]))
            ->toBe($schedules->pluck('id')->all());
    } finally {
        DB::statement('PRAGMA reverse_unordered_selects = OFF');
    }
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
            app(DoctorReportRunner::class),
            methodName: 'schedulesForNode',
            arguments: [$gateway],
        ))->toBe([$firstSchedule->id, $secondSchedule->id]);
    } finally {
        DB::statement('PRAGMA reverse_unordered_selects = OFF');
    }
});

/**
 * @param  list<mixed>  $arguments
 * @return list<int>
 */
function doctor_inventory_ids(object $owner, string $methodName, array $arguments): array
{
    $method = new ReflectionMethod($owner, $methodName);
    /** @var Collection<int, Model> $inventory */
    $inventory = $method->invokeArgs($owner, $arguments);

    expect($inventory)->toBeInstanceOf(Collection::class);

    return array_values(
        $inventory
            ->map(static fn (Model $model): int => (int) $model->getKey())
            ->all(),
    );
}
