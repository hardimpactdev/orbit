<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Services\Nodes\Roles\NodeRoleBaselineConverger;
use App\Services\Nodes\Roles\RoleBaselines\AgentRoleBaseline;
use App\Services\Nodes\Roles\RoleBaselines\AppDevelopmentRoleBaseline;
use App\Services\Nodes\Roles\RoleBaselines\AppProductionRoleBaseline;
use App\Services\Nodes\Roles\RoleBaselines\DatabaseRoleBaseline;
use App\Services\Nodes\Roles\RoleBaselines\GatewayRoleBaseline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

const NODE_ROLE_REMOVE_CALLER_WG_IP = '10.6.0.91';

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function apiNodeRoleRemoveRow(array $overrides = []): array
{
    return array_merge([
        'name' => 'app-1',
        'role' => 'app',
        'host' => '10.6.0.7',
        'user' => 'nckrtl',
        'orbit_path' => '/home/nckrtl/orbit',
        'status' => 'active',
        'environment' => 'development',
        'platform' => 'ubuntu',
        'wireguard_address' => '10.6.0.7',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

function createNodeRoleRemoveCaller(string $role = 'control'): int
{
    return (int) DB::table('nodes')->insertGetId(apiNodeRoleRemoveRow([
        'name' => "{$role}-caller",
        'role' => $role,
        'host' => NODE_ROLE_REMOVE_CALLER_WG_IP,
        'environment' => $role === 'app' ? 'development' : null,
        'wireguard_address' => NODE_ROLE_REMOVE_CALLER_WG_IP,
    ]));
}

function createNodeRoleRemoveGateway(): int
{
    $nodeId = (int) DB::table('nodes')->insertGetId(apiNodeRoleRemoveRow([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'host' => '10.6.0.2',
        'environment' => null,
        'wireguard_address' => '10.6.0.2',
    ]));

    NodeRoleAssignment::factory()->create([
        'node_id' => $nodeId,
        'role' => 'gateway',
        'status' => 'active',
    ]);

    return $nodeId;
}

/**
 * @param  list<string>  $permissions
 */
function grantNodeRoleRemoveAccess(int $callerId, int $targetId, array $permissions = ['role:remove']): void
{
    NodeAccess::query()->create([
        'consumer_node_id' => $callerId,
        'serving_node_id' => $targetId,
        'permissions' => $permissions,
        'custom_permissions' => [],
    ]);
}

/**
 * @param  array<string, mixed>  $data
 * @param  array<string, string>  $server
 */
function deleteNodeRoleRemoveJson(string $uri, array $data = [], array $server = []): TestResponse
{
    /** @phpstan-ignore-next-line Pest resolves call() on the bound Laravel test case at runtime. */
    return test()->call(
        'DELETE',
        $uri,
        $data,
        [],
        [],
        array_merge([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $server),
        json_encode($data, JSON_THROW_ON_ERROR),
    );
}

describe('NodeRoleRemoveController', function (): void {
    it('reports missing permission metadata on authorization failure', function (): void {
        createNodeRoleRemoveCaller('control');
        createNodeRoleRemoveGateway();

        Node::query()->create(apiNodeRoleRemoveRow([
            'name' => 'target-1',
            'wireguard_address' => '10.6.0.20',
        ]));

        $response = deleteNodeRoleRemoveJson('/api/nodes/target-1/roles/database', [], [
            'REMOTE_ADDR' => NODE_ROLE_REMOVE_CALLER_WG_IP,
        ]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.reason', 'missing_permission')
            ->assertJsonPath('error.meta.missing_permission', 'role:remove')
            ->assertJsonPath('error.meta.serving_node', 'target-1');
    });

    it('logs dependent summaries when role removal is blocked', function (): void {
        $callerId = createNodeRoleRemoveCaller();
        createNodeRoleRemoveGateway();

        $node = Node::query()->create(apiNodeRoleRemoveRow([
            'name' => 'target-1',
            'wireguard_address' => '10.6.0.20',
        ]));
        grantNodeRoleRemoveAccess($callerId, $node->id);

        NodeRoleAssignment::query()->create([
            'node_id' => $node->id,
            'role' => 'app-development',
            'status' => 'active',
            'settings' => ['tld' => 'test'],
            'last_error' => null,
            'converged_at' => now(),
        ]);

        DB::table('apps')->insert([
            'name' => 'docs',
            'node_id' => $node->id,
            'environment' => 'development',
            'domain' => null,
            'path' => '/home/orbit/apps/docs',
            'document_root' => 'public',
            'repository' => null,
            'php_version' => '8.5',
            'adopted' => false,
            'agent_ide_config' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = deleteNodeRoleRemoveJson('/api/nodes/target-1/roles/app-development', [], [
            'REMOTE_ADDR' => NODE_ROLE_REMOVE_CALLER_WG_IP,
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'node_role.remove_blocked')
            ->assertJsonPath('error.meta.dependents.0', '1 development app record');

        $entry = Activity::query()->first();

        expect($entry)->not->toBeNull();
        expect($entry->event)->toBe('node.role.remove_blocked');
        expect($entry->subject_type)->toBe(Node::class);
        expect($entry->subject_id)->toBe($node->id);
        expect($entry->properties->get('dependents'))->toBe(['1 development app record']);
    });

    it('rejects gateway role removal before side effects', function (): void {
        $callerId = createNodeRoleRemoveCaller();
        createNodeRoleRemoveGateway();

        $node = Node::query()->create(apiNodeRoleRemoveRow([
            'name' => 'gateway-2',
            'role' => 'gateway',
            'environment' => null,
            'wireguard_address' => '10.6.0.20',
        ]));

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'gateway',
            'status' => 'active',
        ]);
        grantNodeRoleRemoveAccess($callerId, $node->id);

        $response = deleteNodeRoleRemoveJson('/api/nodes/gateway-2/roles/gateway', [
            'force' => true,
        ], [
            'REMOTE_ADDR' => NODE_ROLE_REMOVE_CALLER_WG_IP,
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'role')
            ->assertJsonPath('error.meta.role', 'gateway');

        expect($node->roleAssignments()->where('role', 'gateway')->exists())->toBeTrue();
    });

    it('refreshes legacy node shadows when the final app role is removed', function (): void {
        $callerId = createNodeRoleRemoveCaller();
        createNodeRoleRemoveGateway();

        $node = Node::query()->create(apiNodeRoleRemoveRow([
            'name' => 'target-1',
            'wireguard_address' => '10.6.0.20',
            'tld' => 'test',
        ]));
        grantNodeRoleRemoveAccess($callerId, $node->id);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'app-development',
            'status' => 'active',
            'settings' => ['tld' => 'test'],
        ]);
        NodeAccess::query()->create([
            'consumer_node_id' => $node->id,
            'serving_node_id' => $node->id,
            'permissions' => ['workspace:setup'],
            'custom_permissions' => [],
        ]);

        $response = deleteNodeRoleRemoveJson('/api/nodes/target-1/roles/app-development', [], [
            'REMOTE_ADDR' => NODE_ROLE_REMOVE_CALLER_WG_IP,
        ]);

        $response->assertOk()
            ->assertJsonPath('success.data.role', 'app-development');

        $node->refresh();

        expect($node->roleAssignments()->where('role', 'app-development')->exists())->toBeFalse()
            ->and($node->role)->toBe('control')
            ->and($node->environment)->toBeNull()
            ->and($node->tld)->toBeNull()
            ->and(NodeAccess::query()
                ->where('consumer_node_id', $node->id)
                ->where('serving_node_id', $node->id)
                ->exists())->toBeFalse();
    });

    it('removes Orbit-owned role dependents when force is true without purge data', function (): void {
        $callerId = createNodeRoleRemoveCaller();
        createNodeRoleRemoveGateway();

        $node = Node::query()->create(apiNodeRoleRemoveRow([
            'name' => 'target-1',
            'wireguard_address' => '10.6.0.20',
        ]));
        grantNodeRoleRemoveAccess($callerId, $node->id);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'database',
            'status' => 'active',
        ]);

        NodeTool::query()->create([
            'node_id' => $node->id,
            'name' => 'postgres',
            'expected_state' => 'running',
            'installed_version' => null,
            'settings' => [],
            'status' => 'running',
        ]);

        $response = deleteNodeRoleRemoveJson('/api/nodes/target-1/roles/database', [
            'force' => true,
        ], [
            'REMOTE_ADDR' => NODE_ROLE_REMOVE_CALLER_WG_IP,
        ]);

        $response->assertOk()
            ->assertJsonPath('success.data.purged_data', false);

        expect(NodeTool::query()->where('node_id', $node->id)->where('name', 'postgres')->exists())->toBeFalse();
    });

    it('rejects purge data without force before removing role state', function (): void {
        $callerId = createNodeRoleRemoveCaller();
        createNodeRoleRemoveGateway();

        $node = Node::query()->create(apiNodeRoleRemoveRow([
            'name' => 'target-1',
            'wireguard_address' => '10.6.0.20',
        ]));
        grantNodeRoleRemoveAccess($callerId, $node->id);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'database',
            'status' => 'active',
        ]);

        NodeTool::query()->create([
            'node_id' => $node->id,
            'name' => 'postgres',
            'expected_state' => 'running',
            'installed_version' => null,
            'settings' => [],
            'status' => 'running',
        ]);

        $response = deleteNodeRoleRemoveJson('/api/nodes/target-1/roles/database', [
            'purge_data' => true,
        ], [
            'REMOTE_ADDR' => NODE_ROLE_REMOVE_CALLER_WG_IP,
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.message', 'The purge-data option requires --force.')
            ->assertJsonPath('error.meta.field', 'purge_data');

        expect($node->roleAssignments()->where('role', 'database')->exists())->toBeTrue()
            ->and(NodeTool::query()->where('node_id', $node->id)->where('name', 'postgres')->exists())
            ->toBeTrue();
    });

    it('purges role dependents only when purge data is requested with force', function (): void {
        $callerId = createNodeRoleRemoveCaller();
        createNodeRoleRemoveGateway();

        $node = Node::query()->create(apiNodeRoleRemoveRow([
            'name' => 'target-1',
            'wireguard_address' => '10.6.0.20',
        ]));
        grantNodeRoleRemoveAccess($callerId, $node->id);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'database',
            'status' => 'active',
        ]);

        NodeTool::query()->create([
            'node_id' => $node->id,
            'name' => 'postgres',
            'expected_state' => 'running',
            'installed_version' => null,
            'settings' => [],
            'status' => 'running',
        ]);

        $response = deleteNodeRoleRemoveJson('/api/nodes/target-1/roles/database', [
            'force' => true,
            'purge_data' => true,
        ], [
            'REMOTE_ADDR' => NODE_ROLE_REMOVE_CALLER_WG_IP,
        ]);

        $response->assertOk()
            ->assertJsonPath('success.data.purged_data', true);

        expect(NodeTool::query()->where('node_id', $node->id)->where('name', 'postgres')->exists())->toBeFalse();
    });

    it('returns an error when role removal cleanup fails', function (): void {
        $callerId = createNodeRoleRemoveCaller();
        createNodeRoleRemoveGateway();

        $node = Node::query()->create(apiNodeRoleRemoveRow([
            'name' => 'target-1',
            'wireguard_address' => '10.6.0.20',
        ]));
        grantNodeRoleRemoveAccess($callerId, $node->id);

        $assignment = NodeRoleAssignment::query()->create([
            'node_id' => $node->id,
            'role' => 'app-development',
            'status' => 'active',
            'settings' => ['tld' => 'test'],
            'last_error' => null,
            'converged_at' => now(),
        ]);

        app()->instance(NodeRoleBaselineConverger::class, new class extends NodeRoleBaselineConverger
        {
            public function __construct()
            {
                parent::__construct(
                    app(GatewayRoleBaseline::class),
                    app(AppDevelopmentRoleBaseline::class),
                    app(AppProductionRoleBaseline::class),
                    app(DatabaseRoleBaseline::class),
                    app(AgentRoleBaseline::class),
                );
            }

            public function remove(Node $node, NodeRoleAssignment $assignment, bool $purgeData): void
            {
                throw new RuntimeException('Cleanup failed.');
            }
        });

        $response = deleteNodeRoleRemoveJson('/api/nodes/target-1/roles/app-development', [
            'force' => true,
        ], [
            'REMOTE_ADDR' => NODE_ROLE_REMOVE_CALLER_WG_IP,
        ]);

        $response->assertStatus(500)
            ->assertJsonPath('error.code', 'node_role.remove_failed')
            ->assertJsonPath('error.meta.last_error', 'Cleanup failed.');

        expect($assignment->fresh()->status)->toBe('error')
            ->and($assignment->fresh()->last_error)->toBe('Cleanup failed.');
    });
});
