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

it('updates the local checkout and every active non-control remote node from the registry', function (): void {
    DB::table('nodes')->insert([
        [
            'name' => 'gateway',
            'host' => 'gateway',
            'orbit_path' => '/home/gateway/orbit',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'name' => 'mini',
            'host' => 'mini',
            'orbit_path' => '/Users/nckrtl/orbit',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'name' => 'beast',
            'host' => 'beast',
            'orbit_path' => '/home/nckrtl/orbit',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    DB::table('node_role')->insert([
        'node_id' => DB::table('nodes')->where('name', 'gateway')->value('id'),
        'role' => 'gateway',
        'status' => 'active',
        'settings' => json_encode([], JSON_THROW_ON_ERROR),
        'last_error' => null,
        'converged_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('node_role')->insert([
        'node_id' => DB::table('nodes')->where('name', 'beast')->value('id'),
        'role' => 'app-dev',
        'status' => 'active',
        'settings' => json_encode(['tld' => 'test'], JSON_THROW_ON_ERROR),
        'last_error' => null,
        'converged_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Process::fake();
    Process::preventStrayProcesses();

    $logPath = tempnam(sys_get_temp_dir(), 'orbit-update-all-command-');

    if ($logPath === false) {
        $this->fail('Could not create update command log.');
    }

    try {
        app()->instance(RemoteShell::class, new UpdateAllRemoteShell(exitCode: 0, logPath: $logPath));

        $exitCode = Artisan::call('update:all', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true);

        expect($exitCode)->toBe(0);
        expect($payload['success']['data']['updates'])->toHaveCount(2);
        expect($payload['success']['data']['updates'][0]['target'])->toBe('local');
        expect($payload['success']['data']['updates'][1]['target'])->toBe('beast');

        Process::assertRanTimes(fn (): bool => true, 3);
        Process::assertRan(fn ($process): bool => $process->path === repo_path()
            && $process->command === 'git pull --ff-only');
        Process::assertRan(fn ($process): bool => $process->path === repo_path()
            && is_array($process->command)
            && in_array('install', $process->command)
            && in_array('--no-interaction', $process->command));
        Process::assertRan(fn ($process): bool => $process->path === repo_path()
            && is_array($process->command)
            && in_array('migrate', $process->command)
            && in_array('--force', $process->command));

        $lines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        expect($lines)->toBeArray();

        $events = array_map(
            fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            $lines,
        );

        expect(array_column($events, 'node'))->toBe([
            'beast',
            'beast',
            'beast',
        ]);
    } finally {
        @unlink($logPath);
    }
});

it('excludes role-free operator identities from remote update targets', function (): void {
    DB::table('nodes')->insert([
        [
            'name' => 'gateway',
            'host' => 'gateway',
            'orbit_path' => '/home/gateway/orbit',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'name' => 'mini',
            'host' => 'mini',
            'orbit_path' => '/Users/nckrtl/orbit',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    Process::fake();
    Process::preventStrayProcesses();

    $shell = new UpdateAllRemoteShell(exitCode: 0);
    app()->instance(RemoteShell::class, $shell);

    $exitCode = Artisan::call('update:all', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(0);
    expect($payload['success']['data']['updates'])->toHaveCount(1);
    expect($payload['success']['data']['updates'][0]['target'])->toBe('local');

    Process::assertRanTimes(fn (): bool => true, 3);
    expect($shell->nodes)->toHaveCount(0);
});

final class UpdateAllRemoteShell implements RemoteShell
{
    public array $nodes = [];

    public function __construct(
        private readonly int $exitCode = 0,
        private readonly ?string $logPath = null,
    ) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->nodes[] = $node;

        if ($this->logPath !== null) {
            file_put_contents(
                $this->logPath,
                json_encode([
                    'node' => $node->name,
                    'script' => $script,
                ], JSON_THROW_ON_ERROR).PHP_EOL,
                FILE_APPEND | LOCK_EX,
            );
        }

        return new RemoteShellResult(
            exitCode: $this->exitCode,
            stdout: '',
            stderr: '',
            durationMs: 1,
        );
    }
}
