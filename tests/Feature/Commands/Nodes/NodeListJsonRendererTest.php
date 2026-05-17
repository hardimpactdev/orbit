<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\Nodes\DevelopmentDnsMappingEnactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

afterEach(function (): void {
    File::deleteDirectory(storage_path('app/orbit/node-development-dns.d'));
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

describe('node:list JSON renderer contract', function (): void {
    beforeEach(function (): void {
        config(['orbit.is_gateway' => true]);

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
            'roles' => [],
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
        DB::table('nodes')->insert(nodeListJsonRow([
            'name' => 'incomplete-app',
            'wireguard_address' => null,
        ]));

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
                'next_command' => 'doctor --fix --family=node --restore',
            ]);
    });

    it('attaches healthy doctor meta without failures when --doctor finds no issues', function (): void {
        DB::table('nodes')->insert(nodeListJsonRow(['name' => 'healthy-app']));
        DB::table('wireguard_peers')->insert([
            'node_id' => DB::table('nodes')->where('name', 'healthy-app')->value('id'),
            'public_key' => 'healthy-public-key',
            'private_key' => 'healthy-private-key',
            'allowed_ips' => '10.6.0.7/32',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        app(DevelopmentDnsMappingEnactor::class)->converge(Node::query()->where('name', 'healthy-app')->firstOrFail());

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
