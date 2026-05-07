<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\OrbitUpdater;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\PhpExecutableFinder;
use Tests\TestCase;

uses(TestCase::class);

it('runs migrations with the resolved cli php binary', function (): void {
    app()->instance(PhpExecutableFinder::class, new OrbitUpdaterTestPhpExecutableFinder('/usr/local/bin/php-cli'));

    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
    ]);
    Process::preventStrayProcesses();

    app(OrbitUpdater::class)->runMigrations();

    Process::assertRan(fn ($process): bool => is_array($process->command)
        && $process->command[0] === '/usr/local/bin/php-cli'
        && $process->command[1] === 'artisan'
        && $process->command[2] === 'migrate'
        && $process->command[3] === '--force');
});

it('updates remote nodes with separate stage commands', function (): void {
    $node = new Node([
        'name' => 'beast',
        'orbit_path' => '/home/nckrtl/orbit',
    ]);
    $shell = new OrbitUpdaterTestRemoteShell;
    app()->instance(RemoteShell::class, $shell);

    $result = app(OrbitUpdater::class)->updateRemote($node);

    expect($result->successful())->toBeTrue();
    expect(array_column($shell->calls, 'script'))->toBe([
        'git pull --ff-only',
        'COMPOSER_BIN="$(command -v composer || true)"; if [ -z "$COMPOSER_BIN" ] && [ -x "$HOME/.local/bin/composer" ]; then COMPOSER_BIN="$HOME/.local/bin/composer"; fi; if [ -z "$COMPOSER_BIN" ]; then echo "composer not found" >&2; exit 127; fi; "$COMPOSER_BIN" install --no-interaction',
        'php artisan migrate --force',
    ]);
    expect(array_column($shell->calls, 'cwd'))->toBe([
        '/home/nckrtl/orbit',
        '/home/nckrtl/orbit',
        '/home/nckrtl/orbit',
    ]);
});

final class OrbitUpdaterTestPhpExecutableFinder extends PhpExecutableFinder
{
    public function __construct(private readonly string|false $phpBinary) {}

    public function find(bool $includeArgs = true): string|false
    {
        return $this->phpBinary;
    }
}

final class OrbitUpdaterTestRemoteShell implements RemoteShell
{
    /**
     * @var list<array{node: string, script: string, cwd: string|null, timeout: int|null}>
     */
    public array $calls = [];

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->calls[] = [
            'node' => $node->name,
            'script' => $script,
            'cwd' => $options['cwd'] ?? null,
            'timeout' => $options['timeout'] ?? null,
        ];

        return new RemoteShellResult(
            exitCode: 0,
            stdout: '',
            stderr: '',
            durationMs: 1,
        );
    }
}
