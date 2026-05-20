<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Data\Vpn\VpnBackendClient;
use App\Models\Node;
use App\Services\Vpn\ArrayVpnBackend;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

it('lists vpn clients with json metadata on gateway callers', function (): void {
    vpnLocalNode('gateway');
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

it('runs on gateway machines without client-side role checks', function (): void {
    vpnLocalNode('app');
    $backend = new ArrayVpnBackend;
    bindVpnBackend($backend);

    $exitCode = Artisan::call('vpn-client:list', ['--json' => true]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['success']['data']['clients'])->toBe([])
        ->and($backend->listCalled)->toBeTrue();
});

it('forwards control callers to the gateway over remote shell', function (): void {
    config(['orbit.is_gateway' => false]);

    vpnLocalNode('control');
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'wireguard_address' => '10.6.0.1',
        'status' => 'active',
    ]);

    app()->instance(RemoteShell::class, new class implements RemoteShell
    {
        public string $script = '';

        public function run(Node $node, string $script, array $options = []): RemoteShellResult
        {
            $this->script = $script;

            return new RemoteShellResult(
                exitCode: 0,
                stdout: json_encode([
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
                ], JSON_THROW_ON_ERROR),
                stderr: '',
                durationMs: 1,
            );
        }
    });

    $exitCode = Artisan::call('vpn-client:list', ['--json' => true]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['success']['data']['clients'][0]['name'])->toBe('laptop')
        ->and(app(RemoteShell::class)->script)->toContain('vpn-client:list')
        ->and(app(RemoteShell::class)->script)->toContain('--json');
});

it('logs vpn command activity without secrets', function (): void {
    vpnLocalNode('gateway');
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
