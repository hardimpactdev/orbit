<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

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

function setupNodeRevokeGatewayCaller(): void
{
    DB::table('nodes')->insert(nodeRevokeRow([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'environment' => null,
        'is_local' => true,
    ]));
}

function setupRevokeControlCaller(): void
{
    DB::table('nodes')->insert(nodeRevokeRow([
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

    it('rejects app-node callers before side effects', function (): void {
        DB::table('nodes')->insert(nodeRevokeRow([
            'name' => 'mini',
            'role' => 'app',
            'is_local' => true,
            'environment' => 'development',
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
            ->and($payload['error']['code'])->toBe('caller_role_not_allowed')
            ->and($payload['error']['meta']['caller_role'])->toBe('app');
        expect(DB::table('node_access')->count())->toBe(0);
    });

    it('rejects control-node callers with gateway_unavailable', function (): void {
        DB::table('nodes')->insert(nodeRevokeRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
            'is_local' => true,
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

    it('rejects unknown callers with local_context_invalid', function (): void {
        DB::table('nodes')->insert(nodeRevokeRow([
            'name' => 'bogus',
            'role' => 'bogus',
            'environment' => null,
            'is_local' => true,
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
            ->and($payload['error']['code'])->toBe('local_context_invalid')
            ->and($payload['error']['meta']['caller_role'])->toBe('unknown');
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
        setupRevokeControlCaller();

        Http::fake([
            'https://10.6.0.2/api/nodes/revoke' => Http::response([
                'success' => [
                    'data' => [
                        'consuming_node' => 'control-1',
                        'serving_node' => 'app-1',
                        'action' => 'revoked',
                        'already_absent' => false,
                        'self_lockout' => false,
                    ],
                ],
            ]),
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

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://10.6.0.2/api/nodes/revoke'
            && $request['consuming_node'] === 'control-1'
            && $request['serving_node'] === 'app-1'
            && $request['force'] === true);
    });

    it('renders forwarded already-absent success with human output', function (): void {
        setupRevokeControlCaller();

        Http::fake([
            'https://10.6.0.2/api/nodes/revoke' => Http::response([
                'success' => [
                    'data' => [
                        'consuming_node' => 'control-1',
                        'serving_node' => 'app-1',
                        'action' => 'revoked',
                        'already_absent' => true,
                        'self_lockout' => false,
                    ],
                ],
            ]),
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
        setupRevokeControlCaller();

        Http::fake([
            'https://10.6.0.2/api/nodes/revoke' => Http::response([
                'success' => [
                    'data' => [
                        'consuming_node' => 'control-1',
                        'serving_node' => 'gateway-1',
                        'action' => 'revoked',
                        'already_absent' => false,
                        'self_lockout' => true,
                    ],
                ],
            ]),
        ]);

        $exitCode = Artisan::call('node:revoke', [
            'consuming_node' => 'control-1',
            'serving_node' => 'gateway-1',
            '--force' => true,
        ]);

        expect($exitCode)->toBe(0);
        expect(Artisan::output())->toContain("Access from 'control-1' to 'gateway-1' revoked")
            ->and(Artisan::output())->toContain('This machine no longer has Orbit gateway access.');
    });

    it('preserves structured gateway errors when forwarding', function (array $error): void {
        setupRevokeControlCaller();

        Http::fake([
            'https://10.6.0.2/api/nodes/revoke' => Http::response([
                'error' => $error,
            ], 422),
        ]);

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
        setupRevokeControlCaller();
        DB::table('nodes')->insert(nodeRevokeRow([
            'name' => 'app-1',
            'role' => 'app',
        ]));
        DB::table('node_access')->insert([
            'consumer_node_id' => 2,
            'serving_node_id' => 3,
        ]);

        Http::fake([
            'https://10.6.0.2/api/nodes/revoke' => Http::response([
                'success' => [
                    'data' => [
                        'consuming_node' => 'control-1',
                        'serving_node' => 'app-1',
                        'action' => 'revoked',
                        'already_absent' => false,
                        'self_lockout' => false,
                    ],
                ],
            ]),
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
        setupRevokeControlCaller();

        Http::fake([
            'https://10.6.0.2/api/nodes/revoke' => Http::response([
                'success' => [
                    'data' => [
                        'consuming_node' => 'control-1',
                        'serving_node' => 'app-1',
                        'action' => 'revoked',
                        'already_absent' => false,
                        'self_lockout' => false,
                    ],
                ],
            ]),
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

        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://10.6.0.2/api/nodes/revoke');
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
