<?php

declare(strict_types=1);

use App\Exceptions\RemoteShellFailed;
use App\Models\Node;
use App\Services\RemoteShell\RemoteOrbitRuntimeExecutor;
use Illuminate\Contracts\Process\ProcessResult as ProcessResultContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('wraps plain commands in docker exec on orbit-runtime', function (): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*' => Process::result(output: "installed\n"),
    ]);

    $result = app(RemoteOrbitRuntimeExecutor::class)->run(remoteRuntimeExecutorNode(), 'composer install --no-interaction');

    expect($result->successful())->toBeTrue()
        ->and($result->stdout)->toBe("installed\n");

    Process::assertRan(fn (PendingProcess $process): bool => str_contains(
        (string) $process->command,
        'docker exec -i orbit-runtime composer install --no-interaction',
    ));
});

it('normalizes artisan commands to php artisan inside orbit-runtime', function (string $script): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*' => Process::result(output: "migrated\n"),
    ]);

    app(RemoteOrbitRuntimeExecutor::class)->run(remoteRuntimeExecutorNode(), $script);

    Process::assertRan(fn (PendingProcess $process): bool => str_contains(
        (string) $process->command,
        'docker exec -i orbit-runtime php artisan migrate --force',
    ));
})->with([
    'artisan migrate --force',
    'php artisan migrate --force',
]);

it('preserves runtime env, cwd, timeout, input, stdout, and stderr semantics', function (): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*' => Process::result(output: "runtime-ok\n", errorOutput: "runtime-warning\n"),
    ]);

    $result = app(RemoteOrbitRuntimeExecutor::class)->run(remoteRuntimeExecutorNode(), 'php artisan orbit:cleanup', [
        'cwd' => '/home/orbit/orbit',
        'metadata' => ['ORBIT_REQUEST_ID' => 'runtime-req'],
        'timeout' => 75,
        'input' => 'runtime-stdin',
    ]);

    expect($result->successful())->toBeTrue()
        ->and($result->stdout)->toBe("runtime-ok\n")
        ->and($result->stderr)->toBe("runtime-warning\n");

    Process::assertRan(function (PendingProcess $process, ProcessResultContract $processResult): bool {
        $command = (string) $process->command;

        return str_contains($command, 'docker exec -i')
            && str_contains($command, '--env')
            && str_contains($command, 'ORBIT_REQUEST_ID=runtime-req')
            && str_contains($command, '--workdir')
            && str_contains($command, '/home/orbit/orbit')
            && str_contains($command, 'orbit-runtime php artisan orbit:cleanup')
            && $process->timeout === 75
            && $process->input === 'runtime-stdin'
            && $processResult->output() === "runtime-ok\n"
            && $processResult->errorOutput() === "runtime-warning\n";
    });
});

it('falls back to an in-container shell for compound runtime scripts', function (): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*' => Process::result(output: "compound\n"),
    ]);

    app(RemoteOrbitRuntimeExecutor::class)->run(
        remoteRuntimeExecutorNode(),
        'php artisan migrate --force && php artisan orbit:cleanup',
        ['metadata' => ['ORBIT_REQUEST_ID' => 'runtime-compound']],
    );

    Process::assertRan(function (PendingProcess $process): bool {
        $command = (string) $process->command;

        return str_contains($command, 'docker exec -i orbit-runtime sh -lc')
            && str_contains($command, 'ORBIT_REQUEST_ID')
            && str_contains($command, 'php artisan migrate --force && php artisan orbit:cleanup');
    });
});

it('throws runtime shell failures with the same RemoteShellFailed semantics', function (): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*' => Process::result(errorOutput: "runtime denied\n", exitCode: 13),
    ]);

    try {
        app(RemoteOrbitRuntimeExecutor::class)->run(remoteRuntimeExecutorNode(['name' => 'runtime-failure']), 'php artisan migrate --force', [
            'throw' => true,
        ]);

        $this->fail('Expected the runtime executor to throw a remote shell failure.');
    } catch (RemoteShellFailed $exception) {
        expect($exception->node->name)->toBe('runtime-failure')
            ->and($exception->script)->toBe('docker exec -i orbit-runtime php artisan migrate --force')
            ->and($exception->result->exitCode)->toBe(13)
            ->and($exception->getMessage())->toContain('RemoteShell failed on runtime-failure (exit 13): runtime denied');
    }
});

/**
 * @param  array<string, mixed>  $attributes
 */
function remoteRuntimeExecutorNode(array $attributes = []): Node
{
    return Node::factory()->create([
        'name' => 'runtime-node',
        'host' => 'runtime-node.example.com',
        'wireguard_address' => '10.44.0.60',
        'user' => 'orbit',
        ...remoteRuntimeExecutorPinnedHostKey(),
        ...$attributes,
    ]);
}

/**
 * @return array<string, mixed>
 */
function remoteRuntimeExecutorPinnedHostKey(): array
{
    return [
        'host_key_type' => 'ssh-ed25519',
        'host_key_public' => 'AAAAC3NzaC1lZDI1NTE5AAAAIRemoteRuntimeExecutorPinnedKey',
        'host_key_fingerprint' => 'SHA256:remote-runtime-executor',
        'host_key_pin_mode' => 'verified',
        'host_key_pinned_at' => now(),
    ];
}
