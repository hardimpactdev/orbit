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
        'ssh_user' => 'nckrtl',
        'orbit_path' => '/home/nckrtl/orbit',
        'status' => 'active',
        'is_local' => false,
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
            ->expectsOutputToContain('WireGuard: 10.6.0.7')
            ->expectsOutputToContain('Grants:')
            ->expectsOutputToContain('  Consuming: (none)')
            ->expectsOutputToContain('  Serving: (none)')
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
                            'environment' => null,
                            'platform' => 'unknown',
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
            'orbit_path' => '/Users/nckrtl/orbit',
            'is_local' => true,
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
