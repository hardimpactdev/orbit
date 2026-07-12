<?php

declare(strict_types=1);

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\OrbitUpdater;
use App\Services\RemoteShell\RunsInternalCommands;
use App\Services\Tools\ToolScriptDispatcher;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class);

it('runs gateway migrations inside the orbit-gateway container', function (): void {
    Process::fake(['*' => Process::result()]);
    Process::preventStrayProcesses();

    app(OrbitUpdater::class)->runMigrations();

    Process::assertRan(
        fn ($process): bool => (
            $process->command === [
                'docker',
                'exec',
                'orbit-gateway',
                'php',
                'apps/gateway/artisan',
                'migrate',
                '--force',
            ]
        ),
    );
});

it('runs remote update stages through Agent-pushed tool scripts', function (): void {
    $executor = new OrbitUpdaterRecordingExecutor;
    $updater = new OrbitUpdater(new ToolScriptDispatcher($executor));
    $node = new Node(['name' => 'beast', 'orbit_path' => '/home/nckrtl/orbit']);

    expect($updater->updateRemote($node)->successful())
        ->toBeTrue()
        ->and(array_column($executor->payloads, 'action'))
        ->toBe(['update', 'update', 'update'])
        ->and(array_column($executor->payloads, 'script'))
        ->toBe([
            "cd '/home/nckrtl/orbit' && git pull --ff-only",
            "cd '/home/nckrtl/orbit' && docker exec orbit-gateway composer --working-dir=apps/gateway install --no-interaction",
            "cd '/home/nckrtl/orbit' && docker exec orbit-gateway php apps/gateway/artisan migrate --force",
        ]);
});

final class OrbitUpdaterRecordingExecutor implements RunsInternalCommands
{
    /** @var list<array<string, mixed>> */
    public array $payloads = [];

    public function runInternal(
        Node $node,
        string $commandName,
        array $arguments = [],
        array $commandOptions = [],
        array $transportOptions = [],
    ): RemoteShellResult {
        $payload = json_decode((string) $transportOptions['input'], true, flags: JSON_THROW_ON_ERROR);
        $this->payloads[] = $payload;

        return new RemoteShellResult(
            exitCode: 0,
            stdout: json_encode([
                'success' => ['data' => [
                    'exit_code' => 0,
                    'stdout' => '',
                    'stderr' => '',
                    'duration_ms' => 1,
                ]],
            ], JSON_THROW_ON_ERROR),
            stderr: '',
            durationMs: 1,
        );
    }
}
