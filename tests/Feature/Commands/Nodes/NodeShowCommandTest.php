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
function nodeShowCommandRow(array $overrides = []): array
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

function setupNodeShowCommandGatewayCaller(): void
{
    DB::table('nodes')->insert(nodeShowCommandRow([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'environment' => null,
        'is_local' => true,
    ]));
}

function setupNodeShowCommandControlCaller(): void
{
    DB::table('nodes')->insert(nodeShowCommandRow([
        'name' => 'control-1',
        'role' => 'control',
        'environment' => null,
        'is_local' => true,
    ]));
}

function setupNodeShowCommandAppCaller(): void
{
    DB::table('nodes')->insert(nodeShowCommandRow([
        'name' => 'app-local',
        'role' => 'app',
        'environment' => 'development',
        'is_local' => true,
    ]));
}

function setupNodeShowCommandUnknownCaller(): void
{
    DB::table('nodes')->insert(nodeShowCommandRow([
        'name' => 'weird',
        'role' => 'bogus',
        'environment' => null,
        'is_local' => true,
    ]));
}

describe('node:show command contract', function (): void {
    beforeEach(function (): void {
        setupNodeShowCommandGatewayCaller();
    });

    it('looks up a node by name and returns successfully', function (): void {
        DB::table('nodes')->insert(nodeShowCommandRow());

        $exitCode = Artisan::call('node:show', ['name' => 'app-1']);

        expect($exitCode)->toBe(0);
    });

    it('defaults to the local node when no name is supplied', function (): void {
        DB::table('nodes')->insert(nodeShowCommandRow());

        $exitCode = Artisan::call('node:show', ['--json' => true]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['node']['name'])->toBe('gateway-1')
            ->and($payload['success']['data']['node']['role'])->toBe('gateway');
    });

    it('defaults to local default development app node when set and name is missing', function (): void {
        DB::table('nodes')->insert(nodeShowCommandRow());

        DB::table('local_node_defaults')->insert([
            'default_node_name' => 'app-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exitCode = Artisan::call('node:show', ['--json' => true]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['node']['name'])->toBe('app-1');
    });

    it('does not mutate registry state or run processes', function (): void {
        DB::table('nodes')->insert(nodeShowCommandRow());

        Process::fake();
        Process::preventStrayProcesses();

        $before = DB::table('nodes')->where('name', 'app-1')->first();

        $this->artisan('node:show', ['name' => 'app-1'])->assertSuccessful();

        $after = DB::table('nodes')->where('name', 'app-1')->first();

        expect((array) $after)->toBe((array) $before);
        Process::assertRanTimes(fn (): bool => true, 0);
    });

    it('makes no DB writes during show', function (): void {
        DB::table('nodes')->insert(nodeShowCommandRow());

        $countBefore = DB::table('nodes')->count();

        $this->artisan('node:show', ['name' => 'app-1'])->assertSuccessful();

        expect(DB::table('nodes')->count())->toBe($countBefore);
    });

    it('makes no process calls during show', function (): void {
        Process::fake();
        Process::preventStrayProcesses();

        DB::table('nodes')->insert(nodeShowCommandRow());

        $this->artisan('node:show', ['name' => 'app-1'])->assertSuccessful();

        Process::assertNothingRan();
    });

    it('returns node.not_found for missing target', function (): void {
        $exitCode = Artisan::call('node:show', ['name' => 'nonexistent', '--json' => true]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('node.not_found');
    });
});

describe('node:show caller role behavior', function (): void {
    it('executes locally for gateway callers', function (): void {
        setupNodeShowCommandGatewayCaller();
        DB::table('nodes')->insert(nodeShowCommandRow());

        $exitCode = Artisan::call('node:show', ['name' => 'app-1', '--json' => true]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload)->toHaveKey('success');
    });

    it('forwards for control callers and fails with gateway_unavailable', function (): void {
        setupNodeShowCommandControlCaller();
        DB::table('nodes')->insert(nodeShowCommandRow());

        $exitCode = Artisan::call('node:show', ['name' => 'app-1', '--json' => true]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('gateway_unavailable');
    });

    it('forwards for app callers and fails with gateway_unavailable', function (): void {
        setupNodeShowCommandAppCaller();
        DB::table('nodes')->insert(nodeShowCommandRow());

        $exitCode = Artisan::call('node:show', ['name' => 'app-1', '--json' => true]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('gateway_unavailable');
    });

    it('rejects unknown callers with local_context_invalid', function (): void {
        setupNodeShowCommandUnknownCaller();
        DB::table('nodes')->insert(nodeShowCommandRow());

        $exitCode = Artisan::call('node:show', ['name' => 'app-1', '--json' => true]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('local_context_invalid')
            ->and($payload['error']['meta']['caller_role'])->toBe('unknown');
    });

    it('defaults caller role to control when no local node exists', function (): void {
        DB::table('nodes')->insert(nodeShowCommandRow());

        $exitCode = Artisan::call('node:show', ['name' => 'app-1', '--json' => true]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('gateway_unavailable');
    });
});
