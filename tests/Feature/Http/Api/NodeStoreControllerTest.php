<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Models\WireGuardPeer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function apiStoreNodeRow(array $overrides = []): array
{
    return array_merge([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'environment' => null,
        'tld' => null,
        'platform' => 'unknown',
        'host' => '10.6.0.2',
        'wireguard_address' => '10.6.0.2',
        'gateway_endpoint' => null,
        'user' => 'orbit',
        'orbit_path' => '/home/orbit/orbit',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

describe('NodeStoreController', function (): void {
    it('provisions an app node for an authenticated control caller', function (): void {
        DB::table('nodes')->insert([
            apiStoreNodeRow(),
            apiStoreNodeRow([
                'name' => 'control-1',
                'role' => 'control',
                'host' => '10.6.0.3',
                'wireguard_address' => '10.6.0.3',
                'gateway_endpoint' => '10.6.0.2',
                'user' => 'tester',
                'orbit_path' => '/home/tester/orbit',
            ]),
        ]);

        Process::fake(fn ($process) => str_contains((string) $process->command, 'ssh-keygen -y')
            ? Process::result(output: "ssh-ed25519 AAAATEST gateway\n")
            : Process::result());
        Process::preventStrayProcesses();

        $response = $this
            ->withServerVariables(['REMOTE_ADDR' => '10.6.0.3'])
            ->postJson('/api/nodes', [
                'name' => 'app-dev-1',
                'role' => 'app',
                'host' => '192.0.2.20',
                'environment' => 'development',
                'tld' => 'test',
            ]);

        $response->assertOk()
            ->assertJsonPath('success.data.node.name', 'app-dev-1')
            ->assertJsonPath('success.data.node.role', 'app')
            ->assertJsonPath('success.data.development_tld.gateway_dns.domain', '*.test');

        $node = DB::table('nodes')->where('name', 'app-dev-1')->first();

        expect($node)->not->toBeNull()
            ->and($node->environment)->toBe('development')
            ->and($node->tld)->toBe('test')
            ->and($node->wireguard_address)->toBe('10.6.0.4');

        $entry = Activity::query()
            ->where('event', 'node.created')
            ->first();

        expect($entry)->not->toBeNull();
        expect($entry->log_name)->toBe('api');
        expect($entry->properties->get('type'))->toBe('write');
        expect($entry->subject?->name)->toBe('app-dev-1');
        expect($entry->properties->get('name'))->toBe('app-dev-1');
        expect($entry->properties->get('role'))->toBe('app');
        expect($entry->properties->get('environment'))->toBe('development');
        expect($entry->properties->get('tld'))->toBe('test');

        Process::assertRan(fn ($process): bool => ! str_contains($process->command, '--role=')
            && str_contains($process->command, '--source-archive='));
        Process::assertRan(fn ($process): bool => str_contains($process->command, 'authorized_keys')
            && str_contains($process->command, 'ssh-ed25519 AAAATEST gateway'));
    });

    it('rejects app callers before provisioning', function (): void {
        DB::table('nodes')->insert([
            apiStoreNodeRow(),
            apiStoreNodeRow([
                'name' => 'app-caller',
                'role' => 'app',
                'environment' => 'development',
                'tld' => 'caller',
                'host' => '10.6.0.7',
                'wireguard_address' => '10.6.0.7',
                'gateway_endpoint' => '10.6.0.2',
            ]),
        ]);

        Process::fake();
        Process::preventStrayProcesses();

        $response = $this
            ->withServerVariables(['REMOTE_ADDR' => '10.6.0.7'])
            ->postJson('/api/nodes', [
                'name' => 'app-dev-1',
                'role' => 'app',
                'host' => '192.0.2.20',
                'environment' => 'development',
                'tld' => 'test',
            ]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed');

        expect(DB::table('nodes')->where('name', 'app-dev-1')->exists())->toBeFalse();
        Process::assertRanTimes(fn (): bool => true, 0);
    });

    it('adopts a compatible app node for an authenticated control caller', function (): void {
        DB::table('nodes')->insert([
            apiStoreNodeRow(),
            apiStoreNodeRow([
                'name' => 'control-1',
                'role' => 'control',
                'host' => '10.6.0.3',
                'wireguard_address' => '10.6.0.3',
                'gateway_endpoint' => '10.6.0.2',
                'user' => 'tester',
                'orbit_path' => '/home/tester/orbit',
            ]),
        ]);

        $nodeId = DB::table('nodes')->insertGetId(apiStoreNodeRow([
            'name' => 'app-adopt-1',
            'role' => 'app',
            'environment' => 'development',
            'tld' => 'test',
            'platform' => 'ubuntu_24-04',
            'host' => '192.0.2.30',
            'wireguard_address' => '10.6.0.8',
            'gateway_endpoint' => '10.6.0.2',
            'user' => 'orbit',
            'orbit_path' => '/home/orbit/orbit',
            'status' => 'decommissioned',
        ]));

        WireGuardPeer::query()->create([
            'node_id' => $nodeId,
            'public_key' => 'app-public-key',
            'private_key' => 'app-private-key',
            'allowed_ips' => '10.6.0.8/32',
        ]);

        Process::fake([
            'sudo wg show wg-orbit allowed-ips' => Process::result(output: "app-public-key\t10.6.0.9/32\n"),
        ]);
        Process::preventStrayProcesses();

        $response = $this
            ->withServerVariables(['REMOTE_ADDR' => '10.6.0.3'])
            ->postJson('/api/nodes', [
                'name' => 'app-adopt-1',
                'role' => 'app',
                'host' => '192.0.2.30',
                'environment' => 'development',
                'tld' => 'test',
            ]);

        $response->assertOk()
            ->assertJsonPath('success.data.result.action', 'adopted')
            ->assertJsonPath('success.data.provisioning.status', 'adopted')
            ->assertJsonPath('success.data.node.addresses.wireguard', '10.6.0.9');

        $node = DB::table('nodes')->where('name', 'app-adopt-1')->first();

        expect($node)->not->toBeNull()
            ->and($node->status)->toBe('active')
            ->and($node->wireguard_address)->toBe('10.6.0.9');

        $entry = Activity::query()
            ->where('event', 'node.created')
            ->first();

        expect($entry)->not->toBeNull();
        expect($entry->subject?->name)->toBe('app-adopt-1');

        Process::assertRan(fn ($process): bool => $process->command === 'sudo wg show wg-orbit allowed-ips');
        Process::assertRanTimes(fn ($process): bool => str_contains($process->command, 'ssh '), 0);
    });

    it('materializes a compatible unknown app host for an authenticated control caller', function (): void {
        DB::table('nodes')->insert([
            apiStoreNodeRow(),
            apiStoreNodeRow([
                'name' => 'control-1',
                'role' => 'control',
                'host' => '10.6.0.3',
                'wireguard_address' => '10.6.0.3',
                'gateway_endpoint' => '10.6.0.2',
                'user' => 'tester',
                'orbit_path' => '/home/tester/orbit',
            ]),
        ]);

        app()->instance(RemoteShell::class, new NodeStoreSequencedRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: json_encode([
                'name' => 'app-unknown-1',
                'role' => 'app',
                'local_role' => 'app',
                'status' => 'active',
                'platform' => 'ubuntu_24-04',
                'wireguard_address' => '10.6.0.8',
                'registry_public_key' => null,
                'interface_public_key' => 'app-public-key',
            ], JSON_THROW_ON_ERROR), stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: json_encode([
                'name' => 'app-unknown-1',
                'role' => 'app',
                'local_role' => 'app',
                'status' => 'active',
                'platform' => 'ubuntu_24-04',
                'wireguard_address' => '10.6.0.8',
                'registry_public_key' => null,
                'interface_public_key' => 'app-public-key',
            ], JSON_THROW_ON_ERROR), stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: 'supervisor OK', stderr: '', durationMs: 1),
        ]));

        Process::fake([
            'sudo wg show wg-orbit allowed-ips' => Process::result(output: "app-public-key\t10.6.0.8/32\n"),
        ]);
        Process::preventStrayProcesses();

        $response = $this
            ->withServerVariables(['REMOTE_ADDR' => '10.6.0.3'])
            ->postJson('/api/nodes', [
                'name' => 'app-unknown-1',
                'role' => 'app',
                'host' => '192.0.2.33',
                'environment' => 'development',
                'tld' => 'test',
            ]);

        $response->assertOk()
            ->assertJsonPath('success.data.result.action', 'adopted')
            ->assertJsonPath('success.data.provisioning.status', 'adopted')
            ->assertJsonPath('success.data.node.addresses.wireguard', '10.6.0.8')
            ->assertJsonPath('success.data.node.platform', 'ubuntu_24-04');

        $node = DB::table('nodes')->where('name', 'app-unknown-1')->first();
        $peer = $node === null ? null : DB::table('wireguard_peers')->where('node_id', $node->id)->first();

        expect($node)->not->toBeNull()
            ->and($node->host)->toBe('192.0.2.33')
            ->and($node->status)->toBe('active')
            ->and($peer)->not->toBeNull()
            ->and($peer->public_key)->toBe('app-public-key')
            ->and($peer->private_key)->toBe('')
            ->and($peer->allowed_ips)->toBe('10.6.0.8/32');

        $entry = Activity::query()
            ->where('event', 'node.created')
            ->first();

        expect($entry)->not->toBeNull();
        expect($entry->subject?->name)->toBe('app-unknown-1');

        Process::assertRan(fn ($process): bool => $process->command === 'sudo wg show wg-orbit allowed-ips');
        Process::assertRanTimes(fn ($process): bool => str_contains($process->command, 'ssh '), 0);
    });
});

final class NodeStoreSequencedRemoteShell implements RemoteShell
{
    /**
     * @param  list<RemoteShellResult>  $results
     */
    public function __construct(
        private array $results,
    ) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        return array_shift($this->results) ?? new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}
