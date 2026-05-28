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
        'host' => '10.6.0.7',
        'wireguard_address' => '10.6.0.7',
        'user' => 'nckrtl',
        'orbit_path' => '/home/nckrtl/orbit',
        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

function setupNodeShowGatewayCaller(): void
{
    config(['orbit.is_gateway' => true]);

    $nodeId = DB::table('nodes')->insertGetId(nodeShowRow([
        'name' => 'gateway-1',
    ]));

    nodeShowAssignRole($nodeId, 'gateway');
}

function nodeShowAssignRole(int $nodeId, string $role): void
{
    DB::table('node_role')->insert([
        'node_id' => $nodeId,
        'role' => $role,
        'status' => 'active',
        'settings' => json_encode([], JSON_THROW_ON_ERROR),
        'last_error' => null,
        'converged_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

describe('node:show base contract', function (): void {
    beforeEach(function (): void {
        setupNodeShowGatewayCaller();
    });

    it('looks up a node by name and returns successfully', function (): void {
        DB::table('nodes')->insert(nodeShowRow());

        $exitCode = Artisan::call('node:show', ['name' => 'app-1']);

        expect($exitCode)->toBe(0);
    });

    it('defaults to the local node when no name is supplied', function (): void {
        DB::table('nodes')->insert(nodeShowRow());

        $exitCode = Artisan::call('node:show', ['--json' => true]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['node']['name'])->toBe('gateway-1')
            ->and($payload['success']['data']['node']['roles'][0]['role'])->toBe('gateway');
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

describe('node:show read-only guarantee', function (): void {
    beforeEach(function (): void {
        setupNodeShowGatewayCaller();
    });

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
