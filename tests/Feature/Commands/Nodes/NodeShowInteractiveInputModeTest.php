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
function nodeShowInteractiveRow(array $overrides = []): array
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

function setupShowInteractiveGatewayCaller(): void
{
    DB::table('nodes')->where('is_local', true)->delete();
    DB::table('nodes')->insert(nodeShowInteractiveRow([
        'name' => 'test-gateway',
        'role' => 'gateway',
        'environment' => null,
        'is_local' => true,
    ]));
}

function setupShowInteractiveControlCaller(): void
{
    DB::table('nodes')->where('is_local', true)->delete();
    DB::table('nodes')->insert(nodeShowInteractiveRow([
        'name' => 'test-control',
        'role' => 'control',
        'environment' => null,
        'is_local' => true,
    ]));
}

function setupShowInteractiveAppCaller(): void
{
    DB::table('nodes')->where('is_local', true)->delete();
    DB::table('nodes')->insert(nodeShowInteractiveRow([
        'name' => 'test-app',
        'role' => 'app',
        'environment' => 'development',
        'is_local' => true,
    ]));
}

describe('node:show interactive input mode', function (): void {
    beforeEach(function (): void {
        setupShowInteractiveGatewayCaller();
    });

    it('uses interactive mode when TTY and --json is absent', function (): void {
        DB::table('nodes')->insert(nodeShowInteractiveRow());

        $exitCode = Artisan::call('node:show', [
            'name' => 'app-1',
            '--no-interaction' => false,
        ]);

        expect($exitCode)->toBe(0);
    });

    it('opts out of interactive mode when --json is present', function (): void {
        DB::table('nodes')->insert(nodeShowInteractiveRow());

        $exitCode = Artisan::call('node:show', [
            'name' => 'app-1',
            '--json' => true,
            '--no-interaction' => false,
        ]);

        $output = Artisan::output();
        $payload = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0);
        expect($payload)->toBeArray();
        expect($payload)->toHaveKey('success');
        expect($payload['success'])->toBeArray();
        expect($payload['success'])->toHaveKey('data');
    });

    it('resolves local default node when set and name is missing', function (): void {
        DB::table('nodes')->insert(nodeShowInteractiveRow(['name' => 'default-app']));

        DB::table('local_node_defaults')->insert([
            'default_node_name' => 'default-app',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exitCode = Artisan::call('node:show', [
            '--no-interaction' => false,
        ]);

        expect($exitCode)->toBe(0);
    });

    it('resolves calling node when no default is set and name is missing', function (): void {
        DB::table('nodes')->insert(nodeShowInteractiveRow(['name' => 'other-gateway', 'role' => 'gateway']));

        $exitCode = Artisan::call('node:show', [
            '--no-interaction' => false,
        ]);

        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->toContain('test-gateway');
    });

    it('prompts for name when neither default nor calling node can be resolved', function (): void {
        DB::table('nodes')->delete();
        DB::table('local_node_defaults')->delete();

        $exitCode = Artisan::call('node:show', [
            '--no-interaction' => false,
        ]);

        $output = Artisan::output();

        expect($exitCode)->not->toBe(0);
        expect($output)->not->toContain('No gateway node');
    });

    it('does not deny app callers before prompts or side effects', function (): void {
        setupShowInteractiveAppCaller();

        DB::table('nodes')->insert(nodeShowInteractiveRow(['name' => 'visible-app']));

        $exitCode = Artisan::call('node:show', [
            'name' => 'visible-app',
            '--no-interaction' => false,
        ]);

        $output = Artisan::output();

        expect($exitCode)->not->toBe(0);
        expect($output)->toContain('Gateway');
    });

    it('forwards for control callers when not on gateway', function (): void {
        setupShowInteractiveControlCaller();

        DB::table('nodes')->insert(nodeShowInteractiveRow(['name' => 'some-app']));

        $exitCode = Artisan::call('node:show', [
            'name' => 'some-app',
            '--no-interaction' => false,
        ]);

        $output = Artisan::output();

        expect($exitCode)->not->toBe(0);
        expect($output)->toContain('Gateway connection is required');
    });

    it('executes locally for gateway callers', function (): void {
        DB::table('nodes')->insert(nodeShowInteractiveRow(['name' => 'local-app']));

        $exitCode = Artisan::call('node:show', [
            'name' => 'local-app',
            '--no-interaction' => false,
        ]);

        expect($exitCode)->toBe(0);
    });
});
