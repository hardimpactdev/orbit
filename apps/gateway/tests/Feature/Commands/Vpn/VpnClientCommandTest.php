<?php

declare(strict_types=1);

use App\Data\Vpn\VpnBackendClient;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Runtime\OrbitRuntimeContainer;
use App\Services\Vpn\ArrayVpnBackend;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

it('lists vpn clients with json metadata on gateway callers', function (): void {
    vpnLocalNode('gateway');
    NodeRoleAssignment::factory()->create([
        'node_id' => Node::query()->firstOrFail()->id,
        'role' => 'vpn',
        'status' => 'active',
    ]);
    bindVpnBackend(new ArrayVpnBackend([
        new VpnBackendClient('client-1', 'laptop', '10.6.0.7', true, '2026-04-26T10:00:00Z'),
    ]));

    $exitCode = Artisan::call('vpn-client:list', ['--json' => true]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['success']['data']['clients'][0])->toMatchArray([
            'id' => 'client-1',
            'name' => 'laptop',
            'address' => '10.6.0.7',
            'enabled' => true,
            'latest_handshake_at' => '2026-04-26T10:00:00Z',
            'kind' => 'admin',
        ])
        ->and($payload['success']['meta']['count'])->toBe(1);
});

it('creates admin vpn clients without creating node records', function (): void {
    vpnLocalNode('gateway');
    NodeRoleAssignment::factory()->create([
        'node_id' => Node::query()->firstOrFail()->id,
        'role' => 'vpn',
        'status' => 'active',
    ]);
    bindVpnBackend(new ArrayVpnBackend);

    $before = Node::query()->count();

    $exitCode = Artisan::call('vpn-client:new', [
        'name' => 'laptop',
        '--config' => true,
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['success']['data']['client']['name'])->toBe('laptop')
        ->and($payload['success']['data']['client']['kind'])->toBe('admin')
        ->and($payload['success']['data']['client']['config'])->toContain('[Interface]')
        ->and($payload['success']['meta']['config_included'])->toBeTrue()
        ->and(Node::query()->count())->toBe($before);
});

it('enables disables and removes non-node clients', function (): void {
    vpnLocalNode('gateway');
    NodeRoleAssignment::factory()->create([
        'node_id' => Node::query()->firstOrFail()->id,
        'role' => 'vpn',
        'status' => 'active',
    ]);
    bindVpnBackend(new ArrayVpnBackend([
        new VpnBackendClient('client-1', 'laptop', '10.6.0.7', false, null),
    ]));

    $enabled = Artisan::call('vpn-client:enable', ['name' => 'laptop', '--json' => true]);
    $enablePayload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    $disabled = Artisan::call('vpn-client:disable', ['name' => 'laptop', '--json' => true]);
    $disablePayload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    $removed = Artisan::call('vpn-client:remove', ['name' => 'laptop', '--force' => true, '--json' => true]);
    $removePayload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($enabled)->toBe(0)
        ->and($enablePayload['success']['data']['client']['enabled'])->toBeTrue()
        ->and($enablePayload['success']['data']['client']['action'])->toBe('enabled')
        ->and($disabled)->toBe(0)
        ->and($disablePayload['success']['data']['client']['enabled'])->toBeFalse()
        ->and($disablePayload['success']['data']['client']['action'])->toBe('disabled')
        ->and($disablePayload['success']['data']['client']['already_disabled'])->toBeFalse()
        ->and($removed)->toBe(0)
        ->and($removePayload['success']['data']['client'])->toBe([
            'name' => 'laptop',
            'action' => 'removed',
        ]);
});

it('requires force for remove in json mode before backend deletion', function (): void {
    vpnLocalNode('gateway');
    NodeRoleAssignment::factory()->create([
        'node_id' => Node::query()->firstOrFail()->id,
        'role' => 'vpn',
        'status' => 'active',
    ]);
    $backend = new ArrayVpnBackend([
        new VpnBackendClient('client-1', 'laptop', '10.6.0.7', true, null),
    ]);
    bindVpnBackend($backend);

    $exitCode = Artisan::call('vpn-client:remove', ['name' => 'laptop', '--json' => true]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('validation_failed')
        ->and($payload['error']['meta'])->toBe([
            'field' => 'force',
            'reason' => 'destructive_consent_required',
        ])
        ->and($backend->hasClient('laptop'))->toBeTrue();
});

it('protects active node peers from vpn-client writes', function (): void {
    vpnLocalNode('gateway');
    NodeRoleAssignment::factory()->create([
        'node_id' => Node::query()->firstOrFail()->id,
        'role' => 'vpn',
        'status' => 'active',
    ]);
    Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'wireguard_address' => '10.6.0.8',
        'status' => 'active',
    ]);
    bindVpnBackend(new ArrayVpnBackend([
        new VpnBackendClient('client-1', 'app-1', '10.6.0.8', true, null),
    ]));

    $exitCode = Artisan::call('vpn-client:remove', [
        'name' => 'app-1',
        '--force' => true,
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('validation_failed')
        ->and($payload['error']['meta']['reason'])->toBe('node_peer_protected')
        ->and($payload['error']['meta']['next_command'])->toBe('node:remove app-1')
        ->and(Node::query()->where('name', 'app-1')->exists())->toBeTrue();
});

it('runs on gateway machines without client-side caller checks', function (): void {
    vpnLocalNode('app');
    NodeRoleAssignment::factory()->create([
        'node_id' => Node::query()->firstOrFail()->id,
        'role' => 'vpn',
        'status' => 'active',
    ]);
    $backend = new ArrayVpnBackend;
    bindVpnBackend($backend);

    $exitCode = Artisan::call('vpn-client:list', ['--json' => true]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['success']['data']['clients'])->toBe([])
        ->and($backend->listCalled)->toBeTrue();
});

it('forwards control callers to the gateway through orbit runtime executor', function (): void {
    config(['orbit.is_gateway' => false]);
    Process::preventStrayProcesses();
    $commands = [];
    $runtimeResponse = json_encode([
        'success' => [
            'data' => [
                'clients' => [
                    [
                        'id' => 'client-1',
                        'name' => 'laptop',
                        'address' => '10.6.0.7',
                        'enabled' => true,
                        'latest_handshake_at' => null,
                        'kind' => 'admin',
                    ],
                ],
            ],
            'meta' => ['count' => 1],
        ],
    ], JSON_THROW_ON_ERROR);
    Process::fake(function ($process) use (&$commands, $runtimeResponse) {
        $commands[] = (string) $process->command;

        return Process::result($runtimeResponse);
    });

    vpnLocalNode('control');
    $vpnNode = Node::factory()->create([
        'name' => 'vpn-1',
        'role' => 'gateway',
        'host' => 'vpn-1.example.com',
        'user' => 'orbit',
        'orbit_path' => '/home/orbit/orbit',
        'wireguard_address' => '10.6.0.1',
        'status' => 'active',
        ...vpnPinnedHostKey(),
    ]);
    NodeRoleAssignment::factory()->create([
        'node_id' => $vpnNode->id,
        'role' => 'vpn',
        'status' => 'active',
    ]);

    $exitCode = Artisan::call('vpn-client:list', ['--json' => true]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['success']['data']['clients'][0]['name'])->toBe('laptop');

    expect($commands)->toHaveCount(1);

    $command = $commands[0];

    expect($command)->toContain("'orbit'@'{$vpnNode->wireguard_address}'");
    expect($command)->toContain('docker exec -i');
    expect($command)->toContain('--workdir');
    expect($command)->toContain(OrbitRuntimeContainer::SourcePath);
    expect($command)->toContain('orbit-runtime php apps/gateway/artisan vpn-client:list --json');
    expect((bool) preg_match("/bash -lc '\\''php artisan/", $command))->toBeFalse();
});

it('forwards escaped arguments through an absolute orbit runtime artisan path', function (): void {
    config(['orbit.is_gateway' => false]);
    Process::preventStrayProcesses();
    $commands = [];
    $runtimeResponse = json_encode([
        'success' => [
            'data' => [
                'client' => [
                    'id' => 'client-7',
                    'name' => 'laptop',
                    'address' => '10.6.0.7',
                    'enabled' => true,
                    'latest_handshake_at' => null,
                    'kind' => 'admin',
                ],
            ],
            'meta' => ['config_included' => false],
        ],
    ], JSON_THROW_ON_ERROR);
    Process::fake(function ($process) use (&$commands, $runtimeResponse) {
        $commands[] = (string) $process->command;

        return Process::result($runtimeResponse);
    });

    vpnLocalNode('control');
    $vpnNode = Node::factory()->create([
        'name' => 'vpn-1',
        'role' => 'gateway',
        'host' => 'vpn-1.example.com',
        'user' => 'orbit',
        'orbit_path' => '/home/orbit/orbit',
        'wireguard_address' => '10.6.0.1',
        'status' => 'active',
        ...vpnPinnedHostKey(),
    ]);
    NodeRoleAssignment::factory()->create([
        'node_id' => $vpnNode->id,
        'role' => 'vpn',
        'status' => 'active',
    ]);

    $exitCode = Artisan::call('vpn-client:new', ['name' => 'laptop', '--json' => true]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['success']['data']['client']['name'])->toBe('laptop');

    expect($commands)->toHaveCount(1);

    $command = $commands[0];

    expect($command)->toContain('docker exec -i');
    expect($command)->toContain('orbit-runtime sh -c');
    expect($command)->toContain('php apps/gateway/artisan vpn-client:new');
    expect($command)->toContain('laptop');
    expect($command)->not->toContain('php artisan');
    expect((bool) preg_match("/bash -lc '\\''php artisan/", $command))->toBeFalse();
});

it('fails when no active vpn role node is available for forwarded commands', function (): void {
    config(['orbit.is_gateway' => false]);

    vpnLocalNode('control');

    $exitCode = Artisan::call('vpn-client:list', ['--json' => true]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error'])->toMatchArray([
            'code' => 'vpn_runtime_unavailable',
            'message' => 'No active VPN role node is available for VPN administration.',
        ]);
});

it('fails when the active vpn role node cannot run forwarded commands over ssh', function (): void {
    config(['orbit.is_gateway' => false]);
    Process::preventStrayProcesses();
    Process::fake([
        '*' => Process::result(errorOutput: 'Permission denied (publickey).', exitCode: 255),
    ]);

    vpnLocalNode('control');
    $vpnNode = Node::factory()->create([
        'name' => 'vpn-1',
        'role' => 'gateway',
        'host' => 'vpn-1.example.com',
        'user' => 'orbit',
        'orbit_path' => '/home/orbit/orbit',
        'wireguard_address' => '10.6.0.1',
        'status' => 'active',
        ...vpnPinnedHostKey(),
    ]);
    NodeRoleAssignment::factory()->create([
        'node_id' => $vpnNode->id,
        'role' => 'vpn',
        'status' => 'active',
    ]);

    $exitCode = Artisan::call('vpn-client:list', ['--json' => true]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error'])->toMatchArray([
            'code' => 'vpn_runtime_ssh_unavailable',
            'message' => 'Permission denied (publickey).',
        ]);
});

it('logs vpn command activity without secrets', function (): void {
    vpnLocalNode('gateway');
    NodeRoleAssignment::factory()->create([
        'node_id' => Node::query()->firstOrFail()->id,
        'role' => 'vpn',
        'status' => 'active',
    ]);
    bindVpnBackend(new ArrayVpnBackend([
        new VpnBackendClient('client-1', 'laptop', '10.6.0.7', true, null),
    ]));

    $exitCode = Artisan::call('vpn-client:list', ['--totp' => '123456', '--json' => true]);

    $entry = Activity::query()->first();

    expect($exitCode)->toBe(0);
    expect($entry)->not->toBeNull();
    expect($entry->event)->toBe('vpn-client:list');
    expect($entry->properties->get('type'))->toBe('read');
    expect($entry->properties->get('count'))->toBe(1);
    expect(json_encode($entry->properties->toArray(), JSON_THROW_ON_ERROR))->not->toContain('123456');
});

/**
 * @return array<string, mixed>
 */
function vpnPinnedHostKey(): array
{
    return [
        'host_key_type' => 'ssh-ed25519',
        'host_key_public' => 'AAAAC3NzaC1lZDI1NTE5AAAAIVpnForwardingRuntimeExecutorPinnedKey',
        'host_key_fingerprint' => 'SHA256:vpn-forwarding-runtime-executor',
        'host_key_pin_mode' => 'verified',
        'host_key_pinned_at' => now(),
    ];
}
