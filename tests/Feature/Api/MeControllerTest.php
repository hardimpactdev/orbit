<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function meNodeRow(array $overrides = []): array
{
    return array_merge([
        'name' => 'peer-1',
        'role' => 'control',
        'host' => '10.6.0.8',
        'wireguard_address' => '10.6.0.8',
        'ssh_user' => 'nckrtl',
        'orbit_path' => '/Users/nckrtl/orbit',
        'status' => 'active',
        'is_local' => false,
        'platform' => 'macos_15-4',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

describe('GET /api/me', function (): void {
    it('returns 403 for unknown peer', function (): void {
        $response = $this->getJson('/api/me');

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
        DB::table('nodes')->insert(meNodeRow([
            'name' => 'gateway-1',
            'role' => 'gateway',
            'wireguard_address' => '10.6.0.2',
            'is_local' => true,
            'platform' => 'ubuntu_24-04',
        ]));

        $response = $this->call('GET', '/api/me', [], [], [], ['REMOTE_ADDR' => '10.6.0.8']);

        $response->assertOk()
            ->assertExactJson([
                'success' => [
                    'data' => [
                        'self' => [
                            'name' => 'peer-1',
                            'role' => 'control',
                            'status' => 'active',
                            'platform' => 'macos_15-4',
                            'addresses' => [
                                'wireguard' => '10.6.0.8',
                            ],
                        ],
                        'gateway' => [
                            'name' => 'gateway-1',
                            'role' => 'gateway',
                            'status' => 'active',
                            'platform' => 'ubuntu_24-04',
                            'addresses' => [
                                'wireguard' => '10.6.0.2',
                            ],
                        ],
                    ],
                ],
            ]);
    });

    it('returns success shape for gateway-local node via wireguard ip', function (): void {
        DB::table('nodes')->insert(meNodeRow([
            'name' => 'gateway-1',
            'role' => 'gateway',
            'wireguard_address' => '10.6.0.2',
            'is_local' => true,
            'platform' => 'ubuntu_24-04',
        ]));

        $response = $this->call('GET', '/api/me', [], [], [], ['REMOTE_ADDR' => '10.6.0.2']);

        $response->assertOk()
            ->assertExactJson([
                'success' => [
                    'data' => [
                        'self' => [
                            'name' => 'gateway-1',
                            'role' => 'gateway',
                            'status' => 'active',
                            'platform' => 'ubuntu_24-04',
                            'addresses' => [
                                'wireguard' => '10.6.0.2',
                            ],
                        ],
                        'gateway' => [
                            'name' => 'gateway-1',
                            'role' => 'gateway',
                            'status' => 'active',
                            'platform' => 'ubuntu_24-04',
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
        DB::table('nodes')->insert(meNodeRow([
            'name' => 'gateway-1',
            'role' => 'gateway',
            'wireguard_address' => '10.6.0.2',
            'is_local' => true,
            'platform' => 'ubuntu_24-04',
        ]));

        $response = $this->call('GET', '/api/me', [], [], [], ['REMOTE_ADDR' => '10.6.0.8']);

        $response->assertOk()
            ->assertJsonPath('success.data.self.platform', 'unknown')
            ->assertJsonPath('success.data.self.status', 'active');
    });

    it('does not include legacy id field', function (): void {
        DB::table('nodes')->insert(meNodeRow());
        DB::table('nodes')->insert(meNodeRow([
            'name' => 'gateway-1',
            'role' => 'gateway',
            'wireguard_address' => '10.6.0.2',
            'is_local' => true,
        ]));

        $response = $this->call('GET', '/api/me', [], [], [], ['REMOTE_ADDR' => '10.6.0.8']);

        $response->assertOk();

        $json = $response->json();
        expect($json['success']['data']['self'])->not->toHaveKey('id')
            ->and($json['success']['data']['gateway'])->not->toHaveKey('id');
    });
});
