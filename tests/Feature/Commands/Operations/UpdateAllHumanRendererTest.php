<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

beforeEach(function (): void {
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
});

it('renders progress tree shape', function (): void {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
    ]);
    Process::preventStrayProcesses();

    app()->instance(RemoteShell::class, new UpdateAllHumanRemoteShell);

    $this->artisan('update:all')
        ->expectsOutputToContain('┌ Updating Orbit Installations')
        ->expectsOutputToContain('Update local checkout')
        ->expectsOutputToContain('Update beast')
        ->assertSuccessful();
});

it('renders success prose', function (): void {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
    ]);
    Process::preventStrayProcesses();

    app()->instance(RemoteShell::class, new UpdateAllHumanRemoteShell);

    $this->artisan('update:all')
        ->expectsOutputToContain('Updated local Orbit checkout.')
        ->expectsOutputToContain('Updated node beast.')
        ->assertSuccessful();
});

it('renders failed local checkout prose', function (): void {
    Process::fake([
        'git pull --ff-only' => Process::result(
            output: '',
            errorOutput: 'merge conflict',
            exitCode: 1,
        ),
    ]);
    Process::preventStrayProcesses();

    app()->instance(RemoteShell::class, new UpdateAllHumanRemoteShell);

    $this->artisan('update:all')
        ->expectsOutputToContain('Failed to update local Orbit checkout.')
        ->expectsOutputToContain('merge conflict')
        ->assertFailed();
});

it('renders partial remote failure prose and continues', function (): void {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
    ]);
    Process::preventStrayProcesses();

    app()->instance(RemoteShell::class, new UpdateAllHumanRemoteShell(exitCode: 255, stderr: 'Permission denied (publickey).'));

    $this->artisan('update:all')
        ->expectsOutputToContain('Updated local Orbit checkout.')
        ->expectsOutputToContain('Failed to update node beast.')
        ->expectsOutputToContain('Permission denied (publickey).')
        ->assertFailed();
});

it('has no json envelope in human mode', function (): void {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
    ]);
    Process::preventStrayProcesses();

    app()->instance(RemoteShell::class, new UpdateAllHumanRemoteShell);

    $this->artisan('update:all')
        ->expectsOutputToContain('Updated local Orbit checkout.')
        ->assertSuccessful();
});

it('excludes control nodes from human output', function (): void {
    DB::table('nodes')->insert([
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
    ]);

    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
    ]);
    Process::preventStrayProcesses();

    app()->instance(RemoteShell::class, new UpdateAllHumanRemoteShell);

    $exitCode = Artisan::call('update:all');
    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect(str_contains($output, 'Updated local Orbit checkout.'))->toBeTrue();
    expect(str_contains($output, 'mini'))->toBeFalse();
});

final class UpdateAllHumanRemoteShell implements RemoteShell
{
    public function __construct(
        private readonly int $exitCode = 0,
        private readonly string $stderr = '',
    ) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        return new RemoteShellResult(
            exitCode: $this->exitCode,
            stdout: '',
            stderr: $this->stderr,
            durationMs: 1,
        );
    }
}
