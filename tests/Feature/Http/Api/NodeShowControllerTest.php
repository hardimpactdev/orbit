<?php

declare(strict_types=1);

use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

uses(RefreshDatabase::class);

const SHOW_CALLER_WG_IP = '10.6.0.99';

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function apiShowNodeRow(array $overrides = []): array
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
        'agent_ide_config' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

function createShowCallerNode(): void
{
    DB::table('nodes')->insert([
        'name' => 'caller',
        'role' => 'control',
        'host' => SHOW_CALLER_WG_IP,
        'orbit_path' => '/home/test/orbit',
        'status' => 'active',
        'wireguard_address' => SHOW_CALLER_WG_IP,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function assignApiShowNodeRole(string $nodeName, string $role, array $settings = []): void
{
    DB::table('node_roles')->insert([
        'node_id' => DB::table('nodes')->where('name', $nodeName)->value('id'),
        'role' => $role,
        'status' => 'active',
        'settings' => json_encode($settings, JSON_THROW_ON_ERROR),
        'last_error' => null,
        'converged_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @param  array<string, string>  $server
 */
function getApiNodeJson(string $uri, array $server = []): TestResponse
{
    /** @var TestCase $test */
    // @phpstan-ignore-next-line varTag.nativeType
    $test = test();

    return $test->call(
        'GET',
        $uri,
        [],
        [],
        [],
        array_merge([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $server),
    );
}

describe('NodeShowController', function (): void {
    beforeEach(function (): void {
        createShowCallerNode();
    });

    it('returns a single node by name', function (): void {
        DB::table('nodes')->insert([
            apiShowNodeRow([
                'name' => 'app-1',
                'role' => 'app',
                'environment' => 'development',
                'platform' => 'ubuntu_24-04',
                'status' => 'active',
            ]),
        ]);
        assignApiShowNodeRole('app-1', 'app-development', ['tld' => 'test']);

        $response = getApiNodeJson('/api/nodes/app-1', ['REMOTE_ADDR' => SHOW_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJson([
                'success' => [
                    'data' => [
                        'node' => [
                            'name' => 'app-1',
                            'role' => 'app',
                            'status' => 'active',
                            'environment' => 'development',
                            'platform' => 'ubuntu_24-04',
                            'roles' => [
                                [
                                    'role' => 'app-development',
                                    'status' => 'active',
                                    'settings' => ['tld' => 'test'],
                                ],
                            ],
                            'addresses' => [
                                'wireguard' => '10.6.0.7',
                            ],
                            'agent_ide' => [
                                'adapter' => null,
                                'source' => 'default',
                            ],
                            'grants' => [
                                'consuming_nodes' => [],
                                'serving_nodes' => [],
                            ],
                        ],
                    ],
                ],
            ]);
    });

    it('logs activity for a successful node registry read', function (): void {
        DB::table('nodes')->insert([
            apiShowNodeRow([
                'name' => 'app-1',
                'role' => 'app',
            ]),
        ]);

        $response = getApiNodeJson('/api/nodes/app-1', ['REMOTE_ADDR' => SHOW_CALLER_WG_IP]);

        $response->assertOk();

        $entry = Activity::query()->first();

        expect($entry)->not->toBeNull();
        expect($entry->log_name)->toBe('api');
        expect($entry->event)->toBe('api:GET /nodes/{name}');
        expect($entry->subject_type)->toBe(Node::class);
        expect($entry->subject_id)->toBe(DB::table('nodes')->where('name', 'app-1')->value('id'));
        expect($entry->properties->get('type'))->toBe('read');
        expect($entry->properties->get('method'))->toBe('GET');
        expect($entry->properties->get('path'))->toBe('api/nodes/app-1');
    });

    it('returns 404 for non-existent node', function (): void {
        $response = getApiNodeJson('/api/nodes/non-existent', ['REMOTE_ADDR' => SHOW_CALLER_WG_IP]);

        $response->assertNotFound()
            ->assertJson([
                'error' => [
                    'code' => 'node.not_found',
                    'message' => "Node 'non-existent' not found or not visible.",
                    'meta' => [
                        'name' => 'non-existent',
                    ],
                ],
            ]);
    });

    it('returns null environment for non-app nodes', function (): void {
        DB::table('nodes')->insert([
            apiShowNodeRow([
                'name' => 'gateway-1',
                'role' => 'gateway',
                'environment' => null,
            ]),
        ]);

        $response = getApiNodeJson('/api/nodes/gateway-1', ['REMOTE_ADDR' => SHOW_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.node.environment', null);
    });

    it('derives environment from active app role assignments', function (): void {
        DB::table('nodes')->insert([
            apiShowNodeRow([
                'name' => 'control-app',
                'role' => 'control',
                'environment' => null,
            ]),
        ]);
        assignApiShowNodeRole('control-app', 'app-production');

        $response = getApiNodeJson('/api/nodes/control-app', ['REMOTE_ADDR' => SHOW_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.node.environment', 'production');
    });

    it('defaults platform to unknown when not set', function (): void {
        DB::table('nodes')->insert([
            apiShowNodeRow([
                'name' => 'app-1',
                'platform' => null,
            ]),
        ]);

        $response = getApiNodeJson('/api/nodes/app-1', ['REMOTE_ADDR' => SHOW_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.node.platform', 'unknown');
    });

    it('falls back to host when wireguard_address is missing', function (): void {
        DB::table('nodes')->insert([
            apiShowNodeRow([
                'name' => 'app-1',
                'wireguard_address' => null,
                'host' => '192.168.1.1',
            ]),
        ]);

        $response = getApiNodeJson('/api/nodes/app-1', ['REMOTE_ADDR' => SHOW_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.node.addresses.wireguard', '192.168.1.1');
    });

    it('returns correct node shape for gateway node', function (): void {
        DB::table('nodes')->insert([
            apiShowNodeRow([
                'name' => 'gateway-1',
                'role' => 'gateway',
                'environment' => null,
                'platform' => 'ubuntu_24-04',
                'status' => 'active',
                'wireguard_address' => '10.6.0.2',
            ]),
        ]);

        $response = getApiNodeJson('/api/nodes/gateway-1', ['REMOTE_ADDR' => SHOW_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJson([
                'success' => [
                    'data' => [
                        'node' => [
                            'name' => 'gateway-1',
                            'role' => 'gateway',
                            'status' => 'active',
                            'environment' => null,
                            'platform' => 'ubuntu_24-04',
                            'roles' => [],
                            'addresses' => [
                                'wireguard' => '10.6.0.2',
                            ],
                            'agent_ide' => [
                                'adapter' => null,
                                'source' => 'default',
                            ],
                            'grants' => [
                                'consuming_nodes' => [],
                                'serving_nodes' => [],
                            ],
                        ],
                    ],
                ],
            ]);
    });

    it('returns explicit node agent IDE defaults', function (): void {
        DB::table('nodes')->insert([
            apiShowNodeRow([
                'agent_ide_config' => json_encode(['adapter' => 'polyscope'], JSON_THROW_ON_ERROR),
            ]),
        ]);

        $response = getApiNodeJson('/api/nodes/app-1', ['REMOTE_ADDR' => SHOW_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.node.agent_ide.adapter', 'polyscope')
            ->assertJsonPath('success.data.node.agent_ide.source', 'node');
    });

    it('returns real grants data', function (): void {
        DB::table('nodes')->insert([
            apiShowNodeRow([
                'name' => 'app-1',
                'role' => 'app',
            ]),
            apiShowNodeRow([
                'name' => 'control-1',
                'role' => 'control',
                'environment' => null,
            ]),
            apiShowNodeRow([
                'name' => 'control-2',
                'role' => 'control',
                'environment' => null,
            ]),
        ]);

        $app1Id = DB::table('nodes')->where('name', 'app-1')->value('id');
        $control1Id = DB::table('nodes')->where('name', 'control-1')->value('id');
        $control2Id = DB::table('nodes')->where('name', 'control-2')->value('id');

        DB::table('node_access')->insert([
            ['consumer_node_id' => $control1Id, 'serving_node_id' => $app1Id, 'created_at' => now(), 'updated_at' => now()],
            ['consumer_node_id' => $control2Id, 'serving_node_id' => $app1Id, 'created_at' => now(), 'updated_at' => now()],
            ['consumer_node_id' => $app1Id, 'serving_node_id' => $control1Id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $response = getApiNodeJson('/api/nodes/app-1', ['REMOTE_ADDR' => SHOW_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.node.grants.consuming_nodes', ['control-1', 'control-2'])
            ->assertJsonPath('success.data.node.grants.serving_nodes', ['control-1']);
    });

    it('rejects unauthenticated requests', function (): void {
        DB::table('nodes')->insert([
            apiShowNodeRow([
                'name' => 'existing-app',
                'role' => 'app',
            ]),
        ]);

        $response = getApiNodeJson('/api/nodes/existing-app');

        $response->assertForbidden()
            ->assertJson([
                'error' => [
                    'code' => 'authorization_failed',
                    'message' => 'Peer identity unknown.',
                    'meta' => [],
                ],
            ]);
    });
});
