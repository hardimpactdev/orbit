<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

it('updates the local checkout and every active non-control remote node from the registry', function (): void {
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

    $shell = new UpdateAllRemoteShell(exitCode: 0);
    app()->instance(RemoteShell::class, $shell);

    $this->artisan('update:all')
        ->expectsOutputToContain('Successfully updated 2 nodes')
        ->assertSuccessful();

    Process::assertRanTimes(fn (): bool => true, 3);
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

    expect(array_map(fn (Node $node): string => $node->name, $shell->nodes))->toBe([
        'beast',
        'beast',
        'beast',
    ]);
});

it('excludes control nodes from remote update targets', function (): void {
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
    ]);

    Process::fake();
    Process::preventStrayProcesses();

    $shell = new UpdateAllRemoteShell(exitCode: 0);
    app()->instance(RemoteShell::class, $shell);

    $this->artisan('update:all')
        ->expectsOutputToContain('Successfully updated 1 node')
        ->assertSuccessful();

    Process::assertRanTimes(fn (): bool => true, 3);
    expect($shell->nodes)->toHaveCount(0);
});

final class UpdateAllRemoteShell implements RemoteShell
{
    public array $nodes = [];

    public function __construct(private readonly int $exitCode = 0) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->nodes[] = $node;

        return new RemoteShellResult(
            exitCode: $this->exitCode,
            stdout: '',
            stderr: '',
            durationMs: 1,
        );
    }
}
