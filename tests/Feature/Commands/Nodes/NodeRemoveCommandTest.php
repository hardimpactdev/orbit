<?php

declare(strict_types=1);

use App\Http\Gateway\Requests\Nodes\RemoveNodeRequest;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Nodes\DevelopmentDnsMappingEnactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    MockClient::destroyGlobal();
    bindDevelopmentDnsMappingTestDoubles('node-remove-command-dns');
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    File::deleteDirectory(app(DevelopmentDnsMappingEnactor::class)->configDir());
});

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
        'user' => 'nckrtl',
        'orbit_path' => '/home/nckrtl/orbit',
        'status' => 'active',
        'environment' => 'development',
        'platform' => 'ubuntu_24-04',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

function setupNodeRemoveGatewayCaller(): void
{
    config(['orbit.is_gateway' => true]);

    $nodeId = (int) DB::table('nodes')->insertGetId(nodeRemoveRow([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'environment' => null,
    ]));

    NodeRoleAssignment::factory()->create([
        'node_id' => $nodeId,
        'role' => 'gateway',
        'status' => 'active',
    ]);
}

function setupNodeRemoveControlCaller(): void
{
    DB::table('nodes')->insert(nodeRemoveRow([
        'name' => 'control-1',
        'role' => 'control',
        'environment' => null,
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

    it('removes a gateway-managed WireGuard peer for the removed node', function (): void {
        setupNodeRemoveGatewayCaller();
        DB::table('nodes')->insert(nodeRemoveRow());
        $nodeId = DB::table('nodes')->where('name', 'app-1')->value('id');

        DB::table('wireguard_peers')->insert([
            'node_id' => $nodeId,
            'public_key' => 'app-public-key',
            'private_key' => 'app-private-key',
            'allowed_ips' => '10.6.0.7/32',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exitCode = Artisan::call('node:remove', [
            'name' => 'app-1',
            '--force' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['wireguard_peer_removed'])->toBeTrue()
            ->and(DB::table('wireguard_peers')->where('node_id', $nodeId)->exists())->toBeFalse();
    });

    it('continues successfully when the WireGuard peer is already absent', function (): void {
        setupNodeRemoveGatewayCaller();
        DB::table('nodes')->insert(nodeRemoveRow());

        $exitCode = Artisan::call('node:remove', [
            'name' => 'app-1',
            '--force' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['wireguard_peer_removed'])->toBeFalse();
    });

    it('removes the gateway-owned development dns mapping for removed development app nodes', function (): void {
        setupNodeRemoveGatewayCaller();
        DB::table('nodes')->insert(nodeRemoveRow([
            'tld' => 'test',
            'wireguard_address' => '10.6.0.7',
        ]));

        $node = Node::query()->where('name', 'app-1')->firstOrFail();
        DB::table('node_roles')->insert([
            'node_id' => $node->id,
            'role' => 'app-development',
            'status' => 'active',
            'settings' => json_encode(['tld' => 'test'], JSON_THROW_ON_ERROR),
            'last_error' => null,
            'converged_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        app(DevelopmentDnsMappingEnactor::class)->converge($node);

        $mappingPath = app(DevelopmentDnsMappingEnactor::class)->configDir().'/test.conf';

        expect(File::exists($mappingPath))->toBeTrue();

        $exitCode = Artisan::call('node:remove', [
            'name' => 'app-1',
            '--force' => true,
            '--json' => true,
        ]);

        expect($exitCode)->toBe(0);
        expect(File::exists($mappingPath))->toBeFalse();
    });

    it('rejects gateway-node removal before side effects', function (): void {
        setupNodeRemoveGatewayCaller();
        $gatewayId = (int) DB::table('nodes')->insertGetId(nodeRemoveRow([
            'name' => 'gateway-2',
            'role' => 'gateway',
            'environment' => null,
        ]));
        NodeRoleAssignment::factory()->create([
            'node_id' => $gatewayId,
            'role' => 'gateway',
            'status' => 'active',
        ]);

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

    it('rejects removal for nodes with an assigned gateway role before side effects', function (): void {
        config(['orbit.is_gateway' => true]);

        $gateway = Node::query()->create(nodeRemoveRow([
            'name' => 'gateway-1',
            'role' => 'control',
            'environment' => null,
        ]));

        NodeRoleAssignment::factory()->create([
            'node_id' => $gateway->id,
            'role' => 'gateway',
            'status' => 'active',
        ]);

        $exitCode = Artisan::call('node:remove', [
            'name' => 'gateway-1',
            '--force' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('node.gateway_removal_denied')
            ->and(DB::table('nodes')->where('name', 'gateway-1')->exists())->toBeTrue();
    });

    it('rejects unconfigured control-node callers with gateway_unavailable', function (): void {
        config(['orbit.is_gateway' => false]);

        DB::table('nodes')->insert(nodeRemoveRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
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
        config(['orbit.is_gateway' => false]);

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
        config(['orbit.is_gateway' => false]);

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
        config(['orbit.is_gateway' => false]);

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
        config(['orbit.is_gateway' => false]);

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
        config(['orbit.is_gateway' => false]);

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
