<?php

declare(strict_types=1);

use App\Enums\ProcessRestartPolicy;
use App\Models\App;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Models\Process;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const DASHBOARD_INVENTORY_CALLER_WG_IP = '10.6.0.96';

function create_dashboard_inventory_caller_node(array $overrides = [], ?string $role = null): Node
{
    $attributes = array_merge([
        'name' => 'caller',
        'host' => DASHBOARD_INVENTORY_CALLER_WG_IP,
        'wireguard_address' => DASHBOARD_INVENTORY_CALLER_WG_IP,
    ], $overrides);

    return match ($role) {
        'gateway' => createTestGatewayNode($attributes),
        default => Node::factory()->create($attributes),
    };
}

/**
 * @param  list<string>  $permissions
 */
function grant_dashboard_inventory_access(Node $caller, Node $node, array $permissions): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $node->id,
        'permissions' => json_encode($permissions, JSON_THROW_ON_ERROR),
        'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

describe('DashboardRuntimeInventoryController', function (): void {
    it('returns node grouped inventory records for gateway callers', function (): void {
        create_dashboard_inventory_caller_node(role: 'gateway');
        $appNode = createTestAppHostNode(['name' => 'app-1', 'wireguard_address' => '10.6.0.11']);
        $agentNode = Node::factory()->create(['name' => 'agent-1', 'status' => 'active']);

        NodeRoleAssignment::factory()->create([
            'node_id' => $agentNode->id,
            'role' => 'agent',
            'status' => 'active',
        ]);

        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        Process::factory()
            ->forOwner($app)
            ->create([
                'name' => 'queue',
                'restart_policy' => ProcessRestartPolicy::Always,
                'sort_order' => 1,
            ]);
        NodeTool::factory()->create(['name' => 'composer', 'node_id' => $appNode->id, 'expected_version' => '2.8']);

        $response = $this->call(
            'GET',
            '/api/dashboard/runtime-inventory',
            [],
            [],
            [],
            ['REMOTE_ADDR' => DASHBOARD_INVENTORY_CALLER_WG_IP],
        );

        $nodes = $response->json('success.data.nodes');
        $appNodePayload = collect($nodes)->firstWhere('name', 'app-1');

        $response
            ->assertOk()
            ->assertJsonPath('success.data.apps.0.name', 'docs')
            ->assertJsonPath('success.data.apps.0.node', 'app-1')
            ->assertJsonPath('success.data.processes.0.name', 'queue')
            ->assertJsonPath('success.data.processes.0.node', 'app-1')
            ->assertJsonPath('success.data.processes.0.app', 'docs')
            ->assertJsonPath('success.data.processes.0.status', 'managed')
            ->assertJsonPath('success.data.tools.0.name', 'composer')
            ->assertJsonPath('success.data.tools.0.node', 'app-1');

        expect($appNodePayload)
            ->toBeArray()
            ->and($appNodePayload['roles'][0]['role'] ?? null)
            ->toBe('app-dev');
    });

    it('filters apps processes and tools by node grants for non-gateway callers', function (): void {
        $caller = create_dashboard_inventory_caller_node();
        $visibleNode = createTestAppHostNode(['name' => 'visible-node']);
        $hiddenNode = createTestAppHostNode(['name' => 'hidden-node']);

        grant_dashboard_inventory_access($caller, $visibleNode, ['app:read', 'process:read', 'tool:read']);

        $visibleApp = App::factory()->create(['name' => 'visible-app', 'node_id' => $visibleNode->id]);
        $hiddenApp = App::factory()->create(['name' => 'hidden-app', 'node_id' => $hiddenNode->id]);

        Process::factory()->forOwner($visibleApp)->create(['name' => 'visible-process']);
        Process::factory()->forOwner($hiddenApp)->create(['name' => 'hidden-process']);
        NodeTool::factory()->create(['name' => 'visible-tool', 'node_id' => $visibleNode->id]);
        NodeTool::factory()->create(['name' => 'hidden-tool', 'node_id' => $hiddenNode->id]);

        $response = $this->call(
            'GET',
            '/api/dashboard/runtime-inventory',
            [],
            [],
            [],
            ['REMOTE_ADDR' => DASHBOARD_INVENTORY_CALLER_WG_IP],
        );

        $response->assertOk();

        expect(array_column($response->json('success.data.apps'), 'name'))->toBe(['visible-app']);
        expect(array_column($response->json('success.data.processes'), 'name'))->toBe(['visible-process']);
        expect(array_column($response->json('success.data.tools'), 'name'))->toBe(['visible-tool']);
    });

    it('rejects unauthenticated requests', function (): void {
        $response = $this->getJson('/api/dashboard/runtime-inventory');

        $response
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.message', 'Peer identity unknown.');
    });
});
