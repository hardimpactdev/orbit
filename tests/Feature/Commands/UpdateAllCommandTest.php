<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

it('updates the local checkout and every active remote node from the registry', function (): void {
    DB::table('nodes')->insert([
        [
            'name' => 'gateway',
            'role' => 'gateway',
            'host' => 'gateway',
            'ssh_user' => 'gateway',
            'orbit_path' => '/home/gateway/orbit',
            'status' => 'active',
            'is_local' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'name' => 'mini',
            'role' => 'control',
            'host' => 'mini',
            'ssh_user' => 'nckrtl',
            'orbit_path' => '/Users/nckrtl/orbit',
            'status' => 'active',
            'is_local' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'name' => 'beast',
            'role' => 'app',
            'host' => 'beast',
            'ssh_user' => 'nckrtl',
            'orbit_path' => '/home/nckrtl/orbit',
            'status' => 'active',
            'is_local' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    Process::fake();
    Process::preventStrayProcesses();

    $this->artisan('update:all')
        ->expectsOutputToContain('Updated local Orbit checkout.')
        ->expectsOutputToContain('Updated node mini.')
        ->expectsOutputToContain('Updated node beast.')
        ->assertSuccessful();

    Process::assertRanTimes(fn (): bool => true, 5);
    Process::assertRan(fn ($process): bool => $process->path === base_path()
        && $process->command === 'git pull --ff-only');
    Process::assertRan(fn ($process): bool => $process->path === base_path()
        && is_array($process->command)
        && in_array('install', $process->command)
        && in_array('--no-interaction', $process->command));
    Process::assertRan(fn ($process): bool => $process->path === base_path()
        && is_array($process->command)
        && in_array('migrate', $process->command)
        && in_array('--force', $process->command));
    Process::assertRan(fn ($process): bool => is_string($process->command)
        && str_starts_with($process->command, "ssh nckrtl@mini 'cd /Users/nckrtl/orbit && ")
        && str_contains($process->command, '"$COMPOSER_BIN" install --no-interaction'));
    Process::assertRan(fn ($process): bool => is_string($process->command)
        && str_starts_with($process->command, "ssh nckrtl@beast 'cd /home/nckrtl/orbit && ")
        && str_contains($process->command, '"$COMPOSER_BIN" install --no-interaction'));
});
