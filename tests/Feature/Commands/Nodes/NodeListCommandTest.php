<?php

declare(strict_types=1);

use App\Http\Gateway\Requests\Nodes\ListNodesRequest;
use App\Models\LocalGatewaySettings;
use App\Models\NodeRoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

afterEach(function (): void {
    MockClient::destroyGlobal();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nodeListRow(array $overrides = []): array
{
    return array_merge([
        'name' => 'app-1',
        'role' => 'app',
        'host' => '10.6.0.7',
        'orbit_path' => '/home/nckrtl/orbit',
        'status' => 'active',
        'environment' => 'development',
        'platform' => 'ubuntu_24-04',
        'wireguard_address' => '10.6.0.7',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $settings
 */
function assignNodeListRole(string $nodeName, string $role, array $settings = []): void
{
    $nodeId = (int) DB::table('nodes')
        ->where('name', $nodeName)
        ->value('id');

    NodeRoleAssignment::factory()->create([
        'node_id' => $nodeId,
        'role' => $role,
        'status' => 'active',
        'settings' => $settings,
    ]);
}

describe('node:list base contract', function (): void {
    beforeEach(function (): void {
        config(['orbit.is_gateway' => true]);
    });

    it('sorts nodes by effective role assignment then name', function (): void {
        DB::table('nodes')->insert([
            nodeListRow(['name' => 'zebra-app', 'role' => 'control']),
            nodeListRow(['name' => 'alpha-app', 'role' => 'app']),
            nodeListRow(['name' => 'database-1', 'role' => 'control', 'environment' => null]),
            nodeListRow(['name' => 'gateway-1', 'role' => 'control', 'environment' => null]),
            nodeListRow(['name' => 'control-1', 'role' => 'control', 'environment' => null]),
        ]);
        assignNodeListRole('zebra-app', 'app-development', ['tld' => 'test']);
        assignNodeListRole('alpha-app', 'app-development', ['tld' => 'test']);
        assignNodeListRole('database-1', 'database');
        assignNodeListRole('gateway-1', 'gateway');

        $exitCode = Artisan::call('node:list', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        $nodes = $payload['success']['data']['nodes'];
        $names = array_column($nodes, 'name');

        expect($names)->toBe([
            'alpha-app',
            'zebra-app',
            'control-1',
            'database-1',
            'gateway-1',
        ]);
    });
});

describe('node:list filters', function (): void {
    beforeEach(function (): void {
        config(['orbit.is_gateway' => true]);

        DB::table('nodes')->insert([
            nodeListRow([
                'name' => 'gateway-1',
                'role' => 'control',
                'environment' => null,
            ]),
            nodeListRow([
                'name' => 'dev-app',
                'role' => 'control',
                'environment' => 'development',
            ]),
            nodeListRow([
                'name' => 'prod-app',
                'role' => 'control',
                'environment' => 'production',
            ]),
            nodeListRow([
                'name' => 'db-1',
                'role' => 'control',
                'environment' => null,
            ]),
            nodeListRow([
                'name' => 'control-1',
                'role' => 'control',
                'environment' => null,
            ]),
        ]);
        assignNodeListRole('gateway-1', 'gateway');
        assignNodeListRole('dev-app', 'app-development', ['tld' => 'test']);
        assignNodeListRole('prod-app', 'app-production');
        assignNodeListRole('db-1', 'database');
    });

    it('filters by --role gateway', function (): void {
        $exitCode = Artisan::call('node:list', ['--json' => true, '--role' => 'gateway']);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        $nodes = $payload['success']['data']['nodes'];

        expect($nodes)->toHaveCount(1)
            ->and($nodes[0]['name'])->toBe('gateway-1');
    });

    it('filters by --role app', function (): void {
        $exitCode = Artisan::call('node:list', ['--json' => true, '--role' => 'app']);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        $nodes = $payload['success']['data']['nodes'];

        expect($nodes)->toHaveCount(2);

        $names = array_column($nodes, 'name');
        expect($names)->toContain('dev-app')
            ->and($names)->toContain('prod-app');
    });

    it('filters by concrete app and database roles', function (string $role, string $node): void {
        $exitCode = Artisan::call('node:list', ['--json' => true, '--role' => $role]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        $nodes = $payload['success']['data']['nodes'];

        expect($exitCode)->toBe(0)
            ->and($nodes)->toHaveCount(1)
            ->and($nodes[0]['name'])->toBe($node);
    })->with([
        ['app-development', 'dev-app'],
        ['app-production', 'prod-app'],
        ['database', 'db-1'],
    ]);

    it('filters by --role control', function (): void {
        $exitCode = Artisan::call('node:list', ['--json' => true, '--role' => 'control']);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        $nodes = $payload['success']['data']['nodes'];

        expect($nodes)->toHaveCount(1)
            ->and($nodes[0]['name'])->toBe('control-1');
    });

    it('filters by --environment development', function (): void {
        $exitCode = Artisan::call('node:list', ['--json' => true, '--environment' => 'development']);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        $nodes = $payload['success']['data']['nodes'];

        expect($nodes)->toHaveCount(1)
            ->and($nodes[0]['name'])->toBe('dev-app');
    });

    it('filters by --environment production', function (): void {
        $exitCode = Artisan::call('node:list', ['--json' => true, '--environment' => 'production']);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        $nodes = $payload['success']['data']['nodes'];

        expect($nodes)->toHaveCount(1)
            ->and($nodes[0]['name'])->toBe('prod-app');
    });

    it('combines --role and --environment with AND semantics', function (): void {
        $exitCode = Artisan::call('node:list', [
            '--json' => true,
            '--role' => 'app',
            '--environment' => 'development',
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        $nodes = $payload['success']['data']['nodes'];

        expect($nodes)->toHaveCount(1)
            ->and($nodes[0]['name'])->toBe('dev-app');
    });

    it('returns empty result when filter matches nothing', function (): void {
        $exitCode = Artisan::call('node:list', ['--json' => true, '--role' => 'control', '--environment' => 'development']);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        $nodes = $payload['success']['data']['nodes'];

        expect($nodes)->toHaveCount(0);
    });
});

describe('node:list validation', function (): void {
    it('rejects invalid --role with validation_failed JSON envelope', function (): void {
        $exitCode = Artisan::call('node:list', ['--json' => true, '--role' => 'bogus']);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload)->toHaveKey('error')
            ->and($payload)->not->toHaveKey('success')
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('role')
            ->and($payload['error']['meta']['value'])->toBe('bogus')
            ->and($payload['error']['meta']['allowed'])->toBe(['gateway', 'app', 'app-development', 'app-production', 'database', 'control']);
    });

    it('rejects invalid --role with human error message', function (): void {
        $this->artisan('node:list', ['--role' => 'bogus'])
            ->expectsOutputToContain("Invalid value for --role: 'bogus'. Allowed values: gateway, app, app-development, app-production, database, control.")
            ->assertFailed();
    });

    it('rejects invalid --environment with validation_failed JSON envelope', function (): void {
        $exitCode = Artisan::call('node:list', ['--json' => true, '--environment' => 'bogus']);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload)->toHaveKey('error')
            ->and($payload)->not->toHaveKey('success')
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('environment');
    });

    it('rejects invalid --environment with human error message', function (): void {
        $this->artisan('node:list', ['--environment' => 'bogus'])
            ->expectsOutputToContain("Invalid value for --environment: 'bogus'. Allowed values: development, production.")
            ->assertFailed();
    });

    it('rejects comma-separated --role with validation_failed', function (): void {
        $exitCode = Artisan::call('node:list', ['--json' => true, '--role' => 'app,control']);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload)->toHaveKey('error')
            ->and($payload)->not->toHaveKey('success')
            ->and($payload['error']['code'])->toBe('validation_failed');
    });

    it('rejects comma-separated --environment with validation_failed', function (): void {
        $exitCode = Artisan::call('node:list', ['--json' => true, '--environment' => 'development,production']);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload)->toHaveKey('error')
            ->and($payload)->not->toHaveKey('success')
            ->and($payload['error']['code'])->toBe('validation_failed');
    });
});

describe('node:list read-only guarantee', function (): void {
    beforeEach(function (): void {
        config(['orbit.is_gateway' => true]);
    });

    it('makes no DB writes during base list', function (): void {
        DB::table('nodes')->insert([
            nodeListRow(['name' => 'gateway-1', 'role' => 'gateway', 'environment' => null]),
            nodeListRow(['name' => 'app-1', 'role' => 'app']),
        ]);

        $countBefore = DB::table('nodes')->count();

        $this->artisan('node:list')->assertSuccessful();

        expect(DB::table('nodes')->count())->toBe($countBefore);
    });

    it('makes no process calls during base list', function (): void {
        Process::fake();
        Process::preventStrayProcesses();

        DB::table('nodes')->insert([
            nodeListRow(['name' => 'gateway-1', 'role' => 'gateway', 'environment' => null]),
            nodeListRow(['name' => 'app-1']),
        ]);

        $this->artisan('node:list')->assertSuccessful();

        Process::assertNothingRan();
    });
});

describe('node:list control-caller forwarding', function (): void {
    beforeEach(function (): void {
        DB::table('nodes')->insert([
            'name' => 'local-control',
            'role' => 'control',
            'host' => '10.6.0.2',
            'orbit_path' => '/home/nckrtl/orbit',
            'status' => 'active',
            'environment' => null,
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.2',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();
    });

    it('forwards to gateway and renders gateway response', function (): void {
        config(['orbit.is_gateway' => false]);

        MockClient::global([
            ListNodesRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'nodes' => [
                            [
                                'name' => 'gateway-1',
                                'role' => 'gateway',
                                'environment' => null,
                                'platform' => 'ubuntu_24-04',
                                'status' => 'active',
                            ],
                            [
                                'name' => 'app-1',
                                'role' => 'app',
                                'environment' => 'development',
                                'platform' => 'ubuntu_24-04',
                                'status' => 'active',
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('node:list', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload)->toHaveKey('success')
            ->and($payload['success']['data']['nodes'])->toHaveCount(2);
    });

    it('forwards role filter to gateway request', function (): void {
        config(['orbit.is_gateway' => false]);

        MockClient::global([
            ListNodesRequest::class => function ($pendingRequest) {
                $request = $pendingRequest->getRequest();

                if ($request instanceof ListNodesRequest && $request->role === 'app') {
                    return MockResponse::make([
                        'success' => [
                            'data' => [
                                'nodes' => [
                                    [
                                        'name' => 'app-1',
                                        'role' => 'app',
                                        'environment' => 'development',
                                        'platform' => 'ubuntu_24-04',
                                        'status' => 'active',
                                    ],
                                ],
                            ],
                        ],
                    ], 200);
                }

                return MockResponse::make([
                    'success' => ['data' => ['nodes' => []]],
                ], 200);
            },
        ]);

        $exitCode = Artisan::call('node:list', ['--json' => true, '--role' => 'app']);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['nodes'])->toHaveCount(1)
            ->and($payload['success']['data']['nodes'][0]['name'])->toBe('app-1');
    });

    it('forwards doctor flag to gateway request and renders returned meta', function (): void {
        config(['orbit.is_gateway' => false]);

        MockClient::global([
            ListNodesRequest::class => function ($pendingRequest) {
                $request = $pendingRequest->getRequest();

                if ($request instanceof ListNodesRequest && $request->doctor === true) {
                    return MockResponse::make([
                        'success' => [
                            'data' => [
                                'nodes' => [
                                    [
                                        'name' => 'gateway-1',
                                        'role' => 'gateway',
                                        'environment' => null,
                                        'platform' => 'ubuntu_24-04',
                                        'status' => 'active',
                                    ],
                                ],
                            ],
                            'meta' => [
                                'doctor' => [
                                    'checked' => 1,
                                    'issues' => 1,
                                    'failures' => [
                                        [
                                            'node' => 'gateway-1',
                                            'code' => 'node.record_incomplete',
                                            'message' => 'Node record for gateway-1 is missing required fields.',
                                            'family' => 'node',
                                            'next_command' => 'doctor --fix --family=node --restore',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ], 200);
                }

                return MockResponse::make([
                    'success' => ['data' => ['nodes' => []]],
                ], 200);
            },
        ]);

        $exitCode = Artisan::call('node:list', ['--json' => true, '--doctor' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['meta']['doctor']['checked'])->toBe(1)
            ->and($payload['success']['meta']['doctor']['issues'])->toBe(1);
    });

    it('handles forwarding error with JSON envelope', function (): void {
        config(['orbit.is_gateway' => false]);

        MockClient::global([
            ListNodesRequest::class => MockResponse::make([
                'error' => [
                    'code' => 'gateway_unavailable',
                    'message' => 'Gateway is unreachable.',
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('node:list', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload)->toHaveKey('error')
            ->and($payload['error']['code'])->toBe('gateway_unavailable');
    });

    it('preserves authorization failures from the gateway', function (): void {
        config(['orbit.is_gateway' => false]);

        MockClient::global([
            ListNodesRequest::class => MockResponse::make([
                'error' => [
                    'code' => 'authorization_failed',
                    'message' => 'This node is not authorized to read the node registry.',
                    'meta' => [
                        'node' => 'local-control',
                    ],
                ],
            ], 403),
        ]);

        $exitCode = Artisan::call('node:list', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error'])->toBe([
                'code' => 'authorization_failed',
                'message' => 'This node is not authorized to read the node registry.',
                'meta' => [
                    'node' => 'local-control',
                ],
            ]);
    });

    it('handles forwarding error with human message', function (): void {
        config(['orbit.is_gateway' => false]);

        MockClient::global([
            ListNodesRequest::class => MockResponse::make([
                'error' => [
                    'code' => 'gateway_unavailable',
                    'message' => 'Gateway is unreachable.',
                ],
            ], 200),
        ]);

        $this->artisan('node:list')
            ->expectsOutputToContain('Gateway is unreachable.')
            ->assertFailed();
    });
});
