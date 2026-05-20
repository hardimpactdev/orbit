<?php

declare(strict_types=1);

use App\Http\Gateway\Requests\Nodes\ShowNodeRequest;
use App\Models\LocalGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    MockClient::destroyGlobal();
});

afterEach(function (): void {
    MockClient::destroyGlobal();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nodeShowRolePathRow(array $overrides = []): array
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

function setupNodeShowRolePathGatewayCaller(): void
{
    config(['orbit.is_gateway' => true]);

    DB::table('nodes')->insert(nodeShowRolePathRow([
        'name' => 'local-gateway',
        'role' => 'gateway',
        'environment' => null,
    ]));
}

function setupNodeShowRolePathAppCaller(): void
{
    config(['orbit.is_gateway' => false]);

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.1',
        'ca_pem_path' => '/dev/null',
    ])->save();
}

function setupNodeShowRolePathControlCaller(): void
{
    config(['orbit.is_gateway' => false]);

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.1',
        'ca_pem_path' => '/dev/null',
    ])->save();
}

describe('node:show role paths', function (): void {

    it('forwards to gateway for control caller', function (): void {
        setupNodeShowRolePathControlCaller();

        MockClient::global([
            ShowNodeRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'node' => [
                            'name' => 'gateway-1',
                            'role' => 'gateway',
                            'status' => 'active',
                            'environment' => null,
                            'platform' => 'ubuntu_24-04',
                            'roles' => [
                                [
                                    'role' => 'gateway',
                                    'status' => 'active',
                                    'settings' => [],
                                ],
                            ],
                            'wireguard_address' => '10.6.0.2',
                            'addresses' => ['wireguard' => '10.6.0.2'],
                            'agent_ide' => ['adapter' => null, 'source' => 'default'],
                            'grants' => ['consuming_nodes' => [], 'serving_nodes' => []],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('node:show', ['name' => 'gateway-1', '--json' => true]);
        $rawOutput = Artisan::output();
        $payload = json_decode($rawOutput, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['node']['name'])->toBe('gateway-1')
            ->and($payload['success']['data']['node']['roles'])->toBe([
                [
                    'role' => 'gateway',
                    'status' => 'active',
                    'settings' => [],
                ],
            ])
            ->and($rawOutput)->toContain('"settings":{}');
    });

    it('forwards real grant data from gateway for control caller', function (): void {
        setupNodeShowRolePathControlCaller();

        MockClient::global([
            ShowNodeRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'node' => [
                            'name' => 'app-1',
                            'role' => 'app',
                            'status' => 'active',
                            'environment' => 'development',
                            'platform' => 'ubuntu_24-04',
                            'wireguard_address' => '10.6.0.7',
                            'grants' => [
                                'consuming_nodes' => [
                                    ['name' => 'control-1', 'permissions' => ['*']],
                                    ['name' => 'control-2', 'permissions' => ['*']],
                                ],
                                'serving_nodes' => [
                                    ['name' => 'control-1', 'permissions' => ['*']],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('node:show', ['name' => 'app-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['node']['grants']['consuming_nodes'])->toBe([
                ['name' => 'control-1', 'permissions' => ['*']],
                ['name' => 'control-2', 'permissions' => ['*']],
            ])
            ->and($payload['success']['data']['node']['grants']['serving_nodes'])->toBe([
                ['name' => 'control-1', 'permissions' => ['*']],
            ]);
    });

    it('handles gateway forwarding error for control caller', function (): void {
        setupNodeShowRolePathControlCaller();

        MockClient::global([
            ShowNodeRequest::class => MockResponse::make([
                'error' => [
                    'code' => 'gateway_unavailable',
                    'message' => 'Gateway is unreachable.',
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('node:show', ['name' => 'some-node', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('gateway_unavailable');
    });

    it('preserves gateway authorization failures for control callers', function (): void {
        setupNodeShowRolePathControlCaller();

        MockClient::global([
            ShowNodeRequest::class => MockResponse::make([
                'error' => [
                    'code' => 'authorization_failed',
                    'message' => "Node 'private-app' is not visible to this caller.",
                    'meta' => [
                        'node' => 'private-app',
                    ],
                ],
            ], 403),
        ]);

        $exitCode = Artisan::call('node:show', ['name' => 'private-app', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error'])->toBe([
                'code' => 'authorization_failed',
                'message' => "Node 'private-app' is not visible to this caller.",
                'meta' => [
                    'node' => 'private-app',
                ],
            ]);
    });

    it('uses the local default development app node before forwarding control caller show requests', function (): void {
        setupNodeShowRolePathControlCaller();

        DB::table('local_node_defaults')->insert([
            'default_node_name' => 'default-app',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $mock = MockClient::global([
            ShowNodeRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'node' => [
                            'name' => 'default-app',
                            'role' => 'app',
                            'status' => 'active',
                            'environment' => 'development',
                            'platform' => 'ubuntu_24-04',
                            'wireguard_address' => '10.6.0.8',
                            'grants' => [
                                'consuming_nodes' => [],
                                'serving_nodes' => [],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('node:show', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['node']['name'])->toBe('default-app');

        $mock->assertSent(fn (ShowNodeRequest $request): bool => $request->name === 'default-app');
    });
});
