<?php

declare(strict_types=1);

use App\Http\Gateway\Requests\Nodes\RevokeNodeRequest;
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
function nodeRevokeRow(array $overrides = []): array
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

function setupNodeRevokeGatewayCaller(): void
{
    config(['orbit.is_gateway' => true]);

    DB::table('nodes')->insert(nodeRevokeRow([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'environment' => null,
    ]));
}

function setupRevokeControlCaller(): void
{
    DB::table('nodes')->insert(nodeRevokeRow([
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
function fakeNodeRevokeGateway(array|string $body, int $status = 200): MockClient
{
    return MockClient::global([
        RevokeNodeRequest::class => MockResponse::make($body, $status),
    ]);
}

describe('node:revoke base contract', function (): void {
    it('revokes a grant and returns successfully', function (): void {
        setupNodeRevokeGatewayCaller();
        DB::table('nodes')->insert(nodeRevokeRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodeRevokeRow());
        DB::table('node_access')->insert([
            'consumer_node_id' => 2,
            'serving_node_id' => 3,
        ]);

        $exitCode = Artisan::call('node:revoke', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--force' => true,
        ]);

        expect($exitCode)->toBe(0);
        expect(DB::table('node_access')->count())->toBe(0);
    });

    it('returns idempotent success when grant is already absent', function (): void {
        setupNodeRevokeGatewayCaller();
        DB::table('nodes')->insert(nodeRevokeRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodeRevokeRow());

        $exitCode = Artisan::call('node:revoke', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--force' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['already_absent'])->toBeTrue()
            ->and($payload['success']['data']['action'])->toBe('revoked');
    });

    it('fails with validation_failed when consuming_node is missing', function (): void {
        setupNodeRevokeGatewayCaller();
        DB::table('nodes')->insert(nodeRevokeRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodeRevokeRow());

        $exitCode = Artisan::call('node:revoke', [
            'serving_node' => 'app-1',
            '--force' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['message'])->toBe('Consuming node is required.')
            ->and($payload['error']['meta'])->toBe(['field' => 'consuming_node']);
    });

    it('fails with validation_failed when serving_node is missing', function (): void {
        setupNodeRevokeGatewayCaller();
        DB::table('nodes')->insert(nodeRevokeRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodeRevokeRow());

        $exitCode = Artisan::call('node:revoke', [
            'consuming_node' => 'control-1',
            '--force' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['message'])->toBe('Serving node is required.')
            ->and($payload['error']['meta'])->toBe(['field' => 'serving_node']);
    });

    it('fails with node.not_found for missing consuming node', function (): void {
        setupNodeRevokeGatewayCaller();
        DB::table('nodes')->insert(nodeRevokeRow());

        $exitCode = Artisan::call('node:revoke', [
            'consuming_node' => 'missing',
            'serving_node' => 'app-1',
            '--force' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('node.not_found')
            ->and($payload['error']['message'])->toBe("Node 'missing' not found.")
            ->and($payload['error']['meta'])->toBe(['name' => 'missing']);
    });

    it('fails with node.not_found for missing serving node', function (): void {
        setupNodeRevokeGatewayCaller();
        DB::table('nodes')->insert(nodeRevokeRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));

        $exitCode = Artisan::call('node:revoke', [
            'consuming_node' => 'control-1',
            'serving_node' => 'missing',
            '--force' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('node.not_found')
            ->and($payload['error']['message'])->toBe("Node 'missing' not found.")
            ->and($payload['error']['meta'])->toBe(['name' => 'missing']);
    });

    it('rejects control-node callers with gateway_unavailable', function (): void {
        config(['orbit.is_gateway' => false]);

        DB::table('nodes')->insert(nodeRevokeRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodeRevokeRow());

        $exitCode = Artisan::call('node:revoke', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--force' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('gateway_unavailable');
    });

    it('skips consent check with --force', function (): void {
        setupNodeRevokeGatewayCaller();
        DB::table('nodes')->insert(nodeRevokeRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodeRevokeRow());
        DB::table('node_access')->insert([
            'consumer_node_id' => 2,
            'serving_node_id' => 3,
        ]);

        $exitCode = Artisan::call('node:revoke', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--force' => true,
        ]);

        expect($exitCode)->toBe(0);
        expect(DB::table('node_access')->count())->toBe(0);
    });

    it('fails with validation_failed in non-interactive mode without --force', function (): void {
        setupNodeRevokeGatewayCaller();
        DB::table('nodes')->insert(nodeRevokeRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodeRevokeRow());

        $exitCode = Artisan::call('node:revoke', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('force');
    });
});

describe('node:revoke control forwarding', function (): void {
    it('forwards configured control-node revokes to the gateway without local target rows', function (): void {
        config(['orbit.is_gateway' => false]);

        setupRevokeControlCaller();

        $mock = fakeNodeRevokeGateway([
            'success' => [
                'data' => [
                    'consuming_node' => 'control-1',
                    'serving_node' => 'app-1',
                    'action' => 'revoked',
                    'already_absent' => false,
                    'self_lockout' => false,
                ],
            ],
        ]);

        $exitCode = Artisan::call('node:revoke', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--force' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data'])->toBe([
                'consuming_node' => 'control-1',
                'serving_node' => 'app-1',
                'action' => 'revoked',
                'already_absent' => false,
                'self_lockout' => false,
            ])
            ->and(DB::table('node_access')->count())->toBe(0);

        $mock->assertSent(fn (RevokeNodeRequest $request): bool => $request->resolveEndpoint() === '/api/nodes/revoke'
            && $request->body()->all() === [
                'consuming_node' => 'control-1',
                'serving_node' => 'app-1',
                'force' => true,
            ]);
    });

    it('renders forwarded already-absent success with human output', function (): void {
        config(['orbit.is_gateway' => false]);

        setupRevokeControlCaller();

        fakeNodeRevokeGateway([
            'success' => [
                'data' => [
                    'consuming_node' => 'control-1',
                    'serving_node' => 'app-1',
                    'action' => 'revoked',
                    'already_absent' => true,
                    'self_lockout' => false,
                ],
            ],
        ]);

        $exitCode = Artisan::call('node:revoke', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--force' => true,
        ]);

        expect($exitCode)->toBe(0);
        expect(Artisan::output())->toContain("Access from 'control-1' to 'app-1' was already revoked");
    });

    it('renders forwarded self-lockout success with human output', function (): void {
        config(['orbit.is_gateway' => false]);

        setupRevokeControlCaller();

        fakeNodeRevokeGateway([
            'success' => [
                'data' => [
                    'consuming_node' => 'control-1',
                    'serving_node' => 'gateway-1',
                    'action' => 'revoked',
                    'already_absent' => false,
                    'self_lockout' => true,
                ],
            ],
        ]);

        $exitCode = Artisan::call('node:revoke', [
            'consuming_node' => 'control-1',
            'serving_node' => 'gateway-1',
            '--force' => true,
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->toContain("Access from 'control-1' to 'gateway-1' revoked")
            ->and($output)->toContain('This machine no longer has Orbit gateway access.');
    });

    it('preserves structured gateway errors when forwarding', function (array $error): void {
        config(['orbit.is_gateway' => false]);

        setupRevokeControlCaller();

        fakeNodeRevokeGateway(['error' => $error], 422);

        $exitCode = Artisan::call('node:revoke', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--force' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error'])->toBe($error);
    })->with([
        'authorization failure' => [[
            'code' => 'authorization_failed',
            'message' => 'This control node is not authorized to revoke grants.',
            'meta' => [
                'required_node' => 'gateway-1',
                'caller_role' => 'control',
            ],
        ]],
        'not found' => [[
            'code' => 'node.not_found',
            'message' => "Consuming node 'missing' not found.",
            'meta' => [
                'field' => 'consuming_node',
                'name' => 'missing',
            ],
        ]],
        'missing force' => [[
            'code' => 'validation_failed',
            'message' => 'Use --force to revoke this grant.',
            'meta' => [
                'field' => 'force',
            ],
        ]],
    ]);

    it('does not mutate local node_access for forwarded control calls', function (): void {
        config(['orbit.is_gateway' => false]);

        setupRevokeControlCaller();
        DB::table('nodes')->insert(nodeRevokeRow([
            'name' => 'app-1',
            'role' => 'app',
        ]));
        $controlNodeId = DB::table('nodes')->where('name', 'control-1')->value('id');
        $appNodeId = DB::table('nodes')->where('name', 'app-1')->value('id');

        DB::table('node_access')->insert([
            'consumer_node_id' => $controlNodeId,
            'serving_node_id' => $appNodeId,
        ]);

        fakeNodeRevokeGateway([
            'success' => [
                'data' => [
                    'consuming_node' => 'control-1',
                    'serving_node' => 'app-1',
                    'action' => 'revoked',
                    'already_absent' => false,
                    'self_lockout' => false,
                ],
            ],
        ]);

        $exitCode = Artisan::call('node:revoke', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--force' => true,
            '--json' => true,
        ]);

        expect($exitCode)->toBe(0);
        expect(DB::table('node_access')->count())->toBe(1);
    });

    it('fails with validation_failed before gateway call when force is missing for control callers', function (): void {
        config(['orbit.is_gateway' => false]);

        setupRevokeControlCaller();

        $mock = fakeNodeRevokeGateway([
            'success' => [
                'data' => [
                    'consuming_node' => 'control-1',
                    'serving_node' => 'app-1',
                    'action' => 'revoked',
                    'already_absent' => false,
                    'self_lockout' => false,
                ],
            ],
        ]);

        $exitCode = Artisan::call('node:revoke', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('force');

        $mock->assertNothingSent();
    });
});

describe('node:revoke safety', function (): void {
    it('does not invoke ssh or external processes during revoke', function (): void {
        setupNodeRevokeGatewayCaller();
        Process::fake();
        Process::preventStrayProcesses();

        DB::table('nodes')->insert(nodeRevokeRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodeRevokeRow());
        DB::table('node_access')->insert([
            'consumer_node_id' => 2,
            'serving_node_id' => 3,
        ]);

        Artisan::call('node:revoke', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--force' => true,
        ]);

        Process::assertNothingRan();
    });

    it('makes only targeted registry mutations', function (): void {
        setupNodeRevokeGatewayCaller();
        DB::table('nodes')->insert(nodeRevokeRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodeRevokeRow());
        DB::table('node_access')->insert([
            'consumer_node_id' => 2,
            'serving_node_id' => 3,
        ]);

        $before = (array) DB::table('nodes')->where('name', 'app-1')->first();

        Artisan::call('node:revoke', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--force' => true,
        ]);

        $after = (array) DB::table('nodes')->where('name', 'app-1')->first();

        expect($after)->toBe($before);
        expect(DB::table('node_access')->count())->toBe(0);
    });
});
