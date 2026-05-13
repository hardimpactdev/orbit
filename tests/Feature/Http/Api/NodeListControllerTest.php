<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

afterEach(function (): void {
    File::deleteDirectory(storage_path('app/orbit/node-development-dns.d'));
});

const CALLER_WG_IP = '10.6.0.99';

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function apiNodeRow(array $overrides = []): array
{
    return array_merge([
        'name' => 'app-1',
        'role' => 'app',
        'host' => '10.6.0.7',
        'orbit_path' => '/home/nckrtl/orbit',
        'status' => 'active',
        'environment' => 'development',
        'tld' => 'test',
        'platform' => 'ubuntu_24-04',
        'wireguard_address' => '10.6.0.7',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

function createCallerNode(): void
{
    DB::table('nodes')->insert([
        'name' => 'caller',
        'role' => 'control',
        'host' => CALLER_WG_IP,
        'orbit_path' => '/home/test/orbit',
        'status' => 'active',
        'wireguard_address' => CALLER_WG_IP,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

describe('NodeListController', function (): void {
    beforeEach(function (): void {
        app()->instance(RemoteShell::class, new NodeListControllerRemoteShell);

        createCallerNode();
    });

    it('lists all active nodes sorted by role then name', function (): void {
        DB::table('nodes')->insert([
            apiNodeRow(['name' => 'zebra-app', 'role' => 'app']),
            apiNodeRow(['name' => 'alpha-app', 'role' => 'app']),
            apiNodeRow(['name' => 'gateway-1', 'role' => 'gateway', 'environment' => null]),
            apiNodeRow(['name' => 'control-1', 'role' => 'control', 'environment' => null]),
        ]);

        $response = $this->call('GET', '/api/nodes', [], [], [], ['REMOTE_ADDR' => CALLER_WG_IP]);

        $response->assertOk();
        $nodes = $response->json('success.data.nodes');
        $names = array_column($nodes, 'name');
        expect($names)->toBe(['alpha-app', 'zebra-app', 'caller', 'control-1', 'gateway-1']);
    });

    it('returns only caller node when no other nodes exist', function (): void {
        $response = $this->call('GET', '/api/nodes', [], [], [], ['REMOTE_ADDR' => CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.nodes.0.name', 'caller');
    });

    it('filters nodes by role', function (): void {
        DB::table('nodes')->insert([
            apiNodeRow(['name' => 'app-1', 'role' => 'app']),
            apiNodeRow(['name' => 'gateway-1', 'role' => 'gateway', 'environment' => null]),
            apiNodeRow(['name' => 'control-1', 'role' => 'control', 'environment' => null]),
        ]);

        $response = $this->call('GET', '/api/nodes?role=app', [], [], [], ['REMOTE_ADDR' => CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonCount(1, 'success.data.nodes')
            ->assertJsonPath('success.data.nodes.0.name', 'app-1');
    });

    it('filters nodes by environment', function (): void {
        DB::table('nodes')->insert([
            apiNodeRow(['name' => 'dev-app', 'environment' => 'development']),
            apiNodeRow(['name' => 'prod-app', 'environment' => 'production']),
        ]);

        $response = $this->call('GET', '/api/nodes?environment=production', [], [], [], ['REMOTE_ADDR' => CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonCount(1, 'success.data.nodes')
            ->assertJsonPath('success.data.nodes.0.name', 'prod-app');
    });

    it('returns validation error for invalid role', function (): void {
        $response = $this->call('GET', '/api/nodes?role=invalid', [], [], [], ['REMOTE_ADDR' => CALLER_WG_IP]);

        $response->assertStatus(400)
            ->assertJson([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => "Invalid value for role: 'invalid'. Allowed values: gateway, app, control.",
                    'meta' => [
                        'field' => 'role',
                        'value' => 'invalid',
                        'allowed' => ['gateway', 'app', 'control'],
                    ],
                ],
            ]);
    });

    it('returns validation error for invalid environment', function (): void {
        $response = $this->call('GET', '/api/nodes?environment=invalid', [], [], [], ['REMOTE_ADDR' => CALLER_WG_IP]);

        $response->assertStatus(400)
            ->assertJson([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => "Invalid value for environment: 'invalid'. Allowed values: development, production.",
                    'meta' => [
                        'field' => 'environment',
                        'value' => 'invalid',
                        'allowed' => ['development', 'production'],
                    ],
                ],
            ]);
    });

    it('returns null environment for non-app nodes', function (): void {
        DB::table('nodes')->insert([
            apiNodeRow([
                'name' => 'gateway-1',
                'role' => 'gateway',
                'environment' => null,
                'platform' => 'ubuntu_24-04',
            ]),
        ]);

        $response = $this->call('GET', '/api/nodes', [], [], [], ['REMOTE_ADDR' => CALLER_WG_IP]);

        $gatewayNode = collect($response->json('success.data.nodes'))
            ->first(fn (array $node): bool => $node['name'] === 'gateway-1');

        expect($gatewayNode['environment'])->toBeNull();
    });

    it('defaults platform to unknown when not set', function (): void {
        DB::table('nodes')->insert([
            apiNodeRow([
                'name' => 'app-1',
                'platform' => null,
            ]),
        ]);

        $response = $this->call('GET', '/api/nodes', [], [], [], ['REMOTE_ADDR' => CALLER_WG_IP]);

        $appNode = collect($response->json('success.data.nodes'))
            ->first(fn (array $node): bool => $node['name'] === 'app-1');

        expect($appNode['platform'])->toBe('unknown');
    });

    it('returns correct node field shape', function (): void {
        DB::table('nodes')->insert([
            apiNodeRow([
                'name' => 'app-1',
                'role' => 'app',
                'environment' => 'development',
                'platform' => 'ubuntu_24-04',
                'status' => 'active',
            ]),
        ]);

        $response = $this->call('GET', '/api/nodes', [], [], [], ['REMOTE_ADDR' => CALLER_WG_IP]);

        $appNode = collect($response->json('success.data.nodes'))
            ->first(fn (array $node): bool => $node['name'] === 'app-1');

        expect($appNode)->toBe([
            'name' => 'app-1',
            'role' => 'app',
            'host' => '10.6.0.7',
            'environment' => 'development',
            'platform' => 'ubuntu_24-04',
            'status' => 'active',
        ]);
    });

    it('rejects unauthenticated requests', function (): void {
        $response = $this->getJson('/api/nodes');

        $response->assertForbidden()
            ->assertJson([
                'error' => [
                    'code' => 'authorization_failed',
                    'message' => 'Peer identity unknown.',
                    'meta' => [],
                ],
            ]);
    });

    it('attaches doctor meta when doctor query is present', function (): void {
        DB::table('nodes')->insert([
            apiNodeRow([
                'name' => 'incomplete-app',
                'wireguard_address' => null,
            ]),
        ]);

        $response = $this->call('GET', '/api/nodes?doctor=1&role=app', [], [], [], ['REMOTE_ADDR' => CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.meta.doctor.checked', 1)
            ->assertJsonPath('success.meta.doctor.issues', 1)
            ->assertJsonPath('success.meta.doctor.failures.0.node', 'incomplete-app')
            ->assertJsonPath('success.meta.doctor.failures.0.code', 'node.record_incomplete')
            ->assertJsonPath('success.meta.doctor.failures.0.family', 'node');
    });
});

final class NodeListControllerRemoteShell implements RemoteShell
{
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}
