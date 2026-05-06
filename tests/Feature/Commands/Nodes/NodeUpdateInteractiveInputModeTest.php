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

function setupNodeUpdateInteractiveGatewayCaller(): void
{
    DB::table('nodes')->insert(nodeUpdateInteractiveRow([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'environment' => null,
        'is_local' => true,
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
                'public_ipv4',
                'public_ipv6',
            ])
            ->expectsChoice('Environment', 'production', ['development', 'production'])
            ->expectsOutputToContain('Changed: environment')
            ->assertSuccessful();

        expect(DB::table('nodes')->where('name', 'app-1')->value('environment'))->toBe('production');
    });

    it('does not prompt when json forces non-interactive mode', function (): void {
        setupNodeUpdateInteractiveGatewayCaller();
        DB::table('nodes')->insert(nodeUpdateInteractiveRow());

        $this->artisan('node:update --json')
            ->expectsOutputToContain('Node name is required.')
            ->assertFailed();
    });

    it('denies app-node callers before prompts', function (): void {
        DB::table('nodes')->insert(nodeUpdateInteractiveRow([
            'name' => 'local-app',
            'is_local' => true,
        ]));
        DB::table('nodes')->insert(nodeUpdateInteractiveRow());

        $this->artisan('node:update')
            ->expectsOutputToContain('This command may only be run from a control or gateway node.')
            ->assertFailed();
    });
});
