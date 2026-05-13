<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nodeUpdateInteractiveRow(array $overrides = []): array
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
        'public_ipv4' => null,
        'public_ipv6' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

function setupNodeUpdateInteractiveGatewayCaller(): void
{
    config(['orbit.is_gateway' => true]);

    DB::table('nodes')->insert(nodeUpdateInteractiveRow([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'environment' => null,
    ]));
}

describe('node:update interactive input mode', function (): void {
    it('prompts for missing name, field selection, and selected field value', function (): void {
        setupNodeUpdateInteractiveGatewayCaller();
        DB::table('nodes')->insert(nodeUpdateInteractiveRow());

        $this->artisan('node:update')
            ->expectsQuestion('Node name', 'app-1')
            ->expectsChoice('Which field would you like to update?', 'host', [
                'host',
                'environment',
                'tld',
                'public_ipv4',
                'public_ipv6',
            ])
            ->expectsQuestion('Host', '10.6.0.99')
            ->expectsOutputToContain("Node 'app-1' updated")
            ->assertSuccessful();

        expect(DB::table('nodes')->where('name', 'app-1')->value('host'))->toBe('10.6.0.99');
    });

    it('prompts for field selection when the name is supplied without field flags', function (): void {
        setupNodeUpdateInteractiveGatewayCaller();
        DB::table('nodes')->insert(nodeUpdateInteractiveRow());

        $this->artisan('node:update app-1')
            ->expectsChoice('Which field would you like to update?', 'environment', [
                'host',
                'environment',
                'tld',
                'public_ipv4',
                'public_ipv6',
            ])
            ->expectsChoice('Environment', 'production', ['development', 'production'])
            ->expectsOutputToContain('Changed: environment')
            ->assertSuccessful();

        expect(DB::table('nodes')->where('name', 'app-1')->value('environment'))->toBe('production');
    });

    it('prompts for tld and persists the selected value', function (): void {
        setupNodeUpdateInteractiveGatewayCaller();
        DB::table('nodes')->insert(nodeUpdateInteractiveRow(['tld' => null]));

        $this->artisan('node:update app-1')
            ->expectsChoice('Which field would you like to update?', 'tld', [
                'host',
                'environment',
                'tld',
                'public_ipv4',
                'public_ipv6',
            ])
            ->expectsQuestion('Development TLD', 'test')
            ->expectsOutputToContain('Changed: tld')
            ->assertSuccessful();

        expect(DB::table('nodes')->where('name', 'app-1')->value('tld'))->toBe('test');
    });

    it('omits tld from prompts on production app nodes', function (): void {
        setupNodeUpdateInteractiveGatewayCaller();
        DB::table('nodes')->insert(nodeUpdateInteractiveRow([
            'environment' => 'production',
            'tld' => null,
        ]));

        $this->artisan('node:update app-1')
            ->expectsChoice('Which field would you like to update?', 'host', [
                'host',
                'environment',
                'public_ipv4',
                'public_ipv6',
            ])
            ->expectsQuestion('Host', '10.6.0.99')
            ->assertSuccessful();
    });

    it('does not prompt when json forces non-interactive mode', function (): void {
        setupNodeUpdateInteractiveGatewayCaller();
        DB::table('nodes')->insert(nodeUpdateInteractiveRow());

        $this->artisan('node:update --json')
            ->expectsOutputToContain('Node name is required.')
            ->assertFailed();
    });
});
