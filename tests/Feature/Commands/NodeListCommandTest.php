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
function nodeListRow(array $overrides = []): array
{
    return array_merge([
        'name' => 'app-1',
        'role' => 'app',
        'host' => '10.6.0.7',
        'ssh_user' => 'nckrtl',
        'orbit_path' => '/home/nckrtl/orbit',
        'status' => 'active',
        'is_local' => false,
        'environment' => 'development',
        'platform' => 'ubuntu_24-04',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

describe('node:list base contract', function (): void {
    it('sorts nodes by role then name', function (): void {
        DB::table('nodes')->insert([
            nodeListRow(['name' => 'zebra-app', 'role' => 'app']),
            nodeListRow(['name' => 'alpha-app', 'role' => 'app']),
            nodeListRow(['name' => 'gateway-1', 'role' => 'gateway', 'environment' => null]),
            nodeListRow(['name' => 'control-1', 'role' => 'control', 'environment' => null]),
        ]);

        $exitCode = Artisan::call('node:list', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        $nodes = $payload['success']['data']['nodes'];
        $names = array_column($nodes, 'name');

        expect($names)->toBe([
            'alpha-app',
            'zebra-app',
            'control-1',
            'gateway-1',
        ]);
    });
});

describe('node:list filters', function (): void {
    beforeEach(function (): void {
        DB::table('nodes')->insert([
            nodeListRow([
                'name' => 'gateway-1',
                'role' => 'gateway',
                'environment' => null,
                'is_local' => true,
            ]),
            nodeListRow([
                'name' => 'dev-app',
                'role' => 'app',
                'environment' => 'development',
            ]),
            nodeListRow([
                'name' => 'prod-app',
                'role' => 'app',
                'environment' => 'production',
            ]),
            nodeListRow([
                'name' => 'control-1',
                'role' => 'control',
                'environment' => null,
            ]),
        ]);
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
            ->and($payload['error']['meta']['allowed'])->toBe(['gateway', 'app', 'control']);
    });

    it('rejects invalid --role with human error message', function (): void {
        $this->artisan('node:list', ['--role' => 'bogus'])
            ->expectsOutputToContain("Invalid value for --role: 'bogus'. Allowed values: gateway, app, control.")
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

        DB::table('nodes')->insert(nodeListRow(['name' => 'app-1']));

        $this->artisan('node:list')->assertSuccessful();

        Process::assertNothingRan();
    });
});
