<?php

declare(strict_types=1);

use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

const REMOVE_CALLER_WG_IP = '10.6.0.99';

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function apiRemoveNodeRow(array $overrides = []): array
{
    return array_merge([
        'name' => 'app-1',
        'role' => 'app',
        'host' => '10.6.0.7',
        'ssh_user' => 'nckrtl',
        'user' => 'nckrtl',
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

function createRemoveCallerNode(string $role = 'control'): int
{
    return (int) DB::table('nodes')->insertGetId(apiRemoveNodeRow([
        'name' => "{$role}-caller",
        'role' => $role,
        'host' => REMOVE_CALLER_WG_IP,
        'environment' => $role === 'app' ? 'development' : null,
        'wireguard_address' => REMOVE_CALLER_WG_IP,
    ]));
}

function createRemoveGatewayNode(): int
{
    return (int) DB::table('nodes')->insertGetId(apiRemoveNodeRow([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'host' => '10.6.0.2',
        'environment' => null,
        'wireguard_address' => '10.6.0.2',
    ]));
}

function grantRemoveGatewayAccess(int $callerId, int $gatewayId): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $callerId,
        'serving_node_id' => $gatewayId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function grantRemoveNodeAccess(int $consumerId, int $servingId): void
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
function deleteRemoveNodeJson(string $uri, array $data = [], array $server = []): TestResponse
{
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

describe('NodeRemoveController', function (): void {
    it('removes a node for an authorized control caller', function (): void {
        $callerId = createRemoveCallerNode();
        $gatewayId = createRemoveGatewayNode();
        grantRemoveGatewayAccess($callerId, $gatewayId);
        $targetId = (int) DB::table('nodes')->insertGetId(apiRemoveNodeRow());
        $otherId = (int) DB::table('nodes')->insertGetId(apiRemoveNodeRow([
            'name' => 'app-2',
            'wireguard_address' => '10.6.0.8',
        ]));
        grantRemoveNodeAccess($callerId, $targetId);
        grantRemoveNodeAccess($targetId, $gatewayId);
        grantRemoveNodeAccess($callerId, $otherId);

        $response = deleteRemoveNodeJson('/api/nodes/app-1', [
            'destructive_consent' => true,
            'destructive_consent_source' => 'force',
        ], ['REMOTE_ADDR' => REMOVE_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJson([
                'success' => [
                    'data' => [
                        'name' => 'app-1',
                        'action' => 'removed',
                        'removed_self' => false,
                        'wireguard_peer_removed' => false,
                        'grants_removed' => 2,
                    ],
                ],
            ]);

        expect(DB::table('nodes')->where('name', 'app-1')->exists())->toBeFalse()
            ->and(DB::table('node_access')->count())->toBe(2)
            ->and(DB::table('node_access')
                ->where('consumer_node_id', $callerId)
                ->where('serving_node_id', $otherId)
                ->exists())->toBeTrue();
    });

    it('logs activity for a successful node removal', function (): void {
        $callerId = createRemoveCallerNode();
        $gatewayId = createRemoveGatewayNode();
        grantRemoveGatewayAccess($callerId, $gatewayId);
        $targetId = (int) DB::table('nodes')->insertGetId(apiRemoveNodeRow());
        grantRemoveNodeAccess($callerId, $targetId);
        grantRemoveNodeAccess($targetId, $gatewayId);

        $response = deleteRemoveNodeJson('/api/nodes/app-1', [
            'destructive_consent' => true,
            'destructive_consent_source' => 'force',
        ], ['REMOTE_ADDR' => REMOVE_CALLER_WG_IP]);

        $response->assertOk();

        $entry = Activity::query()->first();

        expect($entry)->not->toBeNull();
        expect($entry->event)->toBe('api:DELETE /nodes/{name}');
        expect($entry->subject_type)->toBe(Node::class);
        expect($entry->subject_id)->toBe($targetId);
        expect($entry->description)->toBe('Node app-1 removed');
        expect($entry->properties->get('type'))->toBe('destructive');
        expect($entry->properties->get('target_node'))->toBe('app-1');
        expect($entry->properties->get('removed_self'))->toBeFalse();
        expect($entry->properties->get('grants_removed'))->toBe(2);
        expect($entry->properties->get('wireguard_peer_removed'))->toBeFalse();
    });

    it('removes the authenticated control caller and marks removed_self', function (): void {
        $callerId = createRemoveCallerNode();
        $gatewayId = createRemoveGatewayNode();
        grantRemoveGatewayAccess($callerId, $gatewayId);

        $response = deleteRemoveNodeJson('/api/nodes/control-caller', [
            'destructive_consent' => true,
            'destructive_consent_source' => 'interactive_confirm',
        ], ['REMOTE_ADDR' => REMOVE_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.name', 'control-caller')
            ->assertJsonPath('success.data.action', 'removed')
            ->assertJsonPath('success.data.removed_self', true)
            ->assertJsonPath('success.data.wireguard_peer_removed', false)
            ->assertJsonPath('success.data.grants_removed', 1);

        expect(DB::table('nodes')->where('name', 'control-caller')->exists())->toBeFalse()
            ->and(DB::table('nodes')->where('name', 'gateway-1')->exists())->toBeTrue();
    });

    it('removes a node directly for a gateway caller', function (): void {
        createRemoveCallerNode('gateway');
        DB::table('nodes')->insert(apiRemoveNodeRow());

        $response = deleteRemoveNodeJson('/api/nodes/app-1', [
            'destructive_consent' => true,
        ], ['REMOTE_ADDR' => REMOVE_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.name', 'app-1')
            ->assertJsonPath('success.data.grants_removed', 0);

        expect(DB::table('nodes')->where('name', 'app-1')->exists())->toBeFalse();
    });

    it('rejects unauthenticated requests', function (): void {
        DB::table('nodes')->insert(apiRemoveNodeRow());

        $response = deleteRemoveNodeJson('/api/nodes/app-1', [
            'destructive_consent' => true,
        ]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.message', 'Peer identity unknown.')
            ->assertJsonPath('error.meta', []);

        expect(DB::table('nodes')->where('name', 'app-1')->exists())->toBeTrue();
    });

    it('rejects app callers before mutation', function (): void {
        createRemoveCallerNode('app');
        DB::table('nodes')->insert(apiRemoveNodeRow());

        $response = deleteRemoveNodeJson('/api/nodes/app-1', [
            'destructive_consent' => true,
        ], ['REMOTE_ADDR' => REMOVE_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'caller_role_not_allowed')
            ->assertJsonPath('error.message', 'This command may only be run from a control or gateway node.')
            ->assertJsonPath('error.meta.caller_role', 'app');

        expect(DB::table('nodes')->where('name', 'app-1')->exists())->toBeTrue();
    });

    it('rejects control callers without gateway access before mutation', function (): void {
        createRemoveCallerNode();
        createRemoveGatewayNode();
        DB::table('nodes')->insert(apiRemoveNodeRow());

        $response = deleteRemoveNodeJson('/api/nodes/app-1', [
            'destructive_consent' => true,
        ], ['REMOTE_ADDR' => REMOVE_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.message', 'This control node is not authorized to remove nodes.')
            ->assertJsonPath('error.meta.required_node', 'gateway-1')
            ->assertJsonPath('error.meta.caller_role', 'control');

        expect(DB::table('nodes')->where('name', 'app-1')->exists())->toBeTrue();
    });

    it('rejects missing or false destructive consent before mutation', function (array $data): void {
        $callerId = createRemoveCallerNode();
        $gatewayId = createRemoveGatewayNode();
        grantRemoveGatewayAccess($callerId, $gatewayId);
        DB::table('nodes')->insert(apiRemoveNodeRow());

        $response = deleteRemoveNodeJson('/api/nodes/app-1', $data, ['REMOTE_ADDR' => REMOVE_CALLER_WG_IP]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.message', 'Use --force to remove this node.')
            ->assertJsonPath('error.meta.field', 'force');

        expect(DB::table('nodes')->where('name', 'app-1')->exists())->toBeTrue();
    })->with([
        'missing consent' => [[]],
        'false consent' => [['destructive_consent' => false]],
    ]);

    it('returns node.not_found for missing active nodes', function (): void {
        $callerId = createRemoveCallerNode();
        $gatewayId = createRemoveGatewayNode();
        grantRemoveGatewayAccess($callerId, $gatewayId);

        $response = deleteRemoveNodeJson('/api/nodes/missing-node', [
            'destructive_consent' => true,
        ], ['REMOTE_ADDR' => REMOVE_CALLER_WG_IP]);

        $response->assertNotFound()
            ->assertJsonPath('error.code', 'node.not_found')
            ->assertJsonPath('error.message', "Node 'missing-node' not found.")
            ->assertJsonPath('error.meta.name', 'missing-node');
    });

    it('rejects gateway node removal before mutation', function (): void {
        $callerId = createRemoveCallerNode();
        $gatewayId = createRemoveGatewayNode();
        grantRemoveGatewayAccess($callerId, $gatewayId);

        $response = deleteRemoveNodeJson('/api/nodes/gateway-1', [
            'destructive_consent' => true,
        ], ['REMOTE_ADDR' => REMOVE_CALLER_WG_IP]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'node.gateway_removal_denied')
            ->assertJsonPath('error.message', 'The gateway node cannot be removed with this command.')
            ->assertJsonPath('error.meta.name', 'gateway-1')
            ->assertJsonPath('error.meta.role', 'gateway');

        expect(DB::table('nodes')->where('id', $gatewayId)->exists())->toBeTrue();
    });
});
