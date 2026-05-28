<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\call;
use function Pest\Laravel\getJson;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function meNodeRow(array $overrides = []): array
{
    return array_merge([
        'name' => 'peer-1',
        'host' => '10.6.0.8',
        'wireguard_address' => '10.6.0.8',
        'orbit_path' => '/Users/nckrtl/orbit',
        'status' => 'active',
        'platform' => 'macos_15-4',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

function assignMeGatewayRole(int $nodeId): void
{
    NodeRoleAssignment::factory()->create([
        'node_id' => $nodeId,
        'role' => 'gateway',
        'status' => 'active',
        'settings' => [],
    ]);
}

describe('GET /api/me', function (): void {
    it('returns 403 for unknown peer', function (): void {
        $response = getJson('/api/me');

        $response->assertForbidden()
            ->assertExactJson([
                'error' => [
                    'code' => 'authorization_failed',
                    'message' => 'Peer identity unknown.',
                    'meta' => [],
                ],
            ]);
    });

    it('returns success shape for peer node via wireguard ip', function (): void {
        DB::table('nodes')->insert(meNodeRow());
        $gatewayId = (int) DB::table('nodes')->insertGetId(meNodeRow([
            'name' => 'gateway-1',
            'wireguard_address' => '10.6.0.2',
            'platform' => 'ubuntu_24-04',
        ]));
        assignMeGatewayRole($gatewayId);

        $response = call('GET', '/api/me', [], [], [], ['REMOTE_ADDR' => '10.6.0.8']);

        $response->assertOk()
            ->assertExactJson([
                'success' => [
                    'data' => [
                        'self' => [
                            'name' => 'peer-1',
                            'status' => 'active',
                            'platform' => 'macos_15-4',
                            'roles' => [],
                            'addresses' => [
                                'wireguard' => '10.6.0.8',
                            ],
                        ],
                        'gateway' => [
                            'name' => 'gateway-1',
                            'status' => 'active',
                            'platform' => 'ubuntu_24-04',
                            'roles' => [
                                [
                                    'status' => 'active',
                                    'settings' => [],
                                ],
                            ],
                            'addresses' => [
                                'wireguard' => '10.6.0.2',
                            ],
                        ],
                    ],
                ],
            ]);
    });

    it('returns success shape for gateway-local node via wireguard ip', function (): void {
        $gatewayId = (int) DB::table('nodes')->insertGetId(meNodeRow([
            'name' => 'gateway-1',
            'wireguard_address' => '10.6.0.2',
            'platform' => 'ubuntu_24-04',
        ]));
        assignMeGatewayRole($gatewayId);

        $response = call('GET', '/api/me', [], [], [], ['REMOTE_ADDR' => '10.6.0.2']);

        $response->assertOk()
            ->assertExactJson([
                'success' => [
                    'data' => [
                        'self' => [
                            'name' => 'gateway-1',
                            'status' => 'active',
                            'platform' => 'ubuntu_24-04',
                            'roles' => [
                                [
                                    'status' => 'active',
                                    'settings' => [],
                                ],
                            ],
                            'addresses' => [
                                'wireguard' => '10.6.0.2',
                            ],
                        ],
                        'gateway' => [
                            'name' => 'gateway-1',
                            'status' => 'active',
                            'platform' => 'ubuntu_24-04',
                            'roles' => [
                                [
                                    'status' => 'active',
                                    'settings' => [],
                                ],
                            ],
                            'addresses' => [
                                'wireguard' => '10.6.0.2',
                            ],
                        ],
                    ],
                ],
            ]);
    });

    it('defaults platform to unknown and status to active when null', function (): void {
        DB::table('nodes')->insert(array_merge(meNodeRow(), [
            'platform' => null,
        ]));
        $gatewayId = (int) DB::table('nodes')->insertGetId(meNodeRow([
            'name' => 'gateway-1',
            'wireguard_address' => '10.6.0.2',
            'platform' => 'ubuntu_24-04',
        ]));
        assignMeGatewayRole($gatewayId);

        $response = call('GET', '/api/me', [], [], [], ['REMOTE_ADDR' => '10.6.0.8']);

        $response->assertOk()
            ->assertJsonPath('success.data.self.platform', 'unknown')
            ->assertJsonPath('success.data.self.status', 'active');
    });

    it('derives environment from active app role assignments', function (): void {
        $appId = (int) DB::table('nodes')->insertGetId(meNodeRow([
            'name' => 'app-1',
            'wireguard_address' => '10.6.0.9',
        ]));
        $gatewayId = (int) DB::table('nodes')->insertGetId(meNodeRow([
            'name' => 'gateway-1',
            'wireguard_address' => '10.6.0.2',
        ]));
        assignMeGatewayRole($gatewayId);
        NodeRoleAssignment::factory()->create([
            'node_id' => $appId,
            'role' => 'app-dev',
            'status' => 'active',
            'settings' => ['tld' => 'test'],
        ]);

        $response = call('GET', '/api/me', [], [], [], ['REMOTE_ADDR' => '10.6.0.9']);

        $response->assertOk()
            ->assertJsonPath('success.data.self.environment', 'development')
            ->assertJsonPath('success.data.gateway.environment', null);
    });

    it('ignores legacy app environment without an active app role assignment', function (): void {
        DB::table('nodes')->insert(meNodeRow([
            'name' => 'app-1',
            'wireguard_address' => '10.6.0.9',
        ]));
        DB::table('nodes')->insert(meNodeRow([
            'name' => 'gateway-1',
            'wireguard_address' => '10.6.0.2',
        ]));

        $response = call('GET', '/api/me', [], [], [], ['REMOTE_ADDR' => '10.6.0.9']);

        $response->assertOk()
            ->assertJsonPath('success.data.self.environment', null);
    });

    it('serializes composable roles for self and gateway', function (): void {
        $self = Node::factory()->create([
            'name' => 'peer-1',

            'host' => '10.6.0.8',
            'wireguard_address' => '10.6.0.8',
            'orbit_path' => '/Users/nckrtl/orbit',
            'status' => 'active',
            'platform' => 'macos_15-4',
        ]);

        $gateway = Node::factory()->create([
            'name' => 'gateway-1',

            'host' => '10.6.0.2',
            'wireguard_address' => '10.6.0.2',
            'orbit_path' => '/home/orbit/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
        ]);

        NodeRoleAssignment::factory()->create([
            'node_id' => $self->id,
            'role' => 'database',
            'status' => 'error',
            'settings' => [],
        ]);

        NodeRoleAssignment::factory()->create([
            'node_id' => $gateway->id,
            'role' => 'gateway',
            'status' => 'active',
            'settings' => [],
        ]);

        $response = call('GET', '/api/me', [], [], [], ['REMOTE_ADDR' => '10.6.0.8']);

        $response->assertOk()
            ->assertJsonPath('success.data.self.roles', [
                [
                    'status' => 'error',
                    'settings' => [],
                ],
            ])
            ->assertJsonPath('success.data.gateway.roles', [
                [
                    'status' => 'active',
                    'settings' => [],
                ],
            ]);

        expect($response->getContent())->toContain('"settings":{}');
    });

    it('resolves the gateway from an active gateway role assignment', function (): void {
        DB::table('nodes')->insert(meNodeRow());

        $gateway = Node::factory()->create([
            'name' => 'gateway-1',

            'host' => '10.6.0.2',
            'wireguard_address' => '10.6.0.2',
            'orbit_path' => '/home/orbit/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
        ]);

        NodeRoleAssignment::factory()->create([
            'node_id' => $gateway->id,
            'role' => 'gateway',
            'status' => 'active',
            'settings' => [],
        ]);

        $response = call('GET', '/api/me', [], [], [], ['REMOTE_ADDR' => '10.6.0.8']);

        $response->assertOk()
            ->assertJsonPath('success.data.gateway.name', 'gateway-1')
            ->assertJsonPath('success.data.gateway.roles.0.role', 'gateway');
    });

    it('does not include legacy id field', function (): void {
        DB::table('nodes')->insert(meNodeRow());
        $gatewayId = (int) DB::table('nodes')->insertGetId(meNodeRow([
            'name' => 'gateway-1',
            'wireguard_address' => '10.6.0.2',
        ]));
        assignMeGatewayRole($gatewayId);

        $response = call('GET', '/api/me', [], [], [], ['REMOTE_ADDR' => '10.6.0.8']);

        $response->assertOk();

        $json = $response->json();
        expect($json['success']['data']['self'])->not->toHaveKey('id')
            ->and($json['success']['data']['gateway'])->not->toHaveKey('id');
    });

    it('authenticates scheduler clients by wireguard address instead of client headers', function (): void {
        DB::table('nodes')->insert(meNodeRow([
            'name' => 'app-1',
            'wireguard_address' => '10.6.0.9',
        ]));
        DB::table('nodes')->insert(meNodeRow([
            'name' => 'gateway-1',
            'wireguard_address' => '10.6.0.2',
        ]));

        $response = call(
            'GET',
            '/api/me',
            [],
            [],
            [],
            [
                'REMOTE_ADDR' => '10.6.0.9',
                'HTTP_X_ORBIT_CLIENT' => 'scheduler',
            ],
        );

        $response->assertOk()
            ->assertJsonPath('success.data.self.name', 'app-1')
            ->assertJsonPath('success.data.self.role', 'app');
    });

    it('rejects spoofed scheduler client headers without a known wireguard peer', function (): void {
        DB::table('nodes')->insert(meNodeRow([
            'name' => 'gateway-1',
            'wireguard_address' => '10.6.0.2',
        ]));

        $response = call(
            'GET',
            '/api/me',
            [],
            [],
            [],
            [
                'REMOTE_ADDR' => '10.6.0.99',
                'HTTP_X_ORBIT_CLIENT' => 'scheduler',
            ],
        );

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed');
    });

    it('trusts the orbit-caddy supplied wireguard peer header only when gateway runtime proxy headers are enabled', function (): void {
        config(['orbit.trust_wireguard_proxy_header' => true]);

        DB::table('nodes')->insert(meNodeRow());
        $gatewayId = (int) DB::table('nodes')->insertGetId(meNodeRow([
            'name' => 'gateway-1',
            'wireguard_address' => '10.6.0.2',
            'platform' => 'ubuntu_24-04',
        ]));
        assignMeGatewayRole($gatewayId);

        $response = call(
            'GET',
            '/api/me',
            [],
            [],
            [],
            [
                'REMOTE_ADDR' => '172.18.0.3',
                'HTTP_X_ORBIT_WIREGUARD_IP' => '10.6.0.8',
            ],
        );

        $response->assertOk()
            ->assertJsonPath('success.data.self.name', 'peer-1');
    });

    it('rejects the orbit-caddy wireguard peer header when gateway runtime proxy headers are disabled', function (): void {
        config(['orbit.trust_wireguard_proxy_header' => false]);

        DB::table('nodes')->insert(meNodeRow());

        $response = call(
            'GET',
            '/api/me',
            [],
            [],
            [],
            [
                'REMOTE_ADDR' => '172.18.0.3',
                'HTTP_X_ORBIT_WIREGUARD_IP' => '10.6.0.8',
            ],
        );

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed');
    });
});
