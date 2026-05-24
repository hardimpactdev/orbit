<?php

declare(strict_types=1);

use App\Exceptions\RemoteShellFailed;
use App\Models\Node;
use App\Services\RemoteShell\RemoteHostExecutor;
use Illuminate\Contracts\Process\ProcessResult as ProcessResultContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\FakeInvokedProcess;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('preserves host shell env, timeout, input, stdout, and stderr semantics', function (): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*' => Process::result(output: "host-ok\n", errorOutput: "host-warning\n"),
    ]);

    $result = app(RemoteHostExecutor::class)->run(remoteHostExecutorNode(), 'printf "%s" "$ORBIT_REQUEST_ID"', [
        'cwd' => '/srv/example',
        'metadata' => ['ORBIT_REQUEST_ID' => 'host-req'],
        'timeout' => 45,
        'input' => 'stdin-payload',
    ]);

    expect($result->successful())->toBeTrue()
        ->and($result->stdout)->toBe("host-ok\n")
        ->and($result->stderr)->toBe("host-warning\n");

    Process::assertRan(function (PendingProcess $process, ProcessResultContract $processResult): bool {
        $command = (string) $process->command;

        return str_contains($command, 'ssh -o StrictHostKeyChecking=yes')
            && str_contains($command, 'bash -lc')
            && str_contains($command, 'ORBIT_REQUEST_ID')
            && str_contains($command, 'host-req')
            && str_contains($command, '/srv/example')
            && str_contains($command, 'printf "%s" "$ORBIT_REQUEST_ID"')
            && $process->timeout === 45
            && $process->input === 'stdin-payload'
            && $processResult->output() === "host-ok\n"
            && $processResult->errorOutput() === "host-warning\n";
    });
});

it('throws host shell failures with the current RemoteShellFailed semantics', function (): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*' => Process::result(errorOutput: "permission denied\n", exitCode: 13),
    ]);

    try {
        app(RemoteHostExecutor::class)->run(remoteHostExecutorNode(['name' => 'host-failure']), 'mkdir /srv/example', [
            'throw' => true,
        ]);

        $this->fail('Expected the host executor to throw a remote shell failure.');
    } catch (RemoteShellFailed $exception) {
        expect($exception->node->name)->toBe('host-failure')
            ->and($exception->script)->toBe('mkdir /srv/example')
            ->and($exception->result->exitCode)->toBe(13)
            ->and($exception->getMessage())->toContain('RemoteShell failed on host-failure (exit 13): permission denied');
    }
});

it('starts host shell processes with the same command composition surface', function (): void {
    Process::preventStrayProcesses();
    Process::fake();

    $process = app(RemoteHostExecutor::class)->start(remoteHostExecutorNode(), 'tail -f /var/log/orbit.log', [
        'metadata' => ['ORBIT_REQUEST_ID' => 'host-start'],
        'timeout' => 90,
        'input' => 'start-input',
    ]);

    expect($process)->toBeInstanceOf(FakeInvokedProcess::class)
        ->and($process->command())->toContain('ssh -o StrictHostKeyChecking=yes')
        ->and($process->command())->toContain('tail -f /var/log/orbit.log');

    Process::assertRan(function (PendingProcess $process): bool {
        return $process->timeout === 90
            && $process->input === 'start-input';
    });
});

/**
 * @param  array<string, mixed>  $attributes
 */
function remoteHostExecutorNode(array $attributes = []): Node
{
    return Node::factory()->create([
        'name' => 'host-node',
        'host' => 'host-node.example.com',
        'wireguard_address' => '10.44.0.50',
        'user' => 'orbit',
        ...remoteExecutorPinnedHostKey(),
        ...$attributes,
    ]);
}

/**
 * @return array<string, mixed>
 */
function remoteExecutorPinnedHostKey(): array
{
    return [
        'host_key_type' => 'ssh-ed25519',
        'host_key_public' => 'AAAAC3NzaC1lZDI1NTE5AAAAIRemoteExecutorPinnedKey',
        'host_key_fingerprint' => 'SHA256:remote-executor',
        'host_key_pin_mode' => 'verified',
        'host_key_pinned_at' => now(),
    ];
}
