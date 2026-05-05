<?php

declare(strict_types=1);

use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

const REVOKE_CALLER_WG_IP = '10.6.0.99';

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function apiRevokeNodeRow(array $overrides = []): array
{
    return array_merge([
        'name' => 'app-1',
        'role' => 'app',
        'host' => '10.6.0.7',
        'ssh_user' => 'nckrtl',
        'orbit_path' => '/home/nckrtl/orbit',
        'status' => 'active',
        'is_local' => false,
        'environment' => 'development',
        'platform' => 'ubuntu_24-04',
        'wireguard_address' => '10.6.0.7',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

function createRevokeCallerNode(string $role = 'control'): int
{
    return (int) DB::table('nodes')->insertGetId(apiRevokeNodeRow([
        'name' => "{$role}-caller",
        'role' => $role,
        'host' => REVOKE_CALLER_WG_IP,
        'environment' => $role === 'app' ? 'development' : null,
        'wireguard_address' => REVOKE_CALLER_WG_IP,
    ]));
}

function createRevokeGatewayNode(): int
{
    return (int) DB::table('nodes')->insertGetId(apiRevokeNodeRow([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'host' => '10.6.0.2',
        'environment' => null,
        'wireguard_address' => '10.6.0.2',
    ]));
}

function grantRevokeAccess(int $consumerId, int $servingId): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $consumerId,
        'serving_node_id' => $servingId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @param  array<string, mixed>  $data
 * @param  array<string, string>  $server
 */
function postNodeRevokeJson(array $data, array $server = []): TestResponse
{
    return test()->call(
        'POST',
        '/api/nodes/revoke',
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

describe('NodeRevokeController', function (): void {
    it('revokes an existing grant for an authorized control caller', function (): void {
        $callerId = createRevokeCallerNode();
        $gatewayId = createRevokeGatewayNode();
        grantRevokeAccess($callerId, $gatewayId);
        $consumingId = (int) DB::table('nodes')->insertGetId(apiRevokeNodeRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
            'wireguard_address' => '10.6.0.11',
        ]));
        $servingId = (int) DB::table('nodes')->insertGetId(apiRevokeNodeRow([
            'name' => 'app-1',
            'wireguard_address' => '10.6.0.12',
        ]));
        grantRevokeAccess($consumingId, $servingId);

        $response = postNodeRevokeJson([
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            'force' => true,
        ], ['REMOTE_ADDR' => REVOKE_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJson([
                'success' => [
                    'data' => [
                        'consuming_node' => 'control-1',
                        'serving_node' => 'app-1',
                        'action' => 'revoked',
                        'already_absent' => false,
                        'self_lockout' => false,
                    ],
                ],
            ]);

        expect(DB::table('node_access')
            ->where('consumer_node_id', $consumingId)
            ->where('serving_node_id', $servingId)
            ->exists())->toBeFalse();
    });

    it('logs activity for a successful grant revocation', function (): void {
        $callerId = createRevokeCallerNode();
        $gatewayId = createRevokeGatewayNode();
        grantRevokeAccess($callerId, $gatewayId);
        $consumingId = (int) DB::table('nodes')->insertGetId(apiRevokeNodeRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
            'wireguard_address' => '10.6.0.11',
        ]));
        $servingId = (int) DB::table('nodes')->insertGetId(apiRevokeNodeRow([
            'name' => 'app-1',
            'wireguard_address' => '10.6.0.12',
        ]));
        grantRevokeAccess($consumingId, $servingId);

        $response = postNodeRevokeJson([
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            'force' => true,
        ], ['REMOTE_ADDR' => REVOKE_CALLER_WG_IP]);

        $response->assertOk();

        $entry = Activity::query()->first();

        expect($entry)->not->toBeNull();
        expect($entry->event)->toBe('api:POST /nodes/revoke');
        expect($entry->subject_type)->toBe(Node::class);
        expect($entry->subject_id)->toBe($servingId);
        expect($entry->description)->toBe('control-1 revoked access to app-1');
        expect($entry->properties->get('type'))->toBe('destructive');
        expect($entry->properties->get('consuming_node'))->toBe('control-1');
        expect($entry->properties->get('serving_node'))->toBe('app-1');
        expect($entry->properties->get('self_lockout'))->toBeFalse();
    });

    it('revokes directly for a gateway caller', function (): void {
        createRevokeCallerNode('gateway');
        $consumingId = (int) DB::table('nodes')->insertGetId(apiRevokeNodeRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
            'wireguard_address' => '10.6.0.11',
        ]));
        $servingId = (int) DB::table('nodes')->insertGetId(apiRevokeNodeRow([
            'name' => 'app-1',
            'wireguard_address' => '10.6.0.12',
        ]));
        grantRevokeAccess($consumingId, $servingId);

        $response = postNodeRevokeJson([
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            'force' => true,
        ], ['REMOTE_ADDR' => REVOKE_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.action', 'revoked')
            ->assertJsonPath('success.data.already_absent', false);

        expect(DB::table('node_access')
            ->where('consumer_node_id', $consumingId)
            ->where('serving_node_id', $servingId)
            ->exists())->toBeFalse();
    });

    it('returns idempotent success when the grant is already absent', function (): void {
        $callerId = createRevokeCallerNode();
        $gatewayId = createRevokeGatewayNode();
        grantRevokeAccess($callerId, $gatewayId);
        DB::table('nodes')->insert(apiRevokeNodeRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
            'wireguard_address' => '10.6.0.11',
        ]));
        DB::table('nodes')->insert(apiRevokeNodeRow([
            'name' => 'app-1',
            'wireguard_address' => '10.6.0.12',
        ]));

        $response = postNodeRevokeJson([
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            'force' => true,
        ], ['REMOTE_ADDR' => REVOKE_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.action', 'revoked')
            ->assertJsonPath('success.data.already_absent', true)
            ->assertJsonPath('success.data.self_lockout', false);
    });

    it('reports self lockout when a control caller revokes its own gateway access', function (): void {
        $callerId = createRevokeCallerNode();
        $gatewayId = createRevokeGatewayNode();
        grantRevokeAccess($callerId, $gatewayId);

        $response = postNodeRevokeJson([
            'consuming_node' => 'control-caller',
            'serving_node' => 'gateway-1',
            'force' => true,
        ], ['REMOTE_ADDR' => REVOKE_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.consuming_node', 'control-caller')
            ->assertJsonPath('success.data.serving_node', 'gateway-1')
            ->assertJsonPath('success.data.action', 'revoked')
            ->assertJsonPath('success.data.already_absent', false)
            ->assertJsonPath('success.data.self_lockout', true);

        expect(DB::table('node_access')
            ->where('consumer_node_id', $callerId)
            ->where('serving_node_id', $gatewayId)
            ->exists())->toBeFalse();
    });

    it('rejects unauthenticated requests', function (): void {
        $response = postNodeRevokeJson([
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            'force' => true,
        ]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.message', 'Peer identity unknown.')
            ->assertJsonPath('error.meta', []);
    });

    it('rejects app callers before mutation', function (): void {
        createRevokeCallerNode('app');
        $consumingId = (int) DB::table('nodes')->insertGetId(apiRevokeNodeRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
            'wireguard_address' => '10.6.0.11',
        ]));
        $servingId = (int) DB::table('nodes')->insertGetId(apiRevokeNodeRow([
            'name' => 'app-1',
            'wireguard_address' => '10.6.0.12',
        ]));
        grantRevokeAccess($consumingId, $servingId);

        $response = postNodeRevokeJson([
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            'force' => true,
        ], ['REMOTE_ADDR' => REVOKE_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'caller_role_not_allowed')
            ->assertJsonPath('error.message', 'This command may only be run from a control or gateway node.')
            ->assertJsonPath('error.meta.caller_role', 'app');

        expect(DB::table('node_access')
            ->where('consumer_node_id', $consumingId)
            ->where('serving_node_id', $servingId)
            ->exists())->toBeTrue();
    });

    it('rejects control callers without gateway access before mutation', function (): void {
        createRevokeCallerNode();
        createRevokeGatewayNode();
        $consumingId = (int) DB::table('nodes')->insertGetId(apiRevokeNodeRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
            'wireguard_address' => '10.6.0.11',
        ]));
        $servingId = (int) DB::table('nodes')->insertGetId(apiRevokeNodeRow([
            'name' => 'app-1',
            'wireguard_address' => '10.6.0.12',
        ]));
        grantRevokeAccess($consumingId, $servingId);

        $response = postNodeRevokeJson([
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            'force' => true,
        ], ['REMOTE_ADDR' => REVOKE_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.message', 'This control node is not authorized to revoke grants.')
            ->assertJsonPath('error.meta.required_node', 'gateway-1')
            ->assertJsonPath('error.meta.caller_role', 'control');

        expect(DB::table('node_access')
            ->where('consumer_node_id', $consumingId)
            ->where('serving_node_id', $servingId)
            ->exists())->toBeTrue();
    });

    it('rejects requests without destructive consent', function (): void {
        $callerId = createRevokeCallerNode();
        $gatewayId = createRevokeGatewayNode();
        grantRevokeAccess($callerId, $gatewayId);

        $response = postNodeRevokeJson([
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
        ], ['REMOTE_ADDR' => REVOKE_CALLER_WG_IP]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.message', 'Use --force to revoke this grant.')
            ->assertJsonPath('error.meta.field', 'force');
    });

    it('rejects missing consuming node input', function (): void {
        $callerId = createRevokeCallerNode();
        $gatewayId = createRevokeGatewayNode();
        grantRevokeAccess($callerId, $gatewayId);

        $response = postNodeRevokeJson([
            'serving_node' => 'app-1',
            'force' => true,
        ], ['REMOTE_ADDR' => REVOKE_CALLER_WG_IP]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.message', 'Consuming node is required.')
            ->assertJsonPath('error.meta.field', 'consuming_node');
    });

    it('rejects missing serving node input', function (): void {
        $callerId = createRevokeCallerNode();
        $gatewayId = createRevokeGatewayNode();
        grantRevokeAccess($callerId, $gatewayId);

        $response = postNodeRevokeJson([
            'consuming_node' => 'control-1',
            'force' => true,
        ], ['REMOTE_ADDR' => REVOKE_CALLER_WG_IP]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.message', 'Serving node is required.')
            ->assertJsonPath('error.meta.field', 'serving_node');
    });

    it('returns node not found for missing endpoint nodes', function (): void {
        $callerId = createRevokeCallerNode();
        $gatewayId = createRevokeGatewayNode();
        grantRevokeAccess($callerId, $gatewayId);
        DB::table('nodes')->insert(apiRevokeNodeRow(['name' => 'app-1']));

        $response = postNodeRevokeJson([
            'consuming_node' => 'missing-control',
            'serving_node' => 'app-1',
            'force' => true,
        ], ['REMOTE_ADDR' => REVOKE_CALLER_WG_IP]);

        $response->assertNotFound()
            ->assertJsonPath('error.code', 'node.not_found')
            ->assertJsonPath('error.message', "Node 'missing-control' not found.")
            ->assertJsonPath('error.meta.name', 'missing-control');
    });
});
