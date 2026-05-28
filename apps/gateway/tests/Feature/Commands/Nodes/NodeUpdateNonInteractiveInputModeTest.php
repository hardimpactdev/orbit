<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nodeUpdateNonInteractiveRow(array $overrides = []): array
{
    return array_merge([
        'name' => 'app-1',
        'host' => '10.6.0.7',
        'wireguard_address' => '10.6.0.7',
        'user' => 'nckrtl',
        'orbit_path' => '/home/nckrtl/orbit',
        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'tld' => null,
        'public_ipv4' => null,
        'public_ipv6' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

function setupNodeUpdateNonInteractiveGatewayCaller(): void
{
    config(['orbit.is_gateway' => true]);

    DB::table('nodes')->insert(nodeUpdateNonInteractiveRow([
        'name' => 'gateway-1',
    ]));
}

describe('node:update non-interactive input mode', function (): void {
    it('rejects missing required name when json forces non-interactive mode', function (): void {
        setupNodeUpdateNonInteractiveGatewayCaller();

        $exitCode = Artisan::call('node:update', [
            '--tld' => 'test',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['message'])->toBe('Node name is required.')
            ->and($payload['error']['meta']['field'])->toBe('name');
    });

    it('rejects missing field flags when json forces non-interactive mode', function (): void {
        setupNodeUpdateNonInteractiveGatewayCaller();
        DB::table('nodes')->insert(nodeUpdateNonInteractiveRow());

        $exitCode = Artisan::call('node:update', [
            'name' => 'app-1',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['message'])->toBe('At least one field must be provided to update a node.')
            ->and($payload['error']['meta']['field'])->toBe('fields');
    });

    it('rejects tld on a non-app target', function (): void {
        setupNodeUpdateNonInteractiveGatewayCaller();

        $exitCode = Artisan::call('node:update', [
            'name' => 'gateway-1',
            '--tld' => 'test',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('node.field_role_incompatible')
            ->and($payload['error']['meta']['field'])->toBe('tld')
            ->and($payload['error']['meta']['name'])->toBe('gateway-1')
            ->and($payload['error']['meta']['role'])->toBe('gateway')
            ->and(DB::table('nodes')->where('name', 'gateway-1')->value('tld'))->toBeNull();
    });

    it('rejects tld on a production app that remains production', function (): void {
        setupNodeUpdateNonInteractiveGatewayCaller();
        DB::table('nodes')->insert(nodeUpdateNonInteractiveRow([
        ]));

        $exitCode = Artisan::call('node:update', [
            'name' => 'app-1',
            '--tld' => 'test',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('node.field_role_incompatible')
            ->and($payload['error']['meta']['field'])->toBe('tld')
            ->and($payload['error']['meta']['role'])->toBe('app')
            ->and(DB::table('nodes')->where('name', 'app-1')->value('tld'))->toBeNull();
    });

    it('updates tld on a development app', function (): void {
        setupNodeUpdateNonInteractiveGatewayCaller();
        DB::table('nodes')->insert(nodeUpdateNonInteractiveRow());

        $exitCode = Artisan::call('node:update', [
            'name' => 'app-1',
            '--tld' => 'test',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['changed'])->toBe(['tld'])
            ->and(DB::table('nodes')->where('name', 'app-1')->value('tld'))->toBe('test');
    });

    it('updates production app to development and tld in the same non-interactive invocation', function (): void {
        setupNodeUpdateNonInteractiveGatewayCaller();
        DB::table('nodes')->insert(nodeUpdateNonInteractiveRow([
        ]));

        $exitCode = Artisan::call('node:update', [
            'name' => 'app-1',
            '--environment' => 'development',
            '--tld' => 'test',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['changed'])->toBe(['environment', 'tld'])
            ->and(DB::table('nodes')->where('name', 'app-1')->value('environment'))->toBe('development')
            ->and(DB::table('nodes')->where('name', 'app-1')->value('tld'))->toBe('test');
    });

    it('rejects duplicate tld across active nodes', function (): void {
        setupNodeUpdateNonInteractiveGatewayCaller();
        DB::table('nodes')->insert(nodeUpdateNonInteractiveRow());
        DB::table('nodes')->insert(nodeUpdateNonInteractiveRow([
            'name' => 'app-2',
            'wireguard_address' => '10.6.0.8',
            'tld' => 'test',
        ]));

        $exitCode = Artisan::call('node:update', [
            'name' => 'app-1',
            '--tld' => 'test',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('node.tld_in_use')
            ->and($payload['error']['meta']['field'])->toBe('tld')
            ->and($payload['error']['meta']['value'])->toBe('test')
            ->and(DB::table('nodes')->where('name', 'app-1')->value('tld'))->toBeNull();
    });

    it('rejects invalid tld syntax', function (): void {
        setupNodeUpdateNonInteractiveGatewayCaller();
        DB::table('nodes')->insert(nodeUpdateNonInteractiveRow());

        $exitCode = Artisan::call('node:update', [
            'name' => 'app-1',
            '--tld' => 'Invalid_TLD!',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('tld')
            ->and($payload['error']['meta']['value'])->toBe('Invalid_TLD!');
    });
});
