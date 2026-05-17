<?php

declare(strict_types=1);

use App\Exceptions\RemoteShellFailed;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\RemoteShell\SshRemoteShell;
use Illuminate\Contracts\Process\ProcessResult as ProcessResultContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

it('runs local nodes through bash without ssh', function (): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*' => Process::result(output: "ok\n"),
    ]);

    $node = Node::factory()->create([
        'role' => 'gateway',
    ]);
    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'gateway',
        'status' => 'active',
    ]);

    $result = (new SshRemoteShell)->run($node, 'pwd', [
        'cwd' => '/srv/example',
        'env' => ['APP_ENV' => 'testing'],
        'timeout' => 45,
    ]);

    expect($result->successful())->toBeTrue()
        ->and($result->stdout)->toBe("ok\n");

    Process::assertRan(function (PendingProcess $process, ProcessResultContract $processResult): bool {
        return $process->command === "bash -c 'export APP_ENV='\\''testing'\\'' && cd '\\''/srv/example'\\'' && pwd'"
            && $process->timeout === 45
            && $processResult->output() === "ok\n";
    });
});

it('runs nodes with an assigned gateway role through bash without ssh', function (): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*' => Process::result(output: "ok\n"),
    ]);

    $node = Node::factory()->create([
        'role' => 'control',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'gateway',
        'status' => 'active',
    ]);

    $result = (new SshRemoteShell)->run($node, 'pwd');

    expect($result->successful())->toBeTrue()
        ->and($result->stdout)->toBe("ok\n");

    Process::assertRan(fn (PendingProcess $process): bool => $process->command === "bash -c 'pwd'");
});

it('runs remote nodes over ssh using wireguard address and steady state user', function (): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*' => Process::result(output: "cloned\n"),
    ]);

    $node = Node::factory()->create([
        'host' => 'public.example.com',
        'wireguard_address' => '10.44.0.20',
        'user' => 'deploy',
    ]);

    $result = (new SshRemoteShell)->run($node, 'git clone git@github.com:acme/site.git site');

    expect($result->successful())->toBeTrue()
        ->and($result->stdout)->toBe("cloned\n");

    Process::assertRan(function (PendingProcess $process): bool {
        return str_contains((string) $process->command, 'ssh -o StrictHostKeyChecking=accept-new')
            && str_contains((string) $process->command, "'deploy'@'10.44.0.20'")
            && str_contains((string) $process->command, 'bash -lc')
            && str_contains((string) $process->command, 'git clone git@github.com:acme/site.git site');
    });
});

it('falls back to ssh user when steady state user is not recorded', function (): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*' => Process::result(output: "ok\n"),
    ]);

    $node = Node::factory()->create([
        'wireguard_address' => '10.44.0.21',
        'user' => null,
    ]);

    (new SshRemoteShell)->run($node, 'whoami');

    Process::assertRan(fn (PendingProcess $process): bool => str_contains((string) $process->command, "'orbit'@'10.44.0.21'"));
});

it('throws failed remote shell results when requested', function (): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*' => Process::result(
            output: '',
            errorOutput: "permission denied\n",
            exitCode: 13,
        ),
    ]);

    $node = Node::factory()->create([
        'name' => 'app-a',
        'wireguard_address' => null,
        'host' => 'app-a.internal',
    ]);

    expect(fn () => (new SshRemoteShell)->run($node, 'mkdir /srv/example', ['throw' => true]))
        ->toThrow(RemoteShellFailed::class, 'RemoteShell failed on app-a (exit 13): permission denied');
});
