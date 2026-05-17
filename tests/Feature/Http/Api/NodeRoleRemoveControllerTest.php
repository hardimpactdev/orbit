<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Nodes\Roles\NodeRoleBaselineConverger;
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
    return (int) DB::table('nodes')->insertGetId(apiNodeRoleRemoveRow([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'host' => '10.6.0.2',
        'environment' => null,
        'wireguard_address' => '10.6.0.2',
    ]));
}

function grantNodeRoleRemoveGatewayAccess(int $callerId, int $gatewayId): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $callerId,
        'serving_node_id' => $gatewayId,
        'created_at' => now(),
        'updated_at' => now(),
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
    it('logs dependent summaries when role removal is blocked', function (): void {
        $callerId = createNodeRoleRemoveCaller();
        $gatewayId = createNodeRoleRemoveGateway();
        grantNodeRoleRemoveGatewayAccess($callerId, $gatewayId);

        $node = Node::query()->create(apiNodeRoleRemoveRow([
            'name' => 'target-1',
            'wireguard_address' => '10.6.0.20',
        ]));

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

    it('returns an error when role removal cleanup fails', function (): void {
        $callerId = createNodeRoleRemoveCaller();
        $gatewayId = createNodeRoleRemoveGateway();
        grantNodeRoleRemoveGatewayAccess($callerId, $gatewayId);

        $node = Node::query()->create(apiNodeRoleRemoveRow([
            'name' => 'target-1',
            'wireguard_address' => '10.6.0.20',
        ]));

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
