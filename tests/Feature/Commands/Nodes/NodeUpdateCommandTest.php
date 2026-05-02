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
function nodeUpdateBaseRow(array $overrides = []): array
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
        'public_ipv4' => null,
        'public_ipv6' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

/**
 * Insert a local gateway node so callerRole() resolves as gateway.
 */
function setupGatewayCallerBase(): void
{
    DB::table('nodes')->insert(nodeUpdateBaseRow([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'environment' => null,
        'is_local' => true,
    ]));
}

describe('node:update base contract', function (): void {
    beforeEach(function (): void {
        setupGatewayCallerBase();
    });

    it('updates a node and returns successfully', function (): void {
        DB::table('nodes')->insert(nodeUpdateBaseRow());

        $exitCode = Artisan::call('node:update', [
            'name' => 'app-1',
            '--host' => '10.6.0.99',
        ]);

        expect($exitCode)->toBe(0);
    });

    it('returns success with empty changed array for no-op update', function (): void {
        DB::table('nodes')->insert(nodeUpdateBaseRow());

        $exitCode = Artisan::call('node:update', [
            'name' => 'app-1',
            '--host' => '10.6.0.7',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['changed'])->toBeEmpty()
            ->and($payload['success']['data']['action'])->toBe('updated');
    });

    it('updates multiple fields and reports all in changed array', function (): void {
        DB::table('nodes')->insert(nodeUpdateBaseRow());

        $exitCode = Artisan::call('node:update', [
            'name' => 'app-1',
            '--host' => '10.6.0.99',
            '--environment' => 'production',
            '--public-ipv4' => '203.0.113.50',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['changed'])->toHaveCount(3)
            ->and($payload['success']['data']['changed'])->toContain('host')
            ->and($payload['success']['data']['changed'])->toContain('environment')
            ->and($payload['success']['data']['changed'])->toContain('public_ipv4');
    });

    it('persists changes to the database', function (): void {
        DB::table('nodes')->insert(nodeUpdateBaseRow());

        Artisan::call('node:update', [
            'name' => 'app-1',
            '--host' => '10.6.0.99',
            '--environment' => 'production',
            '--json' => true,
        ]);

        $node = DB::table('nodes')->where('name', 'app-1')->first();

        expect($node->host)->toBe('10.6.0.99')
            ->and($node->environment)->toBe('production');
    });

    it('fails with node.not_found for missing target', function (): void {
        $exitCode = Artisan::call('node:update', [
            'name' => 'nonexistent',
            '--host' => '10.6.0.99',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('node.not_found');
    });

    it('fails with validation_failed when no field flags provided', function (): void {
        DB::table('nodes')->insert(nodeUpdateBaseRow());

        $exitCode = Artisan::call('node:update', [
            'name' => 'app-1',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('fields');
    });

    it('fails with validation_failed when name is missing', function (): void {
        $exitCode = Artisan::call('node:update', [
            '--host' => '10.6.0.99',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('name');
    });
});

describe('node:update caller role behavior', function (): void {
    it('denies app-node callers with caller_role_not_allowed', function (): void {
        DB::table('nodes')->insert(nodeUpdateBaseRow([
            'name' => 'test-app',
            'is_local' => true,
        ]));

        DB::table('nodes')->insert(nodeUpdateBaseRow());

        $exitCode = Artisan::call('node:update', [
            'name' => 'app-1',
            '--host' => '10.6.0.99',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('caller_role_not_allowed')
            ->and($payload['error']['meta']['caller_role'])->toBe('app');
    });

    it('rejects control-node callers with gateway_unavailable', function (): void {
        DB::table('nodes')->insert(nodeUpdateBaseRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
            'is_local' => true,
        ]));

        DB::table('nodes')->insert(nodeUpdateBaseRow());

        $exitCode = Artisan::call('node:update', [
            'name' => 'app-1',
            '--host' => '10.6.0.99',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('gateway_unavailable');
    });

    it('rejects unknown callers with local_context_invalid', function (): void {
        DB::table('nodes')->insert(nodeUpdateBaseRow([
            'name' => 'bogus-local',
            'role' => 'bogus',
            'environment' => null,
            'is_local' => true,
        ]));

        DB::table('nodes')->insert(nodeUpdateBaseRow());

        $exitCode = Artisan::call('node:update', [
            'name' => 'app-1',
            '--host' => '10.6.0.99',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('local_context_invalid')
            ->and($payload['error']['meta']['caller_role'])->toBe('unknown');
    });
});

describe('node:update field value validation', function (): void {
    beforeEach(function (): void {
        setupGatewayCallerBase();
    });

    it('rejects empty host value', function (): void {
        DB::table('nodes')->insert(nodeUpdateBaseRow());

        $exitCode = Artisan::call('node:update', [
            'name' => 'app-1',
            '--host' => '',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('host');
    });

    it('rejects invalid environment value', function (): void {
        DB::table('nodes')->insert(nodeUpdateBaseRow());

        $exitCode = Artisan::call('node:update', [
            'name' => 'app-1',
            '--environment' => 'staging',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('environment')
            ->and($payload['error']['meta']['value'])->toBe('staging')
            ->and($payload['error']['meta']['allowed'])->toBe(['development', 'production']);
    });

    it('rejects invalid public-ipv4 format', function (): void {
        DB::table('nodes')->insert(nodeUpdateBaseRow());

        $exitCode = Artisan::call('node:update', [
            'name' => 'app-1',
            '--public-ipv4' => 'not-an-ip',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('public_ipv4');
    });

    it('rejects invalid public-ipv6 format', function (): void {
        DB::table('nodes')->insert(nodeUpdateBaseRow());

        $exitCode = Artisan::call('node:update', [
            'name' => 'app-1',
            '--public-ipv6' => 'not-an-ip',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('public_ipv6');
    });

    it('accepts valid ipv6 address', function (): void {
        DB::table('nodes')->insert(nodeUpdateBaseRow());

        $exitCode = Artisan::call('node:update', [
            'name' => 'app-1',
            '--public-ipv6' => '2001:db8::1',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['changed'])->toBe(['public_ipv6']);
    });
});

describe('node:update safety', function (): void {
    beforeEach(function (): void {
        setupGatewayCallerBase();
    });

    it('does not invoke ssh or external processes during update', function (): void {
        Process::fake();
        Process::preventStrayProcesses();

        DB::table('nodes')->insert(nodeUpdateBaseRow());

        Artisan::call('node:update', [
            'name' => 'app-1',
            '--host' => '10.6.0.99',
            '--json' => true,
        ]);

        Process::assertNothingRan();
    });

    it('makes only targeted registry mutations', function (): void {
        DB::table('nodes')->insert(nodeUpdateBaseRow());

        $before = (array) DB::table('nodes')->where('name', 'app-1')->first();

        Artisan::call('node:update', [
            'name' => 'app-1',
            '--host' => '10.6.0.99',
            '--json' => true,
        ]);

        $after = (array) DB::table('nodes')->where('name', 'app-1')->first();

        expect($after['host'])->toBe('10.6.0.99')
            ->and($after['environment'])->toBe($before['environment'])
            ->and($after['role'])->toBe($before['role'])
            ->and($after['status'])->toBe($before['status']);
    });
});
