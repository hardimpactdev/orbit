<?php

declare(strict_types=1);

use App\Actions\Nodes\ReenactNodeArtifacts;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

const UPDATE_CALLER_WG_IP = '10.6.0.99';

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function apiUpdateNodeRow(array $overrides = []): array
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
        'public_ipv4' => null,
        'public_ipv6' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

function createUpdateCallerNode(string $role = 'control'): int
{
    return (int) DB::table('nodes')->insertGetId(apiUpdateNodeRow([
        'name' => "{$role}-caller",
        'role' => $role,
        'host' => UPDATE_CALLER_WG_IP,
        'environment' => $role === 'app' ? 'development' : null,
        'wireguard_address' => UPDATE_CALLER_WG_IP,
    ]));
}

function createUpdateGatewayNode(): int
{
    return (int) DB::table('nodes')->insertGetId(apiUpdateNodeRow([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'host' => '10.6.0.2',
        'environment' => null,
        'wireguard_address' => '10.6.0.2',
    ]));
}

function grantUpdateGatewayAccess(int $callerId, int $gatewayId): void
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
function putUpdateNodeJson(string $uri, array $data, array $server = []): TestResponse
{
    return test()->call(
        'PUT',
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

describe('NodeUpdateController', function (): void {
    it('updates a node for an authorized control caller', function (): void {
        $callerId = createUpdateCallerNode();
        $gatewayId = createUpdateGatewayNode();
        grantUpdateGatewayAccess($callerId, $gatewayId);
        DB::table('nodes')->insert(apiUpdateNodeRow());

        $response = putUpdateNodeJson('/api/nodes/app-1', [
            'host' => '10.6.0.8',
            'environment' => 'production',
            'public_ipv4' => '203.0.113.10',
        ], ['REMOTE_ADDR' => UPDATE_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJson([
                'success' => [
                    'data' => [
                        'name' => 'app-1',
                        'changed' => ['host', 'environment', 'public_ipv4'],
                        'action' => 'updated',
                    ],
                ],
            ]);

        $node = DB::table('nodes')->where('name', 'app-1')->first();

        expect($node->host)->toBe('10.6.0.8')
            ->and($node->environment)->toBe('production')
            ->and($node->public_ipv4)->toBe('203.0.113.10');
    });

    it('logs activity for a successful node update write', function (): void {
        $callerId = createUpdateCallerNode();
        $gatewayId = createUpdateGatewayNode();
        grantUpdateGatewayAccess($callerId, $gatewayId);
        $targetId = (int) DB::table('nodes')->insertGetId(apiUpdateNodeRow());

        $response = putUpdateNodeJson('/api/nodes/app-1', [
            'host' => '10.6.0.8',
            'environment' => 'production',
            'public_ipv4' => '203.0.113.10',
        ], ['REMOTE_ADDR' => UPDATE_CALLER_WG_IP]);

        $response->assertOk();

        $entry = Activity::query()->first();

        expect($entry)->not->toBeNull();
        expect($entry->event)->toBe('api:PUT /nodes/{name}');
        expect($entry->subject_type)->toBe(Node::class);
        expect($entry->subject_id)->toBe($targetId);
        expect($entry->description)->toBe('Node app-1 updated');
        expect($entry->properties->get('type'))->toBe('write');
        expect($entry->properties->get('target_node'))->toBe('app-1');
        expect($entry->properties->get('changed_fields'))->toBe(['host', 'environment', 'public_ipv4']);
    });

    it('updates a node directly for a gateway caller', function (): void {
        createUpdateCallerNode('gateway');
        DB::table('nodes')->insert(apiUpdateNodeRow());

        $response = putUpdateNodeJson('/api/nodes/app-1', [
            'host' => '10.6.0.8',
        ], ['REMOTE_ADDR' => UPDATE_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.changed', ['host']);
    });

    it('returns empty changed array for no-op updates', function (): void {
        $callerId = createUpdateCallerNode();
        $gatewayId = createUpdateGatewayNode();
        grantUpdateGatewayAccess($callerId, $gatewayId);
        DB::table('nodes')->insert(apiUpdateNodeRow());

        $response = putUpdateNodeJson('/api/nodes/app-1', [
            'host' => '10.6.0.7',
        ], ['REMOTE_ADDR' => UPDATE_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.changed', []);
    });

    it('returns success warnings when artifact re-enactment fails after intent update', function (): void {
        app()->instance(ReenactNodeArtifacts::class, new class extends ReenactNodeArtifacts
        {
            public function handle(Node $node, array $changed): array
            {
                throw new RuntimeException('artifact failed');
            }
        });

        $callerId = createUpdateCallerNode();
        $gatewayId = createUpdateGatewayNode();
        grantUpdateGatewayAccess($callerId, $gatewayId);
        DB::table('nodes')->insert(apiUpdateNodeRow());

        $response = putUpdateNodeJson('/api/nodes/app-1', [
            'host' => '10.6.0.8',
        ], ['REMOTE_ADDR' => UPDATE_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.changed', ['host'])
            ->assertJsonPath('success.meta.warnings.0.code', 'node.artifact_enactment_failed')
            ->assertJsonPath('success.meta.warnings.0.family', 'node')
            ->assertJsonPath('success.meta.warnings.0.next_command', 'doctor --fix --family=node --restore');

        expect(DB::table('nodes')->where('name', 'app-1')->value('host'))->toBe('10.6.0.8');
    });

    it('rejects unauthenticated requests', function (): void {
        DB::table('nodes')->insert(apiUpdateNodeRow());

        $response = putUpdateNodeJson('/api/nodes/app-1', ['host' => '10.6.0.8']);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.message', 'Peer identity unknown.')
            ->assertJsonPath('error.meta', []);
    });

    it('rejects app callers before mutation', function (): void {
        createUpdateCallerNode('app');
        DB::table('nodes')->insert(apiUpdateNodeRow());

        $response = putUpdateNodeJson('/api/nodes/app-1', ['host' => '10.6.0.8'], ['REMOTE_ADDR' => UPDATE_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'caller_role_not_allowed')
            ->assertJsonPath('error.message', 'This command may only be run from a control or gateway node.')
            ->assertJsonPath('error.meta.caller_role', 'app');

        expect(DB::table('nodes')->where('name', 'app-1')->value('host'))->toBe('10.6.0.7');
    });

    it('rejects control callers without gateway access', function (): void {
        createUpdateCallerNode();
        createUpdateGatewayNode();
        DB::table('nodes')->insert(apiUpdateNodeRow());

        $response = putUpdateNodeJson('/api/nodes/app-1', ['host' => '10.6.0.8'], ['REMOTE_ADDR' => UPDATE_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.message', 'This control node is not authorized to update nodes.')
            ->assertJsonPath('error.meta.required_node', 'gateway-1')
            ->assertJsonPath('error.meta.caller_role', 'control');
    });

    it('returns validation error when no fields are provided', function (): void {
        $callerId = createUpdateCallerNode();
        $gatewayId = createUpdateGatewayNode();
        grantUpdateGatewayAccess($callerId, $gatewayId);
        DB::table('nodes')->insert(apiUpdateNodeRow());

        $response = putUpdateNodeJson('/api/nodes/app-1', [], ['REMOTE_ADDR' => UPDATE_CALLER_WG_IP]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.message', 'At least one field must be provided to update a node.')
            ->assertJsonPath('error.meta.field', 'fields');
    });

    it('returns validation error for invalid environment', function (): void {
        $callerId = createUpdateCallerNode();
        $gatewayId = createUpdateGatewayNode();
        grantUpdateGatewayAccess($callerId, $gatewayId);
        DB::table('nodes')->insert(apiUpdateNodeRow());

        $response = putUpdateNodeJson('/api/nodes/app-1', ['environment' => 'staging'], ['REMOTE_ADDR' => UPDATE_CALLER_WG_IP]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.message', "Invalid value for --environment: 'staging'. Allowed values: development, production.")
            ->assertJsonPath('error.meta.field', 'environment');
    });

    it('returns validation error for role-incompatible fields', function (): void {
        $callerId = createUpdateCallerNode();
        $gatewayId = createUpdateGatewayNode();
        grantUpdateGatewayAccess($callerId, $gatewayId);

        $response = putUpdateNodeJson('/api/nodes/gateway-1', ['environment' => 'production'], ['REMOTE_ADDR' => UPDATE_CALLER_WG_IP]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'node.field_role_incompatible')
            ->assertJsonPath('error.message', "The field 'environment' is not valid for node 'gateway-1' (role: gateway).")
            ->assertJsonPath('error.meta.field', 'environment')
            ->assertJsonPath('error.meta.name', 'gateway-1')
            ->assertJsonPath('error.meta.role', 'gateway');
    });

    it('returns not found for missing nodes', function (): void {
        $callerId = createUpdateCallerNode();
        $gatewayId = createUpdateGatewayNode();
        grantUpdateGatewayAccess($callerId, $gatewayId);

        $response = putUpdateNodeJson('/api/nodes/missing-node', ['host' => '10.6.0.8'], ['REMOTE_ADDR' => UPDATE_CALLER_WG_IP]);

        $response->assertNotFound()
            ->assertJsonPath('error.code', 'node.not_found')
            ->assertJsonPath('error.message', "Node 'missing-node' not found.")
            ->assertJsonPath('error.meta.name', 'missing-node');
    });

    it('updates the development tld for an app node', function (): void {
        $callerId = createUpdateCallerNode();
        $gatewayId = createUpdateGatewayNode();
        grantUpdateGatewayAccess($callerId, $gatewayId);
        DB::table('nodes')->insert(apiUpdateNodeRow(['tld' => null]));

        $response = putUpdateNodeJson('/api/nodes/app-1', ['tld' => 'test'], ['REMOTE_ADDR' => UPDATE_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.changed', ['tld']);

        expect(DB::table('nodes')->where('name', 'app-1')->value('tld'))->toBe('test');
    });

    it('rejects an invalid tld value', function (): void {
        $callerId = createUpdateCallerNode();
        $gatewayId = createUpdateGatewayNode();
        grantUpdateGatewayAccess($callerId, $gatewayId);
        DB::table('nodes')->insert(apiUpdateNodeRow());

        $response = putUpdateNodeJson('/api/nodes/app-1', ['tld' => 'Invalid_TLD!'], ['REMOTE_ADDR' => UPDATE_CALLER_WG_IP]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'tld')
            ->assertJsonPath('error.meta.value', 'Invalid_TLD!');
    });

    it('rejects tld on a production app node as role-incompatible', function (): void {
        $callerId = createUpdateCallerNode();
        $gatewayId = createUpdateGatewayNode();
        grantUpdateGatewayAccess($callerId, $gatewayId);
        DB::table('nodes')->insert(apiUpdateNodeRow(['environment' => 'production']));

        $response = putUpdateNodeJson('/api/nodes/app-1', ['tld' => 'test'], ['REMOTE_ADDR' => UPDATE_CALLER_WG_IP]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'node.field_role_incompatible')
            ->assertJsonPath('error.meta.field', 'tld');
    });

    it('rejects tld already assigned to another active node', function (): void {
        $callerId = createUpdateCallerNode();
        $gatewayId = createUpdateGatewayNode();
        grantUpdateGatewayAccess($callerId, $gatewayId);
        DB::table('nodes')->insert(apiUpdateNodeRow(['tld' => null]));
        DB::table('nodes')->insert(apiUpdateNodeRow(['name' => 'app-2', 'tld' => 'test']));

        $response = putUpdateNodeJson('/api/nodes/app-1', ['tld' => 'test'], ['REMOTE_ADDR' => UPDATE_CALLER_WG_IP]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'node.tld_in_use')
            ->assertJsonPath('error.meta.field', 'tld')
            ->assertJsonPath('error.meta.value', 'test');
    });
});
