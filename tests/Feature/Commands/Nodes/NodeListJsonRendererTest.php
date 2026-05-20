<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Nodes\DevelopmentDnsMappingEnactor;
use App\Services\Nodes\DevelopmentDnsMappingProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

afterEach(function (): void {
    File::deleteDirectory(app(DevelopmentDnsMappingEnactor::class)->configDir());
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nodeListJsonRow(array $overrides = []): array
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

/**
 * @param  array<string, mixed>  $settings
 */
function assignNodeListJsonRole(string $nodeName, string $role, array $settings = []): void
{
    $nodeId = (int) DB::table('nodes')
        ->where('name', $nodeName)
        ->value('id');

    NodeRoleAssignment::factory()->create([
        'node_id' => $nodeId,
        'role' => $role,
        'status' => 'active',
        'settings' => $settings,
    ]);
}

/**
 * @param  array<string, mixed>  $settings
 */
function createNodeListJsonRoleAssignment(
    string $nodeName,
    string $role,
    string $status = 'active',
    array $settings = [],
    ?string $lastError = null,
    mixed $convergedAt = null,
): void {
    $nodeId = (int) DB::table('nodes')
        ->where('name', $nodeName)
        ->value('id');

    NodeRoleAssignment::factory()->create([
        'node_id' => $nodeId,
        'role' => $role,
        'status' => $status,
        'settings' => $settings,
        'last_error' => $lastError,
        'converged_at' => $convergedAt,
    ]);
}

describe('node:list JSON renderer contract', function (): void {
    beforeEach(function (): void {
        config(['orbit.is_gateway' => true]);

        $developmentDnsConfigDir = storage_path('framework/testing/node-list-json-dns/'.bin2hex(random_bytes(6)));
        $developmentDnsMappingEnactor = new DevelopmentDnsMappingEnactor($developmentDnsConfigDir);

        app()->instance(DevelopmentDnsMappingEnactor::class, $developmentDnsMappingEnactor);
        app()->instance(DevelopmentDnsMappingProbe::class, new DevelopmentDnsMappingProbe($developmentDnsMappingEnactor));
        app()->instance(RemoteShell::class, new NodeListJsonRendererRemoteShell);

        DB::table('nodes')->insert([
            'name' => 'local-gateway',
            'role' => 'gateway',
            'host' => '10.6.0.1',
            'orbit_path' => '/home/orbit/orbit',
            'status' => 'active',
            'environment' => null,
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        assignNodeListJsonRole('local-gateway', 'gateway');
    });

    it('selects JSON renderer with --json and returns discriminated success envelope', function (): void {
        DB::table('nodes')->insert([
            nodeListJsonRow([
                'name' => 'gateway-1',
                'role' => 'gateway',
                'environment' => null,
            ]),
        ]);

        $exitCode = Artisan::call('node:list', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload)->toHaveKey('success')
            ->and($payload)->not->toHaveKey('error')
            ->and($payload['success'])->toBeArray()
            ->and($payload['success'])->toHaveKey('data');
    });

    it('returns success.data.nodes as a flat array of node objects', function (): void {
        DB::table('nodes')->insert([
            nodeListJsonRow([
                'name' => 'app-1',
                'role' => 'app',
                'environment' => 'development',
            ]),
        ]);

        $exitCode = Artisan::call('node:list', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0);

        $data = $payload['success']['data'];
        expect($data)->toBeArray()
            ->and($data)->toHaveKey('nodes');

        $nodes = $data['nodes'];
        expect($nodes)->toBeArray();
    });

    it('returns per-node fields name role environment platform status', function (): void {
        DB::table('nodes')->insert([
            nodeListJsonRow([
                'name' => 'gateway-1',
                'role' => 'gateway',
                'environment' => null,
                'platform' => 'ubuntu_24-04',
            ]),
            nodeListJsonRow([
                'name' => 'app-1',
                'role' => 'app',
                'environment' => 'development',
                'platform' => 'ubuntu_24-04',
            ]),
        ]);
        assignNodeListJsonRole('app-1', 'app-development', ['tld' => 'test']);

        $exitCode = Artisan::call('node:list', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0);

        $nodes = $payload['success']['data']['nodes'];
        $indexed = array_column($nodes, null, 'name');

        expect($indexed['app-1'])->toBe([
            'name' => 'app-1',
            'role' => 'app',
            'environment' => 'development',
            'platform' => 'ubuntu_24-04',
            'status' => 'active',
            'roles' => [
                [
                    'role' => 'app-development',
                    'status' => 'active',
                    'settings' => ['tld' => 'test'],
                    'last_error' => null,
                    'converged_at' => NodeRoleAssignment::query()
                        ->where('node_id', DB::table('nodes')->where('name', 'app-1')->value('id'))
                        ->where('role', 'app-development')
                        ->first()
                        ?->converged_at
                        ?->toJSON(),
                ],
            ],
        ])
            ->and($indexed['gateway-1'])->toBe([
                'name' => 'gateway-1',
                'role' => 'gateway',
                'environment' => null,
                'platform' => 'ubuntu_24-04',
                'status' => 'active',
                'roles' => [],
            ]);
    });

    it('includes gateway-coupled vpn assignments with full payload fields', function (): void {
        DB::table('nodes')->insert([
            nodeListJsonRow([
                'name' => 'gateway-1',
                'role' => 'gateway',
                'environment' => null,
                'wireguard_address' => '10.6.0.2',
            ]),
        ]);

        $gatewayConvergedAt = now()->subMinute();
        $vpnConvergedAt = now();

        createNodeListJsonRoleAssignment(
            nodeName: 'gateway-1',
            role: 'gateway',
            convergedAt: $gatewayConvergedAt,
        );
        createNodeListJsonRoleAssignment(
            nodeName: 'gateway-1',
            role: 'vpn',
            settings: [
                'public_endpoint' => 'vpn.example.test',
                'wireguard_cidr' => '10.44.0.0/24',
                'wireguard_port' => 51820,
                'dns_ip' => '10.44.0.1',
            ],
            convergedAt: $vpnConvergedAt,
        );

        $exitCode = Artisan::call('node:list', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);
        $gatewayNode = collect($payload['success']['data']['nodes'])->firstWhere('name', 'gateway-1');
        $roleAssignments = collect($gatewayNode['roles'])->keyBy('role');

        expect($exitCode)->toBe(0)
            ->and($gatewayNode)
            ->toMatchArray([
                'name' => 'gateway-1',
                'role' => 'gateway',
                'environment' => null,
                'platform' => 'ubuntu_24-04',
                'status' => 'active',
            ]);

        expect($roleAssignments['gateway'])->toMatchArray([
            'role' => 'gateway',
            'status' => 'active',
            'settings' => [],
            'last_error' => null,
        ])->and($roleAssignments['gateway']['converged_at'])->toBe(
            NodeRoleAssignment::query()
                ->where('node_id', DB::table('nodes')->where('name', 'gateway-1')->value('id'))
                ->where('role', 'gateway')
                ->first()
                ?->converged_at
                ?->toJSON(),
        );

        expect($roleAssignments['vpn'])->toMatchArray([
            'role' => 'vpn',
            'status' => 'active',
            'settings' => [
                'public_endpoint' => 'vpn.example.test',
                'wireguard_cidr' => '10.44.0.0/24',
                'wireguard_port' => 51820,
                'dns_ip' => '10.44.0.1',
            ],
            'last_error' => null,
        ])->and($roleAssignments['vpn']['converged_at'])->toBe(
            NodeRoleAssignment::query()
                ->where('node_id', DB::table('nodes')->where('name', 'gateway-1')->value('id'))
                ->where('role', 'vpn')
                ->first()
                ?->converged_at
                ?->toJSON(),
        );
    });

    it('does not include wg_ip or internal fields in JSON output', function (): void {
        DB::table('nodes')->insert(nodeListJsonRow([
            'name' => 'gateway-1',
            'wireguard_address' => '10.6.0.1',
        ]));

        $exitCode = Artisan::call('node:list', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        $nodes = $payload['success']['data']['nodes'];

        foreach ($nodes as $node) {
            expect($node)->not->toHaveKey('wg_ip')
                ->and($node)->not->toHaveKey('wireguard_address')
                ->and($node)->not->toHaveKey('host')
                ->and($node)->not->toHaveKey('user')
                ->and($node)->not->toHaveKey('orbit_path')
                ->and($node)->not->toHaveKey('is_local');
        }
    });

    it('defaults platform to unknown when null', function (): void {
        DB::table('nodes')->insert(nodeListJsonRow([
            'name' => 'gateway-1',
            'role' => 'gateway',
            'environment' => null,
            'platform' => null,
        ]));

        $exitCode = Artisan::call('node:list', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        $nodes = $payload['success']['data']['nodes'];

        expect($nodes[0]['platform'])->toBe('unknown');
    });

    it('returns empty nodes array when no nodes match filters', function (): void {
        $exitCode = Artisan::call('node:list', ['--json' => true, '--role' => 'control']);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0);

        $nodes = $payload['success']['data']['nodes'];

        expect($nodes)->toBeArray()->and($nodes)->toHaveCount(0);
    });

    it('environment is null for non-app nodes', function (): void {
        DB::table('nodes')->insert(nodeListJsonRow([
            'name' => 'gateway-1',
            'role' => 'gateway',
            'environment' => null,
        ]));

        $exitCode = Artisan::call('node:list', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0);

        $nodes = $payload['success']['data']['nodes'];
        $indexed = array_column($nodes, null, 'name');

        expect($indexed['gateway-1'])->toHaveKey('environment')
            ->and($indexed['gateway-1']['environment'])->toBeNull();
    });

    it('platform is never null and uses unknown sentinel when undetected', function (): void {
        DB::table('nodes')->insert(nodeListJsonRow([
            'name' => 'gateway-1',
            'role' => 'gateway',
            'environment' => null,
            'platform' => null,
        ]));

        $exitCode = Artisan::call('node:list', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0);

        $nodes = $payload['success']['data']['nodes'];
        $indexed = array_column($nodes, null, 'name');

        expect($indexed['gateway-1'])->toHaveKey('platform')
            ->and($indexed['gateway-1']['platform'])->not->toBeNull();
    });

    it('derives serialized and filtered environments from active app role assignments', function (): void {
        DB::table('nodes')->insert([
            nodeListJsonRow([
                'name' => 'legacy-app',
                'role' => 'app',
                'environment' => 'development',
                'wireguard_address' => '10.6.0.8',
            ]),
            nodeListJsonRow([
                'name' => 'control-app',
                'role' => 'control',
                'environment' => null,
                'wireguard_address' => '10.6.0.9',
            ]),
            nodeListJsonRow([
                'name' => 'prod-app',
                'role' => 'app',
                'environment' => 'production',
                'wireguard_address' => '10.6.0.10',
            ]),
        ]);
        assignNodeListJsonRole('control-app', 'app-development', ['tld' => 'test']);
        assignNodeListJsonRole('prod-app', 'app-production');

        $exitCode = Artisan::call('node:list', ['--json' => true, '--environment' => 'development']);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        $nodes = $payload['success']['data']['nodes'];

        expect($exitCode)->toBe(0)
            ->and(array_column($nodes, 'name'))->toBe(['control-app'])
            ->and($nodes[0]['environment'])->toBe('development');
    });

    it('uses correct enum values for role and status', function (): void {
        DB::table('nodes')->insert([
            nodeListJsonRow([
                'name' => 'gateway-1',
                'role' => 'gateway',
                'environment' => null,
            ]),
            nodeListJsonRow([
                'name' => 'app-1',
                'role' => 'app',
                'environment' => 'development',
            ]),
            nodeListJsonRow([
                'name' => 'control-1',
                'role' => 'control',
                'environment' => null,
                'status' => 'provisioning',
            ]),
        ]);

        $exitCode = Artisan::call('node:list', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0);

        $nodes = $payload['success']['data']['nodes'];
        $indexed = array_column($nodes, null, 'name');

        expect($indexed['gateway-1']['role'])->toBe('gateway');
        expect($indexed['app-1']['role'])->toBe('app');
        expect($indexed['control-1']['role'])->toBe('control');

        expect($indexed['gateway-1']['status'])->toBeIn(['active', 'provisioning']);
        expect($indexed['app-1']['status'])->toBeIn(['active', 'provisioning']);
        expect($indexed['control-1']['status'])->toBeIn(['active', 'provisioning']);
    });

    it('returns nested validation_failed error for invalid --role', function (): void {
        $exitCode = Artisan::call('node:list', ['--json' => true, '--role' => 'bogus']);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1);

        expect($payload)->toHaveKey('error')
            ->and($payload)->not->toHaveKey('success');

        $error = $payload['error'];
        expect($error['code'])->toBe('validation_failed');
        expect($error['meta'])->toHaveKey('field')
            ->and($error['meta']['field'])->toBe('role')
            ->and($error['meta'])->toHaveKey('value')
            ->and($error['meta'])->toHaveKey('allowed');
    });

    it('returns nested validation_failed error for invalid --environment', function (): void {
        $exitCode = Artisan::call('node:list', ['--json' => true, '--environment' => 'bogus']);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1);

        expect($payload)->toHaveKey('error')
            ->and($payload)->not->toHaveKey('success');

        $error = $payload['error'];
        expect($error['code'])->toBe('validation_failed');
        expect($error['meta'])->toHaveKey('field')
            ->and($error['meta']['field'])->toBe('environment');
    });

    it('attaches doctor meta when --doctor is present and issues are found', function (): void {
        $node = createTestAppHostNode(nodeListJsonRow([
            'name' => 'incomplete-app',
            'environment' => 'production',
            'tld' => null,
            'wireguard_address' => null,
        ]), 'app-production');
        markNodeSecurityBaselineClean($node);

        $exitCode = Artisan::call('node:list', [
            '--json' => true,
            '--doctor' => true,
            '--role' => 'app',
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0);

        $doctor = $payload['success']['meta']['doctor'];

        expect($doctor['checked'])->toBe(1)
            ->and($doctor['issues'])->toBe(1)
            ->and($doctor['failures'])->toHaveCount(1)
            ->and($doctor['failures'][0])->toMatchArray([
                'node' => 'incomplete-app',
                'code' => 'node.record_incomplete',
                'family' => 'node',
                'next_command' => 'doctor --family=node --node=incomplete-app',
            ]);
    });

    it('attaches healthy doctor meta without failures when --doctor finds no issues', function (): void {
        $node = createTestAppHostNode(nodeListJsonRow(['name' => 'healthy-app']));
        markNodeSecurityBaselineClean($node);
        DB::table('wireguard_peers')->insert([
            'node_id' => $node->id,
            'public_key' => 'healthy-public-key',
            'private_key' => 'healthy-private-key',
            'allowed_ips' => '10.6.0.7/32',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        app(DevelopmentDnsMappingEnactor::class)->converge($node);

        $exitCode = Artisan::call('node:list', [
            '--json' => true,
            '--doctor' => true,
            '--role' => 'app',
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['meta']['doctor'])->toBe([
                'checked' => 1,
                'issues' => 0,
            ]);
    });
});

final class NodeListJsonRendererRemoteShell implements RemoteShell
{
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}
