<?php

declare(strict_types=1);

use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

const GRANT_CALLER_WG_IP = '10.6.0.99';

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function apiGrantNodeRow(array $overrides = []): array
{
    return array_merge([
        'name' => 'app-1',
        'role' => 'app',
        'host' => '10.6.0.7',
        'orbit_path' => '/home/nckrtl/orbit',
        'status' => 'active',
        'environment' => 'development',
        'platform' => 'ubuntu_24-04',
        'wireguard_address' => '10.6.0.7',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

function createGrantCallerNode(string $role = 'control'): int
{
    return (int) DB::table('nodes')->insertGetId(apiGrantNodeRow([
        'name' => "{$role}-caller",
        'role' => $role,
        'host' => GRANT_CALLER_WG_IP,
        'environment' => $role === 'app' ? 'development' : null,
        'wireguard_address' => GRANT_CALLER_WG_IP,
    ]));
}

function createGrantGatewayNode(): int
{
    return (int) DB::table('nodes')->insertGetId(apiGrantNodeRow([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'host' => '10.6.0.2',
        'environment' => null,
        'wireguard_address' => '10.6.0.2',
    ]));
}

function grantGatewayManagementAccess(int $callerId, int $gatewayId): void
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
function postNodeGrantJson(array $data, array $server = []): TestResponse
{
    return test()->call(
        'POST',
        '/api/nodes/grant',
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

describe('NodeGrantController', function (): void {
    it('creates a grant for an authorized control caller', function (): void {
        $callerId = createGrantCallerNode();
        $gatewayId = createGrantGatewayNode();
        grantGatewayManagementAccess($callerId, $gatewayId);
        $consumingId = (int) DB::table('nodes')->insertGetId(apiGrantNodeRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
            'wireguard_address' => '10.6.0.11',
        ]));
        $servingId = (int) DB::table('nodes')->insertGetId(apiGrantNodeRow([
            'name' => 'app-1',
            'wireguard_address' => '10.6.0.12',
        ]));

        $response = postNodeGrantJson([
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
        ], ['REMOTE_ADDR' => GRANT_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJson([
                'success' => [
                    'data' => [
                        'consuming_node' => 'control-1',
                        'serving_node' => 'app-1',
                        'action' => 'granted',
                        'already_granted' => false,
                    ],
                ],
            ]);

        expect(DB::table('node_access')
            ->where('consumer_node_id', $consumingId)
            ->where('serving_node_id', $servingId)
            ->exists())->toBeTrue();
    });

    it('logs activity for a successful grant write', function (): void {
        $callerId = createGrantCallerNode();
        $gatewayId = createGrantGatewayNode();
        grantGatewayManagementAccess($callerId, $gatewayId);
        DB::table('nodes')->insert(apiGrantNodeRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
            'wireguard_address' => '10.6.0.11',
        ]));
        $servingId = (int) DB::table('nodes')->insertGetId(apiGrantNodeRow([
            'name' => 'app-1',
            'wireguard_address' => '10.6.0.12',
        ]));

        $response = postNodeGrantJson([
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
        ], ['REMOTE_ADDR' => GRANT_CALLER_WG_IP]);

        $response->assertOk();

        $entry = Activity::query()->first();

        expect($entry)->not->toBeNull();
        expect($entry->event)->toBe('api:POST /nodes/grant');
        expect($entry->subject_type)->toBe(Node::class);
        expect($entry->subject_id)->toBe($servingId);
        expect($entry->description)->toBe('control-1 granted access to app-1');
        expect($entry->properties->get('type'))->toBe('write');
        expect($entry->properties->get('consuming_node'))->toBe('control-1');
        expect($entry->properties->get('serving_node'))->toBe('app-1');
    });

    it('creates a grant directly for a gateway caller', function (): void {
        createGrantCallerNode('gateway');
        $consumingId = (int) DB::table('nodes')->insertGetId(apiGrantNodeRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
            'wireguard_address' => '10.6.0.11',
        ]));
        $servingId = (int) DB::table('nodes')->insertGetId(apiGrantNodeRow([
            'name' => 'app-1',
            'wireguard_address' => '10.6.0.12',
        ]));

        $response = postNodeGrantJson([
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
        ], ['REMOTE_ADDR' => GRANT_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.already_granted', false);

        expect(DB::table('node_access')
            ->where('consumer_node_id', $consumingId)
            ->where('serving_node_id', $servingId)
            ->exists())->toBeTrue();
    });

    it('returns idempotent success when the grant already exists', function (): void {
        $callerId = createGrantCallerNode();
        $gatewayId = createGrantGatewayNode();
        grantGatewayManagementAccess($callerId, $gatewayId);
        $consumingId = (int) DB::table('nodes')->insertGetId(apiGrantNodeRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
            'wireguard_address' => '10.6.0.11',
        ]));
        $servingId = (int) DB::table('nodes')->insertGetId(apiGrantNodeRow([
            'name' => 'app-1',
            'wireguard_address' => '10.6.0.12',
        ]));

        DB::table('node_access')->insert([
            'consumer_node_id' => $consumingId,
            'serving_node_id' => $servingId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = postNodeGrantJson([
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
        ], ['REMOTE_ADDR' => GRANT_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.action', 'granted')
            ->assertJsonPath('success.data.already_granted', true);

        expect(DB::table('node_access')
            ->where('consumer_node_id', $consumingId)
            ->where('serving_node_id', $servingId)
            ->count())->toBe(1);
    });

    it('rejects unauthenticated requests', function (): void {
        $response = postNodeGrantJson([
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
        ]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.message', 'Peer identity unknown.')
            ->assertJsonPath('error.meta', []);
    });

    it('rejects app callers before mutation', function (): void {
        createGrantCallerNode('app');
        DB::table('nodes')->insert(apiGrantNodeRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
            'wireguard_address' => '10.6.0.11',
        ]));
        DB::table('nodes')->insert(apiGrantNodeRow([
            'name' => 'app-1',
            'wireguard_address' => '10.6.0.12',
        ]));

        $response = postNodeGrantJson([
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
        ], ['REMOTE_ADDR' => GRANT_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'caller_role_not_allowed')
            ->assertJsonPath('error.message', 'This command may only be run from a control or gateway node.')
            ->assertJsonPath('error.meta.caller_role', 'app');

        expect(DB::table('node_access')->count())->toBe(0);
    });

    it('rejects control callers without gateway access before mutation', function (): void {
        createGrantCallerNode();
        createGrantGatewayNode();
        DB::table('nodes')->insert(apiGrantNodeRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
            'wireguard_address' => '10.6.0.11',
        ]));
        DB::table('nodes')->insert(apiGrantNodeRow([
            'name' => 'app-1',
            'wireguard_address' => '10.6.0.12',
        ]));

        $response = postNodeGrantJson([
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
        ], ['REMOTE_ADDR' => GRANT_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.message', 'This control node is not authorized to grant node access.')
            ->assertJsonPath('error.meta.required_node', 'gateway-1')
            ->assertJsonPath('error.meta.caller_role', 'control');

        expect(DB::table('node_access')->count())->toBe(0);
    });

    it('rejects missing consuming node input', function (): void {
        $callerId = createGrantCallerNode();
        $gatewayId = createGrantGatewayNode();
        grantGatewayManagementAccess($callerId, $gatewayId);

        $response = postNodeGrantJson([
            'serving_node' => 'app-1',
        ], ['REMOTE_ADDR' => GRANT_CALLER_WG_IP]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.message', 'Consuming node is required.')
            ->assertJsonPath('error.meta.field', 'consuming_node');
    });

    it('rejects missing serving node input', function (): void {
        $callerId = createGrantCallerNode();
        $gatewayId = createGrantGatewayNode();
        grantGatewayManagementAccess($callerId, $gatewayId);

        $response = postNodeGrantJson([
            'consuming_node' => 'control-1',
        ], ['REMOTE_ADDR' => GRANT_CALLER_WG_IP]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.message', 'Serving node is required.')
            ->assertJsonPath('error.meta.field', 'serving_node');
    });

    it('rejects missing consuming nodes as not found', function (): void {
        $callerId = createGrantCallerNode();
        $gatewayId = createGrantGatewayNode();
        grantGatewayManagementAccess($callerId, $gatewayId);
        DB::table('nodes')->insert(apiGrantNodeRow(['name' => 'app-1']));

        $response = postNodeGrantJson([
            'consuming_node' => 'missing-control',
            'serving_node' => 'app-1',
        ], ['REMOTE_ADDR' => GRANT_CALLER_WG_IP]);

        $response->assertNotFound()
            ->assertJsonPath('error.code', 'node.not_found')
            ->assertJsonPath('error.message', "Consuming node 'missing-control' not found.")
            ->assertJsonPath('error.meta.field', 'consuming_node')
            ->assertJsonPath('error.meta.name', 'missing-control');
    });

    it('rejects provisioning serving nodes as not found', function (): void {
        $callerId = createGrantCallerNode();
        $gatewayId = createGrantGatewayNode();
        grantGatewayManagementAccess($callerId, $gatewayId);
        DB::table('nodes')->insert(apiGrantNodeRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
            'wireguard_address' => '10.6.0.11',
        ]));
        DB::table('nodes')->insert(apiGrantNodeRow([
            'name' => 'app-1',
            'status' => 'provisioning',
            'wireguard_address' => '10.6.0.12',
        ]));

        $response = postNodeGrantJson([
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
        ], ['REMOTE_ADDR' => GRANT_CALLER_WG_IP]);

        $response->assertNotFound()
            ->assertJsonPath('error.code', 'node.not_found')
            ->assertJsonPath('error.message', "Serving node 'app-1' not found.")
            ->assertJsonPath('error.meta.field', 'serving_node')
            ->assertJsonPath('error.meta.name', 'app-1');

        expect(DB::table('node_access')->count())->toBe(1);
    });

    it('rejects self-grants as policy violations', function (): void {
        $callerId = createGrantCallerNode();
        $gatewayId = createGrantGatewayNode();
        grantGatewayManagementAccess($callerId, $gatewayId);
        DB::table('nodes')->insert(apiGrantNodeRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
            'wireguard_address' => '10.6.0.11',
        ]));

        $response = postNodeGrantJson([
            'consuming_node' => 'control-1',
            'serving_node' => 'control-1',
        ], ['REMOTE_ADDR' => GRANT_CALLER_WG_IP]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'node.grant_policy_violation')
            ->assertJsonPath('error.message', 'A node cannot be granted access to itself.')
            ->assertJsonPath('error.meta.consuming_node', 'control-1')
            ->assertJsonPath('error.meta.serving_node', 'control-1')
            ->assertJsonPath('error.meta.reason', 'self_grant');

        expect(DB::table('node_access')->count())->toBe(1);
    });
});
