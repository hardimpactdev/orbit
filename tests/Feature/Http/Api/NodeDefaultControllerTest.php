<?php

declare(strict_types=1);

use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

const DEFAULT_CALLER_WG_IP = '10.6.0.99';

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function apiDefaultNodeRow(array $overrides = []): array
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

function createDefaultCallerNode(string $role = 'control'): int
{
    return (int) DB::table('nodes')->insertGetId(apiDefaultNodeRow([
        'name' => "{$role}-caller",
        'role' => $role,
        'host' => DEFAULT_CALLER_WG_IP,
        'environment' => $role === 'app' ? 'development' : null,
        'wireguard_address' => DEFAULT_CALLER_WG_IP,
    ]));
}

function grantDefaultNodeAccess(int $callerId, int $nodeId): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $callerId,
        'serving_node_id' => $nodeId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @param  array<string, mixed>  $data
 * @param  array<string, string>  $server
 */
function nodeDefaultJson(string $method, string $uri, array $data = [], array $server = []): TestResponse
{
    return test()->call(
        $method,
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

describe('NodeDefaultController', function (): void {
    it('shows the configured default node', function (): void {
        createDefaultCallerNode();
        DB::table('local_node_defaults')->insert([
            'default_node_name' => 'app-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = nodeDefaultJson('GET', '/api/nodes/default', server: ['REMOTE_ADDR' => DEFAULT_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJson([
                'success' => [
                    'data' => [
                        'action' => 'show',
                        'default_node' => [
                            'name' => 'app-1',
                            'role' => 'app',
                            'environment' => 'development',
                        ],
                    ],
                ],
            ]);
    });

    it('logs activity when showing the configured default node', function (): void {
        createDefaultCallerNode();
        DB::table('local_node_defaults')->insert([
            'default_node_name' => 'app-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = nodeDefaultJson('GET', '/api/nodes/default', server: ['REMOTE_ADDR' => DEFAULT_CALLER_WG_IP]);

        $response->assertOk();

        $entry = Activity::query()->first();

        expect($entry)->not->toBeNull();
        expect($entry->event)->toBe('api:GET /nodes/default');
        expect($entry->subject_type)->toBeNull();
        expect($entry->properties->get('type'))->toBe('read');
        expect($entry->properties->get('action'))->toBe('show');
        expect($entry->properties->get('default_node'))->toBe('app-1');
    });

    it('shows an empty default state', function (): void {
        createDefaultCallerNode();

        $response = nodeDefaultJson('GET', '/api/nodes/default', server: ['REMOTE_ADDR' => DEFAULT_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.action', 'show')
            ->assertJsonPath('success.data.default_node', null);
    });

    it('sets an authorized development app node as default', function (): void {
        $callerId = createDefaultCallerNode();
        $appId = (int) DB::table('nodes')->insertGetId(apiDefaultNodeRow());
        grantDefaultNodeAccess($callerId, $appId);

        $response = nodeDefaultJson('PUT', '/api/nodes/default', ['name' => 'app-1'], ['REMOTE_ADDR' => DEFAULT_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.action', 'set')
            ->assertJsonPath('success.data.default_node.name', 'app-1');

        expect(DB::table('local_node_defaults')->value('default_node_name'))->toBe('app-1');
    });

    it('logs activity when setting the default node', function (): void {
        $callerId = createDefaultCallerNode();
        $appId = (int) DB::table('nodes')->insertGetId(apiDefaultNodeRow());
        grantDefaultNodeAccess($callerId, $appId);

        $response = nodeDefaultJson('PUT', '/api/nodes/default', ['name' => 'app-1'], ['REMOTE_ADDR' => DEFAULT_CALLER_WG_IP]);

        $response->assertOk();

        $entry = Activity::query()->first();

        expect($entry)->not->toBeNull();
        expect($entry->event)->toBe('api:PUT /nodes/default');
        expect($entry->subject_type)->toBe(Node::class);
        expect($entry->subject_id)->toBe($appId);
        expect($entry->description)->toBe('Default node set to app-1');
        expect($entry->properties->get('type'))->toBe('write');
        expect($entry->properties->get('action'))->toBe('set');
        expect($entry->properties->get('default_node'))->toBe('app-1');
    });

    it('updates the existing default row on repeated set', function (): void {
        $callerId = createDefaultCallerNode();
        $firstId = (int) DB::table('nodes')->insertGetId(apiDefaultNodeRow(['name' => 'app-1']));
        $secondId = (int) DB::table('nodes')->insertGetId(apiDefaultNodeRow(['name' => 'app-2']));
        grantDefaultNodeAccess($callerId, $firstId);
        grantDefaultNodeAccess($callerId, $secondId);

        nodeDefaultJson('PUT', '/api/nodes/default', ['name' => 'app-1'], ['REMOTE_ADDR' => DEFAULT_CALLER_WG_IP]);
        $response = nodeDefaultJson('PUT', '/api/nodes/default', ['name' => 'app-2'], ['REMOTE_ADDR' => DEFAULT_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.default_node.name', 'app-2');

        expect(DB::table('local_node_defaults')->count())->toBe(1)
            ->and(DB::table('local_node_defaults')->value('default_node_name'))->toBe('app-2');
    });

    it('clears an existing default node', function (): void {
        createDefaultCallerNode();
        DB::table('local_node_defaults')->insert([
            'default_node_name' => 'app-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = nodeDefaultJson('DELETE', '/api/nodes/default', server: ['REMOTE_ADDR' => DEFAULT_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.action', 'clear')
            ->assertJsonPath('success.data.default_node', null)
            ->assertJsonPath('success.meta.was_set', true);

        expect(DB::table('local_node_defaults')->value('default_node_name'))->toBeNull();
    });

    it('logs activity when clearing the default node', function (): void {
        createDefaultCallerNode();
        DB::table('local_node_defaults')->insert([
            'default_node_name' => 'app-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = nodeDefaultJson('DELETE', '/api/nodes/default', server: ['REMOTE_ADDR' => DEFAULT_CALLER_WG_IP]);

        $response->assertOk();

        $entry = Activity::query()->first();

        expect($entry)->not->toBeNull();
        expect($entry->event)->toBe('api:DELETE /nodes/default');
        expect($entry->subject_type)->toBeNull();
        expect($entry->description)->toBe('Default node cleared');
        expect($entry->properties->get('type'))->toBe('write');
        expect($entry->properties->get('action'))->toBe('clear');
        expect($entry->properties->get('default_node'))->toBeNull();
    });

    it('clears when no default was set', function (): void {
        createDefaultCallerNode();

        $response = nodeDefaultJson('DELETE', '/api/nodes/default', server: ['REMOTE_ADDR' => DEFAULT_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.meta.was_set', false);
    });

    it('rejects unauthenticated requests', function (): void {
        $response = nodeDefaultJson('GET', '/api/nodes/default');

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.message', 'Peer identity unknown.');
    });

    it('rejects non-control callers', function (string $role): void {
        createDefaultCallerNode($role);

        $response = nodeDefaultJson('GET', '/api/nodes/default', server: ['REMOTE_ADDR' => DEFAULT_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'caller_role_not_allowed')
            ->assertJsonPath('error.message', 'This command may only be run from a control node.')
            ->assertJsonPath('error.meta.caller_role', $role);
    })->with(['app', 'gateway']);

    it('rejects set with missing name', function (): void {
        createDefaultCallerNode();

        $response = nodeDefaultJson('PUT', '/api/nodes/default', [], ['REMOTE_ADDR' => DEFAULT_CALLER_WG_IP]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.message', 'Node name is required.')
            ->assertJsonPath('error.meta.field', 'name');
    });

    it('rejects missing target nodes', function (): void {
        createDefaultCallerNode();

        $response = nodeDefaultJson('PUT', '/api/nodes/default', ['name' => 'missing'], ['REMOTE_ADDR' => DEFAULT_CALLER_WG_IP]);

        $response->assertNotFound()
            ->assertJsonPath('error.code', 'node.not_found')
            ->assertJsonPath('error.message', "Node 'missing' not found or not visible.")
            ->assertJsonPath('error.meta.name', 'missing');
    });

    it('rejects non-development app targets', function (): void {
        $callerId = createDefaultCallerNode();
        $prodId = (int) DB::table('nodes')->insertGetId(apiDefaultNodeRow([
            'name' => 'prod-1',
            'environment' => 'production',
        ]));
        grantDefaultNodeAccess($callerId, $prodId);

        $response = nodeDefaultJson('PUT', '/api/nodes/default', ['name' => 'prod-1'], ['REMOTE_ADDR' => DEFAULT_CALLER_WG_IP]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'node.invalid_role')
            ->assertJsonPath('error.message', "Node 'prod-1' is not a development app node.")
            ->assertJsonPath('error.meta.required_role', 'app')
            ->assertJsonPath('error.meta.required_environment', 'development');
    });

    it('rejects unauthorized target nodes', function (): void {
        createDefaultCallerNode();
        DB::table('nodes')->insert(apiDefaultNodeRow());

        $response = nodeDefaultJson('PUT', '/api/nodes/default', ['name' => 'app-1'], ['REMOTE_ADDR' => DEFAULT_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.message', "This node is not authorized to operate on 'app-1'.")
            ->assertJsonPath('error.meta.name', 'app-1')
            ->assertJsonPath('error.meta.caller_role', 'control');
    });
});
