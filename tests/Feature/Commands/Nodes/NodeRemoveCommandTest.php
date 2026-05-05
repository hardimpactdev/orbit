<?php

declare(strict_types=1);

use App\Http\Gateway\Requests\Nodes\RemoveNodeRequest;
use App\Models\LocalGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

beforeEach(fn (): null => MockClient::destroyGlobal());
afterEach(fn (): null => MockClient::destroyGlobal());

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nodeRemoveRow(array $overrides = []): array
{
    return array_merge([
        'name' => 'app-1',
        'role' => 'app',
        'host' => '10.6.0.7',
        'wireguard_address' => '10.6.0.7',
        'ssh_user' => 'nckrtl',
        'user' => 'nckrtl',
        'orbit_path' => '/home/nckrtl/orbit',
        'status' => 'active',
        'is_local' => false,
        'environment' => 'development',
        'platform' => 'ubuntu_24-04',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

function setupNodeRemoveGatewayCaller(): void
{
    DB::table('nodes')->insert(nodeRemoveRow([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'environment' => null,
        'is_local' => true,
    ]));
}

function setupNodeRemoveControlCaller(): void
{
    DB::table('nodes')->insert(nodeRemoveRow([
        'name' => 'control-1',
        'role' => 'control',
        'environment' => null,
        'is_local' => true,
    ]));

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.2',
        'gateway_wg_ip' => '10.6.0.2',
        'ca_pem_path' => '/tmp/fake-orbit-ca.pem',
    ])->save();
}

/**
 * @param  array<string, mixed>|string  $body
 */
function fakeNodeRemoveGateway(array|string $body, int $status = 200): MockClient
{
    return MockClient::global([
        RemoveNodeRequest::class => MockResponse::make($body, $status),
    ]);
}

describe('node:remove base contract', function (): void {
    it('removes a node and returns successfully', function (): void {
        setupNodeRemoveGatewayCaller();
        DB::table('nodes')->insert(nodeRemoveRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodeRemoveRow());
        DB::table('node_access')->insert([
            'consumer_node_id' => 2,
            'serving_node_id' => 3,
        ]);

        $exitCode = Artisan::call('node:remove', [
            'name' => 'app-1',
            '--force' => true,
        ]);

        expect($exitCode)->toBe(0);
        expect(DB::table('nodes')->where('name', 'app-1')->exists())->toBeFalse();
        expect(DB::table('node_access')->count())->toBe(0);
    });

    it('counts grants removed across both consumer and serving directions', function (): void {
        setupNodeRemoveGatewayCaller();
        DB::table('nodes')->insert(nodeRemoveRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodeRemoveRow());
        DB::table('nodes')->insert(nodeRemoveRow([
            'name' => 'app-2',
        ]));
        // app-1 is consumer
        DB::table('node_access')->insert([
            'consumer_node_id' => 3,
            'serving_node_id' => 2,
        ]);
        // app-1 is serving
        DB::table('node_access')->insert([
            'consumer_node_id' => 4,
            'serving_node_id' => 3,
        ]);
        // unrelated grant
        DB::table('node_access')->insert([
            'consumer_node_id' => 2,
            'serving_node_id' => 4,
        ]);

        $exitCode = Artisan::call('node:remove', [
            'name' => 'app-1',
            '--force' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['grants_removed'])->toBe(2)
            ->and($payload['success']['data']['action'])->toBe('removed');
        expect(DB::table('node_access')->count())->toBe(1);
    });

    it('rejects gateway-node removal before side effects', function (): void {
        setupNodeRemoveGatewayCaller();
        DB::table('nodes')->insert(nodeRemoveRow([
            'name' => 'gateway-2',
            'role' => 'gateway',
            'environment' => null,
        ]));

        $exitCode = Artisan::call('node:remove', [
            'name' => 'gateway-2',
            '--force' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('node.gateway_removal_denied')
            ->and($payload['error']['meta']['role'])->toBe('gateway');
        expect(DB::table('nodes')->where('name', 'gateway-2')->exists())->toBeTrue();
    });

    it('rejects app-node callers before side effects', function (): void {
        DB::table('nodes')->insert(nodeRemoveRow([
            'name' => 'mini',
            'role' => 'app',
            'is_local' => true,
            'environment' => 'development',
        ]));
        DB::table('nodes')->insert(nodeRemoveRow());

        $exitCode = Artisan::call('node:remove', [
            'name' => 'app-1',
            '--force' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('caller_role_not_allowed')
            ->and($payload['error']['meta']['caller_role'])->toBe('app');
        expect(DB::table('nodes')->where('name', 'app-1')->exists())->toBeTrue();
    });

    it('rejects unconfigured control-node callers with gateway_unavailable', function (): void {
        DB::table('nodes')->insert(nodeRemoveRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
            'is_local' => true,
        ]));
        DB::table('nodes')->insert(nodeRemoveRow());

        $exitCode = Artisan::call('node:remove', [
            'name' => 'app-1',
            '--force' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('gateway_unavailable');
    });

    it('rejects unknown callers with local_context_invalid', function (): void {
        DB::table('nodes')->insert(nodeRemoveRow([
            'name' => 'bogus',
            'role' => 'bogus',
            'environment' => null,
            'is_local' => true,
        ]));
        DB::table('nodes')->insert(nodeRemoveRow());

        $exitCode = Artisan::call('node:remove', [
            'name' => 'app-1',
            '--force' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('local_context_invalid')
            ->and($payload['error']['meta']['caller_role'])->toBe('unknown');
    });

    it('skips consent check with --force', function (): void {
        setupNodeRemoveGatewayCaller();
        DB::table('nodes')->insert(nodeRemoveRow());

        $exitCode = Artisan::call('node:remove', [
            'name' => 'app-1',
            '--force' => true,
        ]);

        expect($exitCode)->toBe(0);
        expect(DB::table('nodes')->where('name', 'app-1')->exists())->toBeFalse();
    });

    it('fails with validation_failed in non-interactive mode without --force', function (): void {
        setupNodeRemoveGatewayCaller();
        DB::table('nodes')->insert(nodeRemoveRow());

        $exitCode = Artisan::call('node:remove', [
            'name' => 'app-1',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('force');
    });

    it('fails with node.not_found when target node does not exist', function (): void {
        setupNodeRemoveGatewayCaller();

        $exitCode = Artisan::call('node:remove', [
            'name' => 'missing',
            '--force' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('node.not_found')
            ->and($payload['error']['meta']['name'])->toBe('missing');
    });

    it('does not treat already-absent removal as idempotent success', function (): void {
        setupNodeRemoveGatewayCaller();

        $exitCode = Artisan::call('node:remove', [
            'name' => 'missing',
            '--force' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('node.not_found');
    });
});

describe('node:remove control forwarding', function (): void {
    it('forwards configured control-node removals to the gateway without local target rows', function (): void {
        setupNodeRemoveControlCaller();

        $mock = fakeNodeRemoveGateway([
            'success' => [
                'data' => [
                    'name' => 'app-1',
                    'action' => 'removed',
                    'removed_self' => false,
                    'wireguard_peer_removed' => false,
                    'grants_removed' => 2,
                ],
            ],
        ]);

        $exitCode = Artisan::call('node:remove', [
            'name' => 'app-1',
            '--force' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data'])->toBe([
                'name' => 'app-1',
                'action' => 'removed',
                'removed_self' => false,
                'wireguard_peer_removed' => false,
                'grants_removed' => 2,
            ])
            ->and(DB::table('nodes')->where('name', 'app-1')->exists())->toBeFalse()
            ->and(DB::table('node_access')->count())->toBe(0);

        $mock->assertSent(fn (RemoveNodeRequest $request): bool => $request->resolveEndpoint() === '/api/nodes/app-1'
            && $request->body()->all() === [
                'destructive_consent' => true,
                'destructive_consent_source' => 'force',
            ]);
    });

    it('renders forwarded success with human output', function (): void {
        setupNodeRemoveControlCaller();

        fakeNodeRemoveGateway([
            'success' => [
                'data' => [
                    'name' => 'app-1',
                    'action' => 'removed',
                    'removed_self' => false,
                    'wireguard_peer_removed' => false,
                    'grants_removed' => 0,
                ],
            ],
        ]);

        $exitCode = Artisan::call('node:remove', [
            'name' => 'app-1',
            '--force' => true,
        ]);

        expect($exitCode)->toBe(0);
        expect(Artisan::output())->toContain("Node 'app-1' removed");
    });

    it('preserves structured gateway authorization failures', function (): void {
        setupNodeRemoveControlCaller();

        fakeNodeRemoveGateway([
            'error' => [
                'code' => 'authorization_failed',
                'message' => 'This control node is not authorized to remove nodes.',
                'meta' => [
                    'required_node' => 'gateway-1',
                    'caller_role' => 'control',
                ],
            ],
        ], 403);

        $exitCode = Artisan::call('node:remove', [
            'name' => 'app-1',
            '--force' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('authorization_failed')
            ->and($payload['error']['message'])->toBe('This control node is not authorized to remove nodes.')
            ->and($payload['error']['meta'])->toBe([
                'required_node' => 'gateway-1',
                'caller_role' => 'control',
            ]);
    });

    it('validates missing --force locally before forwarding in non-interactive mode', function (): void {
        setupNodeRemoveControlCaller();

        $mock = fakeNodeRemoveGateway([
            'success' => ['data' => ['name' => 'app-1']],
        ]);

        $exitCode = Artisan::call('node:remove', [
            'name' => 'app-1',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('force');

        $mock->assertNothingSent();
    });

    it('does not mutate local Node or NodeAccess for forwarded control calls', function (): void {
        setupNodeRemoveControlCaller();
        DB::table('nodes')->insert(nodeRemoveRow([
            'name' => 'app-1',
        ]));
        $controlNodeId = DB::table('nodes')->where('name', 'control-1')->value('id');
        $appNodeId = DB::table('nodes')->where('name', 'app-1')->value('id');

        DB::table('node_access')->insert([
            'consumer_node_id' => $controlNodeId,
            'serving_node_id' => $appNodeId,
        ]);

        fakeNodeRemoveGateway([
            'success' => [
                'data' => [
                    'name' => 'app-1',
                    'action' => 'removed',
                    'removed_self' => false,
                    'wireguard_peer_removed' => false,
                    'grants_removed' => 1,
                ],
            ],
        ]);

        Artisan::call('node:remove', [
            'name' => 'app-1',
            '--force' => true,
            '--json' => true,
        ]);

        expect(DB::table('nodes')->where('name', 'app-1')->exists())->toBeTrue();
        expect(DB::table('node_access')->count())->toBe(1);
    });
});

describe('node:remove safety', function (): void {
    it('does not invoke ssh or external processes during removal', function (): void {
        setupNodeRemoveGatewayCaller();
        DB::table('nodes')->insert(nodeRemoveRow());
        Process::fake();
        Process::preventStrayProcesses();

        Artisan::call('node:remove', [
            'name' => 'app-1',
            '--force' => true,
        ]);

        Process::assertNothingRan();
    });

    it('removes only the targeted node record', function (): void {
        setupNodeRemoveGatewayCaller();
        DB::table('nodes')->insert(nodeRemoveRow([
            'name' => 'app-1',
        ]));
        DB::table('nodes')->insert(nodeRemoveRow([
            'name' => 'app-2',
        ]));

        Artisan::call('node:remove', [
            'name' => 'app-1',
            '--force' => true,
        ]);

        expect(DB::table('nodes')->where('name', 'app-1')->exists())->toBeFalse();
        expect(DB::table('nodes')->where('name', 'app-2')->exists())->toBeTrue();
    });
});
