<?php

declare(strict_types=1);

use App\Http\Gateway\Requests\Nodes\NodePermissionsRequest;
use App\Models\LocalGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

beforeEach(fn (): null => MockClient::destroyGlobal());
afterEach(fn (): null => MockClient::destroyGlobal());

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nodePermissionsRow(array $overrides = []): array
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

function setupPermissionsGatewayCaller(): void
{
    config(['orbit.is_gateway' => true]);

    DB::table('nodes')->insert(nodePermissionsRow([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'environment' => null,
    ]));
}

function setupPermissionsControlCaller(): void
{
    DB::table('nodes')->insert(nodePermissionsRow([
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
function fakeNodePermissionsGateway(array|string $body, int $status = 200): MockClient
{
    return MockClient::global([
        NodePermissionsRequest::class => MockResponse::make($body, $status),
    ]);
}

describe('node:permissions base contract', function (): void {
    it('reads permissions for an existing grant with no options', function (): void {
        setupPermissionsGatewayCaller();
        DB::table('nodes')->insert(nodePermissionsRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodePermissionsRow());

        $controlId = DB::table('nodes')->where('name', 'control-1')->value('id');
        $appId = DB::table('nodes')->where('name', 'app-1')->value('id');

        DB::table('node_access')->insert([
            'consumer_node_id' => $controlId,
            'serving_node_id' => $appId,
            'permissions' => json_encode(['node:read', 'tool:read']),
        ]);

        $exitCode = Artisan::call('node:permissions', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['action'])->toBe('read')
            ->and($payload['success']['data']['permissions'])->toBe(['node:read', 'tool:read']);
    });

    it('fails with node.grant_not_found when reading missing grant', function (): void {
        setupPermissionsGatewayCaller();
        DB::table('nodes')->insert(nodePermissionsRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodePermissionsRow());

        $exitCode = Artisan::call('node:permissions', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('node.grant_not_found');
    });

    it('sets permissions with --preset on existing grant', function (): void {
        setupPermissionsGatewayCaller();
        DB::table('nodes')->insert(nodePermissionsRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodePermissionsRow());

        $controlId = DB::table('nodes')->where('name', 'control-1')->value('id');
        $appId = DB::table('nodes')->where('name', 'app-1')->value('id');

        DB::table('node_access')->insert([
            'consumer_node_id' => $controlId,
            'serving_node_id' => $appId,
            'permissions' => json_encode(['node:read']),
        ]);

        $exitCode = Artisan::call('node:permissions', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--preset' => 'operator',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);
        $grant = DB::table('node_access')->first();

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['action'])->toBe('updated')
            ->and($grant->permissions)->toBe(json_encode(['app:read', 'database:read', 'doctor:verify', 'firewall_rule:read', 'node:read', 'tool:read', 'tool:restart']));
    });

    it('creates grant with --preset when missing', function (): void {
        setupPermissionsGatewayCaller();
        DB::table('nodes')->insert(nodePermissionsRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodePermissionsRow());

        $exitCode = Artisan::call('node:permissions', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--preset' => 'operator',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);
        $grant = DB::table('node_access')->first();

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['action'])->toBe('created')
            ->and($grant->permissions)->toBe(json_encode(['app:read', 'database:read', 'doctor:verify', 'firewall_rule:read', 'node:read', 'tool:read', 'tool:restart']));
    });

    it('sets permissions with --permissions on existing grant', function (): void {
        setupPermissionsGatewayCaller();
        DB::table('nodes')->insert(nodePermissionsRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodePermissionsRow());

        $controlId = DB::table('nodes')->where('name', 'control-1')->value('id');
        $appId = DB::table('nodes')->where('name', 'app-1')->value('id');

        DB::table('node_access')->insert([
            'consumer_node_id' => $controlId,
            'serving_node_id' => $appId,
            'permissions' => json_encode(['node:read']),
        ]);

        $exitCode = Artisan::call('node:permissions', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--permissions' => 'node:read,tool:read',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);
        $grant = DB::table('node_access')->first();

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['action'])->toBe('updated')
            ->and($grant->permissions)->toBe(json_encode(['node:read', 'tool:read']));
    });

    it('creates grant with --permissions when missing', function (): void {
        setupPermissionsGatewayCaller();
        DB::table('nodes')->insert(nodePermissionsRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodePermissionsRow());

        $exitCode = Artisan::call('node:permissions', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--permissions' => 'node:read,tool:read',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['action'])->toBe('created')
            ->and($payload['success']['data']['permissions'])->toBe(['node:read', 'tool:read']);
    });

    it('adds permissions with --add on existing grant', function (): void {
        setupPermissionsGatewayCaller();
        DB::table('nodes')->insert(nodePermissionsRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodePermissionsRow());

        $controlId = DB::table('nodes')->where('name', 'control-1')->value('id');
        $appId = DB::table('nodes')->where('name', 'app-1')->value('id');

        DB::table('node_access')->insert([
            'consumer_node_id' => $controlId,
            'serving_node_id' => $appId,
            'permissions' => json_encode(['node:read']),
        ]);

        $exitCode = Artisan::call('node:permissions', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--add' => 'tool:read',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);
        $grant = DB::table('node_access')->first();

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['action'])->toBe('updated')
            ->and($grant->permissions)->toBe(json_encode(['node:read', 'tool:read']));
    });

    it('creates grant with --add when missing', function (): void {
        setupPermissionsGatewayCaller();
        DB::table('nodes')->insert(nodePermissionsRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodePermissionsRow());

        $exitCode = Artisan::call('node:permissions', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--add' => 'node:read',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['action'])->toBe('created')
            ->and($payload['success']['data']['permissions'])->toBe(['node:read']);
    });

    it('removes permissions with --remove and preserves grant edge', function (): void {
        setupPermissionsGatewayCaller();
        DB::table('nodes')->insert(nodePermissionsRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodePermissionsRow());

        $controlId = DB::table('nodes')->where('name', 'control-1')->value('id');
        $appId = DB::table('nodes')->where('name', 'app-1')->value('id');

        DB::table('node_access')->insert([
            'consumer_node_id' => $controlId,
            'serving_node_id' => $appId,
            'permissions' => json_encode(['node:read', 'tool:read']),
        ]);

        $exitCode = Artisan::call('node:permissions', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--remove' => 'tool:read',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);
        $grant = DB::table('node_access')->first();

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['action'])->toBe('updated')
            ->and($payload['success']['data']['permissions'])->toBe(['node:read'])
            ->and(DB::table('node_access')->count())->toBe(1);
    });

    it('removes all permissions with --remove leaving empty set', function (): void {
        setupPermissionsGatewayCaller();
        DB::table('nodes')->insert(nodePermissionsRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodePermissionsRow());

        $controlId = DB::table('nodes')->where('name', 'control-1')->value('id');
        $appId = DB::table('nodes')->where('name', 'app-1')->value('id');

        DB::table('node_access')->insert([
            'consumer_node_id' => $controlId,
            'serving_node_id' => $appId,
            'permissions' => json_encode(['node:read']),
        ]);

        $exitCode = Artisan::call('node:permissions', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--remove' => 'node:read',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);
        $grant = DB::table('node_access')->first();

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['action'])->toBe('updated')
            ->and($payload['success']['data']['permissions'])->toBe([])
            ->and(DB::table('node_access')->count())->toBe(1);
    });

    it('fails with node.grant_not_found when --remove on missing grant', function (): void {
        setupPermissionsGatewayCaller();
        DB::table('nodes')->insert(nodePermissionsRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodePermissionsRow());

        $exitCode = Artisan::call('node:permissions', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--remove' => 'node:read',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('node.grant_not_found');
    });

    it('fails with validation_failed when multiple mutation flags are given', function (): void {
        setupPermissionsGatewayCaller();
        DB::table('nodes')->insert(nodePermissionsRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodePermissionsRow());

        $exitCode = Artisan::call('node:permissions', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--preset' => 'operator',
            '--permissions' => 'node:read',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed');
    });

    it('fails with validation_failed for invalid preset', function (): void {
        setupPermissionsGatewayCaller();
        DB::table('nodes')->insert(nodePermissionsRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodePermissionsRow());

        $exitCode = Artisan::call('node:permissions', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--preset' => 'invalid',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed');
    });

    it('fails with validation_failed for invalid permissions', function (): void {
        setupPermissionsGatewayCaller();
        DB::table('nodes')->insert(nodePermissionsRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodePermissionsRow());

        $exitCode = Artisan::call('node:permissions', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--permissions' => 'invalid:permission',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed');
    });

    it('warns about redundant permissions', function (): void {
        setupPermissionsGatewayCaller();
        DB::table('nodes')->insert(nodePermissionsRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodePermissionsRow());

        $exitCode = Artisan::call('node:permissions', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--permissions' => 'node:read,node:list',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['meta']['warnings'][0]['code'])->toBe('node.redundant_permissions');
    });

    it('rejects control-node callers with gateway_unavailable', function (): void {
        config(['orbit.is_gateway' => false]);

        DB::table('nodes')->insert(nodePermissionsRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodePermissionsRow());

        $exitCode = Artisan::call('node:permissions', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('gateway_unavailable');
    });
});

describe('node:permissions control forwarding', function (): void {
    it('fails locally with missing nodes in json mode and sends no request', function (): void {
        config(['orbit.is_gateway' => false]);

        setupPermissionsControlCaller();

        $mock = fakeNodePermissionsGateway([
            'success' => [
                'data' => [
                    'consuming_node' => 'control-1',
                    'serving_node' => 'app-1',
                    'action' => 'created',
                    'mode' => 'preset',
                    'permissions' => ['node:read'],
                ],
            ],
        ]);

        $exitCode = Artisan::call('node:permissions', [
            '--preset' => 'operator',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['message'])->toBe('Both consuming_node and serving_node are required.')
            ->and($payload['error']['meta'])->toBe(['fields' => ['consuming_node', 'serving_node']]);

        $mock->assertNotSent(NodePermissionsRequest::class);
    });

    it('fails locally with multiple mode flags in json mode and sends no request', function (): void {
        config(['orbit.is_gateway' => false]);

        setupPermissionsControlCaller();

        $mock = fakeNodePermissionsGateway([
            'success' => [
                'data' => [
                    'consuming_node' => 'control-1',
                    'serving_node' => 'app-1',
                    'action' => 'created',
                    'mode' => 'preset',
                    'permissions' => ['node:read'],
                ],
            ],
        ]);

        $exitCode = Artisan::call('node:permissions', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--preset' => 'operator',
            '--permissions' => 'node:read',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['message'])->toBe('Use only one of --preset, --permissions, --add, or --remove.');

        $mock->assertNotSent(NodePermissionsRequest::class);
    });

    it('forwards preset requests to the gateway', function (): void {
        config(['orbit.is_gateway' => false]);

        setupPermissionsControlCaller();

        $mock = fakeNodePermissionsGateway([
            'success' => [
                'data' => [
                    'consuming_node' => 'control-1',
                    'serving_node' => 'app-1',
                    'action' => 'created',
                    'mode' => 'preset',
                    'permissions' => ['node:read'],
                ],
            ],
        ]);

        $exitCode = Artisan::call('node:permissions', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--preset' => 'operator',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['action'])->toBe('created')
            ->and($payload['success']['data']['mode'])->toBe('preset')
            ->and($payload['success']['data']['permissions'])->toBe(['node:read']);

        $mock->assertSent(fn (NodePermissionsRequest $request): bool => $request->resolveEndpoint() === '/api/nodes/permissions'
            && $request->body()->all() === [
                'consuming_node' => 'control-1',
                'serving_node' => 'app-1',
                'preset' => 'operator',
            ]);
    });
});
