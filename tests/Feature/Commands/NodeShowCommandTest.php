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
function nodeShowRow(array $overrides = []): array
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

describe('node:show', function (): void {
    it('renders registered node details for humans', function (): void {
        DB::table('nodes')->insert(nodeShowRow());

        $this->artisan('node:show', ['name' => 'app-1'])
            ->expectsOutputToContain('Node: app-1')
            ->expectsOutputToContain('Role: app')
            ->expectsOutputToContain('Environment: development')
            ->expectsOutputToContain('Platform: ubuntu_24-04')
            ->expectsOutputToContain('WireGuard: 10.6.0.7')
            ->assertSuccessful();
    });

    it('returns the documented JSON envelope for a registry read', function (): void {
        DB::table('nodes')->insert(nodeShowRow());

        $exitCode = Artisan::call('node:show', [
            'name' => 'app-1',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload)->toBe([
                'success' => [
                    'data' => [
                        'node' => [
                            'name' => 'app-1',
                            'role' => 'app',
                            'status' => 'active',
                            'environment' => 'development',
                            'platform' => 'ubuntu_24-04',
                            'addresses' => [
                                'wireguard' => '10.6.0.7',
                            ],
                            'agent_ide' => [
                                'adapter' => null,
                                'source' => 'default',
                            ],
                            'grants' => [
                                'consuming_nodes' => [],
                                'serving_nodes' => [],
                            ],
                        ],
                    ],
                ],
            ])
            ->and($payload)->not->toHaveKey('error')
            ->and($payload['success']['data']['node'])->not->toHaveKey('checks');
    });

    it('returns a documented JSON error when the node is missing', function (): void {
        $exitCode = Artisan::call('node:show', [
            'name' => 'missing-node',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload)->toBe([
                'error' => [
                    'code' => 'node.not_found',
                    'message' => "Node 'missing-node' not found or not visible.",
                    'meta' => [
                        'name' => 'missing-node',
                    ],
                ],
            ]);
    });

    it('defaults to the local node when no name is supplied', function (): void {
        DB::table('nodes')->insert(nodeShowRow([
            'name' => 'mini',
            'role' => 'control',
            'host' => '10.6.0.8',
            'wireguard_address' => '10.6.0.8',
            'orbit_path' => '/Users/nckrtl/orbit',
            'is_local' => true,
            'environment' => null,
            'platform' => 'macos_15-4',
        ]));

        $exitCode = Artisan::call('node:show', ['--json' => true]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['node']['name'])->toBe('mini')
            ->and($payload['success']['data']['node']['role'])->toBe('control');
    });

    it('does not mutate registry state or run processes', function (): void {
        DB::table('nodes')->insert(nodeShowRow());

        Process::fake();
        Process::preventStrayProcesses();

        $before = DB::table('nodes')->where('name', 'app-1')->first();

        $this->artisan('node:show', ['name' => 'app-1'])->assertSuccessful();

        $after = DB::table('nodes')->where('name', 'app-1')->first();

        expect((array) $after)->toBe((array) $before);
        Process::assertRanTimes(fn (): bool => true, 0);
    });
});

describe('node:show field semantics', function (): void {
    it('returns environment null for non-app roles', function (): void {
        DB::table('nodes')->insert(nodeShowRow([
            'name' => 'gateway-1',
            'role' => 'gateway',
            'environment' => 'should-be-ignored',
            'platform' => 'ubuntu_24-04',
        ]));

        $exitCode = Artisan::call('node:show', [
            'name' => 'gateway-1',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['node']['environment'])->toBeNull()
            ->and($payload['success']['data']['node']['role'])->toBe('gateway');
    });

    it('defaults platform to unknown when null', function (): void {
        DB::table('nodes')->insert(nodeShowRow([
            'name' => 'gateway-1',
            'role' => 'gateway',
            'environment' => null,
            'platform' => null,
        ]));

        $exitCode = Artisan::call('node:show', [
            'name' => 'gateway-1',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['node']['platform'])->toBe('unknown');
    });

    it('falls back to host when wireguard_address is null', function (): void {
        DB::table('nodes')->insert(nodeShowRow([
            'wireguard_address' => null,
            'host' => '192.168.1.1',
        ]));

        $exitCode = Artisan::call('node:show', [
            'name' => 'app-1',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['node']['addresses']['wireguard'])->toBe('192.168.1.1');
    });

    it('omits environment line in human output for non-app roles', function (): void {
        DB::table('nodes')->insert(nodeShowRow([
            'name' => 'gateway-1',
            'role' => 'gateway',
            'environment' => null,
        ]));

        $this->artisan('node:show', ['name' => 'gateway-1'])
            ->expectsOutputToContain('Node: gateway-1')
            ->expectsOutputToContain('Role: gateway')
            ->doesntExpectOutputToContain('Environment:')
            ->assertSuccessful();
    });

    it('omits grants section in human output when empty', function (): void {
        DB::table('nodes')->insert(nodeShowRow());

        $this->artisan('node:show', ['name' => 'app-1'])
            ->expectsOutputToContain('Node: app-1')
            ->doesntExpectOutputToContain('Grants:')
            ->assertSuccessful();
    });
});

describe('node:show documented error shapes', function (): void {
    it('returns validation_failed when name is missing and no local node exists', function (): void {
        $exitCode = Artisan::call('node:show', ['--json' => true]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload)->toHaveKey('error')
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['message'])->toBe('Node name is required.')
            ->and($payload['error']['meta']['field'])->toBe('name');
    });

    it('returns human prose error for missing node', function (): void {
        $this->artisan('node:show', ['name' => 'missing-node'])
            ->expectsOutputToContain("Node 'missing-node' not found or not visible.")
            ->assertFailed();
    });

    it('returns human prose error for missing name without local node', function (): void {
        $this->artisan('node:show')
            ->expectsOutputToContain('Node name is required.')
            ->assertFailed();
    });
});

describe('node:show read-only guarantee', function (): void {
    it('makes no DB writes during show', function (): void {
        DB::table('nodes')->insert(nodeShowRow());

        $countBefore = DB::table('nodes')->count();

        $this->artisan('node:show', ['name' => 'app-1'])->assertSuccessful();

        expect(DB::table('nodes')->count())->toBe($countBefore);
    });

    it('makes no process calls during show', function (): void {
        Process::fake();
        Process::preventStrayProcesses();

        DB::table('nodes')->insert(nodeShowRow());

        $this->artisan('node:show', ['name' => 'app-1'])->assertSuccessful();

        Process::assertNothingRan();
    });
});
