<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\Nodes\DevelopmentDnsMappingEnactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(RefreshDatabase::class);

afterEach(function (): void {
    File::deleteDirectory(app(DevelopmentDnsMappingEnactor::class)->configDir());
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

function assignApiNodeRole(string $nodeName, string $role, array $settings = []): void
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
function getApiNodesJson(string $uri, array $server = []): TestResponse
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

describe('NodeListController', function (): void {
    beforeEach(function (): void {
        bindDevelopmentDnsMappingTestDoubles('node-list-controller-dns');
        app()->instance(RemoteShell::class, new NodeListControllerRemoteShell);

        createCallerNode();
    });

    it('lists all active nodes sorted by effective role assignment then name', function (): void {
        DB::table('nodes')->insert([
            apiNodeRow(['name' => 'zebra-app', 'role' => 'control']),
            apiNodeRow(['name' => 'alpha-app', 'role' => 'app']),
            apiNodeRow(['name' => 'database-1', 'role' => 'control', 'environment' => null]),
            apiNodeRow(['name' => 'gateway-1', 'role' => 'control', 'environment' => null]),
            apiNodeRow(['name' => 'control-1', 'role' => 'control', 'environment' => null]),
        ]);
        assignApiNodeRole('zebra-app', 'app-development', ['tld' => 'test']);
        assignApiNodeRole('alpha-app', 'app-development', ['tld' => 'test']);
        assignApiNodeRole('database-1', 'database');
        assignApiNodeRole('gateway-1', 'gateway');

        $response = getApiNodesJson('/api/nodes', ['REMOTE_ADDR' => CALLER_WG_IP]);

        $response->assertOk();
        $nodes = $response->json('success.data.nodes');
        $names = array_column($nodes, 'name');
        expect($names)->toBe(['alpha-app', 'zebra-app', 'caller', 'control-1', 'database-1', 'gateway-1']);
    });

    it('returns only caller node when no other nodes exist', function (): void {
        $response = getApiNodesJson('/api/nodes', ['REMOTE_ADDR' => CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.nodes.0.name', 'caller');
    });

    it('filters nodes by role', function (): void {
        DB::table('nodes')->insert([
            apiNodeRow(['name' => 'app-1', 'role' => 'control']),
            apiNodeRow(['name' => 'gateway-1', 'role' => 'gateway', 'environment' => null]),
            apiNodeRow(['name' => 'control-1', 'role' => 'control', 'environment' => null]),
        ]);
        assignApiNodeRole('app-1', 'app-development', ['tld' => 'test']);

        $response = getApiNodesJson('/api/nodes?role=app', ['REMOTE_ADDR' => CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonCount(1, 'success.data.nodes')
            ->assertJsonPath('success.data.nodes.0.name', 'app-1');
    });

    it('filters nodes by concrete role assignments', function (): void {
        DB::table('nodes')->insert([
            apiNodeRow(['name' => 'db-1', 'role' => 'control', 'environment' => null]),
            apiNodeRow(['name' => 'legacy-gateway', 'role' => 'gateway', 'environment' => null]),
            apiNodeRow(['name' => 'assigned-gateway', 'role' => 'control', 'environment' => null]),
        ]);
        assignApiNodeRole('db-1', 'database');
        assignApiNodeRole('assigned-gateway', 'gateway');

        $databaseResponse = getApiNodesJson('/api/nodes?role=database', ['REMOTE_ADDR' => CALLER_WG_IP]);
        $gatewayResponse = getApiNodesJson('/api/nodes?role=gateway', ['REMOTE_ADDR' => CALLER_WG_IP]);

        $databaseResponse->assertOk()
            ->assertJsonCount(1, 'success.data.nodes')
            ->assertJsonPath('success.data.nodes.0.name', 'db-1');

        $gatewayResponse->assertOk()
            ->assertJsonCount(1, 'success.data.nodes')
            ->assertJsonPath('success.data.nodes.0.name', 'assigned-gateway');
    });

    it('filters nodes by environment', function (): void {
        DB::table('nodes')->insert([
            apiNodeRow(['name' => 'dev-app', 'environment' => 'development']),
            apiNodeRow(['name' => 'prod-app', 'environment' => 'production']),
        ]);
        assignApiNodeRole('dev-app', 'app-development', ['tld' => 'test']);
        assignApiNodeRole('prod-app', 'app-production');

        $response = getApiNodesJson('/api/nodes?environment=production', ['REMOTE_ADDR' => CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonCount(1, 'success.data.nodes')
            ->assertJsonPath('success.data.nodes.0.name', 'prod-app');
    });

    it('returns validation error for invalid role', function (): void {
        $response = getApiNodesJson('/api/nodes?role=invalid', ['REMOTE_ADDR' => CALLER_WG_IP]);

        $response->assertStatus(400)
            ->assertJson([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => "Invalid value for role: 'invalid'. Allowed values: gateway, app, app-development, app-production, database, control.",
                    'meta' => [
                        'field' => 'role',
                        'value' => 'invalid',
                        'allowed' => ['gateway', 'app', 'app-development', 'app-production', 'database', 'control'],
                    ],
                ],
            ]);
    });

    it('returns validation error for invalid environment', function (): void {
        $response = getApiNodesJson('/api/nodes?environment=invalid', ['REMOTE_ADDR' => CALLER_WG_IP]);

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

        $response = getApiNodesJson('/api/nodes', ['REMOTE_ADDR' => CALLER_WG_IP]);

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

        $response = getApiNodesJson('/api/nodes', ['REMOTE_ADDR' => CALLER_WG_IP]);

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
        assignApiNodeRole('app-1', 'app-development', ['tld' => 'test']);

        $response = getApiNodesJson('/api/nodes', ['REMOTE_ADDR' => CALLER_WG_IP]);

        $appNode = collect($response->json('success.data.nodes'))
            ->first(fn (array $node): bool => $node['name'] === 'app-1');

        expect($appNode)->toBe([
            'name' => 'app-1',
            'role' => 'app',
            'host' => '10.6.0.7',
            'environment' => 'development',
            'platform' => 'ubuntu_24-04',
            'status' => 'active',
            'roles' => [
                [
                    'role' => 'app-development',
                    'status' => 'active',
                    'settings' => ['tld' => 'test'],
                ],
            ],
        ]);
    });

    it('derives serialized environment from active app role assignments', function (): void {
        DB::table('nodes')->insert([
            apiNodeRow([
                'name' => 'control-app',
                'role' => 'control',
                'environment' => null,
            ]),
            apiNodeRow([
                'name' => 'legacy-app',
                'role' => 'app',
                'environment' => 'development',
            ]),
        ]);
        assignApiNodeRole('control-app', 'app-development', ['tld' => 'test']);

        $response = getApiNodesJson('/api/nodes', ['REMOTE_ADDR' => CALLER_WG_IP]);
        $nodes = collect($response->json('success.data.nodes'))->keyBy('name');

        expect($nodes['control-app']['environment'])->toBe('development')
            ->and($nodes['legacy-app']['environment'])->toBeNull();
    });

    it('rejects unauthenticated requests', function (): void {
        $response = getApiNodesJson('/api/nodes');

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
        assignApiNodeRole('incomplete-app', 'app-development', ['tld' => 'test']);

        $response = getApiNodesJson('/api/nodes?doctor=1&role=app', ['REMOTE_ADDR' => CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.meta.doctor.checked', 1)
            ->assertJsonPath('success.meta.doctor.issues', 2);

        $failure = collect($response->json('success.meta.doctor.failures'))
            ->first(fn (array $failure): bool => $failure['code'] === 'node.record_incomplete');

        expect($failure)->toMatchArray([
            'node' => 'incomplete-app',
            'family' => 'node',
        ]);
    });
});

final class NodeListControllerRemoteShell implements RemoteShell
{
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}
