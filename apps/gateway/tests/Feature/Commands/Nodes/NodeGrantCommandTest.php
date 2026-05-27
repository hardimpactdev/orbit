<?php

declare(strict_types=1);

use App\Http\Gateway\Requests\Nodes\GrantNodeRequest;
use App\Models\LocalGatewaySettings;
use App\Models\NodeRoleAssignment;
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
function nodeGrantRow(array $overrides = []): array
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

function setupGrantGatewayCaller(): void
{
    config(['orbit.is_gateway' => true]);

    DB::table('nodes')->insert(nodeGrantRow([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'environment' => null,
    ]));
}

function setupGrantControlCaller(): void
{
    DB::table('nodes')->insert(nodeGrantRow([
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
function fakeNodeGrantGateway(array|string $body, int $status = 200): MockClient
{
    return MockClient::global([
        GrantNodeRequest::class => MockResponse::make($body, $status),
    ]);
}

describe('node:grant base contract', function (): void {
    it('creates a new grant and returns successfully', function (): void {
        setupGrantGatewayCaller();
        DB::table('nodes')->insert(nodeGrantRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodeGrantRow());

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--preset' => 'operator',
        ]);

        expect($exitCode)->toBe(0);
        expect(DB::table('node_access')->count())->toBe(1);
    });

    it('returns idempotent success when grant already exists', function (): void {
        setupGrantGatewayCaller();
        DB::table('nodes')->insert(nodeGrantRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodeGrantRow());

        Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--preset' => 'operator',
        ]);

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--preset' => 'operator',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['already_granted'])->toBeTrue()
            ->and($payload['success']['data']['action'])->toBe('granted');
    });

    it('fails with node.not_found for missing consuming node', function (): void {
        setupGrantGatewayCaller();
        DB::table('nodes')->insert(nodeGrantRow());

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'missing',
            'serving_node' => 'app-1',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('node.not_found')
            ->and($payload['error']['meta']['field'])->toBe('consuming_node');
    });

    it('fails with node.not_found for missing serving node', function (): void {
        setupGrantGatewayCaller();
        DB::table('nodes')->insert(nodeGrantRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'missing',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('node.not_found')
            ->and($payload['error']['meta']['field'])->toBe('serving_node');
    });

    it('allows self-grants', function (): void {
        setupGrantGatewayCaller();
        DB::table('nodes')->insert(nodeGrantRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'control-1',
            '--preset' => 'operator',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['action'])->toBe('granted')
            ->and(DB::table('node_access')->count())->toBe(1);
    });

    it('creates a grant with --preset permissions', function (): void {
        setupGrantGatewayCaller();
        DB::table('nodes')->insert(nodeGrantRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodeGrantRow());

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--preset' => 'operator',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);
        $grant = DB::table('node_access')->first();

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['action'])->toBe('granted')
            ->and($grant->permissions)->toBe(json_encode(['app:read', 'database:read', 'doctor:verify', 'firewall_rule:read', 'node:read', 'tool:read', 'tool:restart']));
    });

    it('creates a grant with --permissions', function (): void {
        setupGrantGatewayCaller();
        DB::table('nodes')->insert(nodeGrantRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodeGrantRow());

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--permissions' => 'node:read,tool:read',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);
        $grant = DB::table('node_access')->first();

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['action'])->toBe('granted')
            ->and($grant->permissions)->toBe(json_encode(['node:read', 'tool:read']));
    });

    it('does not modify existing grant permissions', function (): void {
        setupGrantGatewayCaller();
        DB::table('nodes')->insert(nodeGrantRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodeGrantRow());

        Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--preset' => 'operator',
        ]);

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--permissions' => 'node:read',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);
        $grant = DB::table('node_access')->first();

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['already_granted'])->toBeTrue()
            ->and($grant->permissions)->toBe(json_encode(['app:read', 'database:read', 'doctor:verify', 'firewall_rule:read', 'node:read', 'tool:read', 'tool:restart']));
    });

    it('warns about redundant permissions', function (): void {
        setupGrantGatewayCaller();
        DB::table('nodes')->insert(nodeGrantRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodeGrantRow());

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--permissions' => 'node:read,node:list',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['meta']['warnings'][0]['code'])->toBe('node.redundant_permissions')
            ->and($payload['success']['meta']['warnings'][0]['permissions'])->toBe(['node:list']);
    });

    it('requires --force for gateway-admin grants', function (): void {
        setupGrantGatewayCaller();
        DB::table('nodes')->insert(nodeGrantRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        $gatewayId = (int) DB::table('nodes')->insertGetId(nodeGrantRow([
            'name' => 'target-gateway',
            'role' => 'gateway',
            'environment' => null,
        ]));
        NodeRoleAssignment::factory()->create([
            'node_id' => $gatewayId,
            'role' => 'gateway',
            'status' => 'active',
        ]);

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'target-gateway',
            '--preset' => 'gateway-admin',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('force');
    });

    it('rejects control-node callers with gateway_unavailable', function (): void {
        config(['orbit.is_gateway' => false]);

        DB::table('nodes')->insert(nodeGrantRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodeGrantRow());

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--preset' => 'operator',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('gateway_unavailable')
            ->and($payload['error']['message'])->toBe('Gateway connection is required to grant node access.')
            ->and($payload['error']['meta'])->toBe([]);
    });
});

describe('node:grant control forwarding', function (): void {
    // covered by Nodes/NodeGrantOnControlNodeContractTest.php
    it('forwards configured control-node grants to the gateway without local target rows', function (): void {
        config(['orbit.is_gateway' => false]);

        setupGrantControlCaller();

        $mock = fakeNodeGrantGateway([
            'success' => [
                'data' => [
                    'consuming_node' => 'control-1',
                    'serving_node' => 'app-1',
                    'action' => 'granted',
                    'already_granted' => false,
                    'permissions' => ['*'],
                ],
            ],
        ]);

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--preset' => 'operator',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data'])->toBe([
                'consuming_node' => 'control-1',
                'serving_node' => 'app-1',
                'action' => 'granted',
                'already_granted' => false,
                'permissions' => ['*'],
            ])
            ->and(DB::table('nodes')->where('name', 'app-1')->exists())->toBeFalse()
            ->and(DB::table('node_access')->count())->toBe(0);

        $mock->assertSent(fn (GrantNodeRequest $request): bool => $request->resolveEndpoint() === '/api/nodes/grant'
            && $request->body()->all() === [
                'consuming_node' => 'control-1',
                'serving_node' => 'app-1',
                'preset' => 'operator',
            ]);
    });

    // covered by Nodes/NodeGrantOnControlNodeContractTest.php
    it('renders forwarded already-granted success with human output', function (): void {
        config(['orbit.is_gateway' => false]);

        setupGrantControlCaller();

        fakeNodeGrantGateway([
            'success' => [
                'data' => [
                    'consuming_node' => 'control-1',
                    'serving_node' => 'app-1',
                    'action' => 'granted',
                    'already_granted' => true,
                    'permissions' => ['*'],
                ],
            ],
        ]);

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--preset' => 'operator',
        ]);

        expect($exitCode)->toBe(0);
        expect(Artisan::output())->toContain("'control-1' already has access to 'app-1'");
    });

    // covered by Nodes/NodeGrantOnControlNodeContractTest.php
    it('preserves structured gateway errors when forwarding', function (array $error): void {
        config(['orbit.is_gateway' => false]);

        setupGrantControlCaller();

        fakeNodeGrantGateway(['error' => $error], 422);

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--preset' => 'operator',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error'])->toBe($error);
    })->with([
        'authorization failure' => [[
            'code' => 'authorization_failed',
            'message' => 'This action requires the node:grant permission on a grant to the gateway.',
            'meta' => [
                'reason' => 'missing_permission',
                'missing_permission' => 'node:grant',
                'serving_node' => 'gateway-1',
            ],
        ]],
        'not found' => [[
            'code' => 'node.not_found',
            'message' => "Serving node 'app-1' not found.",
            'meta' => [
                'field' => 'serving_node',
                'name' => 'app-1',
            ],
        ]],
        'policy violation' => [[
            'code' => 'node.grant_policy_violation',
            'message' => 'A node cannot be granted access to itself.',
            'meta' => [
                'consuming_node' => 'control-1',
                'serving_node' => 'control-1',
                'reason' => 'self_grant',
            ],
        ]],
    ]);
});

describe('node:grant safety', function (): void {
    it('does not invoke ssh or external processes during grant', function (): void {
        setupGrantGatewayCaller();
        Process::fake();
        Process::preventStrayProcesses();

        DB::table('nodes')->insert(nodeGrantRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodeGrantRow());

        Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
        ]);

        Process::assertNothingRan();
    });

    it('makes only targeted registry mutations', function (): void {
        setupGrantGatewayCaller();
        DB::table('nodes')->insert(nodeGrantRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodeGrantRow());

        $before = (array) DB::table('nodes')->where('name', 'app-1')->first();

        Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--preset' => 'operator',
        ]);

        $after = (array) DB::table('nodes')->where('name', 'app-1')->first();

        expect($after)->toBe($before);
        expect(DB::table('node_access')->count())->toBe(1);
    });
});
