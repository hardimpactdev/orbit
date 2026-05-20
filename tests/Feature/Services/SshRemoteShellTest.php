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

it('rejects invalid environment variable names before composing shell commands', function (): void {
    Process::preventStrayProcesses();
    Process::fake();

    $node = Node::factory()->create([
        'role' => 'gateway',
    ]);
    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'gateway',
        'status' => 'active',
    ]);

    expect(fn () => (new SshRemoteShell)->run($node, 'pwd', [
        'env' => ['APP_ENV; touch /tmp/orbit-pwned' => 'testing'],
    ]))->toThrow(InvalidArgumentException::class, 'Invalid remote shell environment variable name');

    Process::assertRanTimes(fn (): bool => true, 0);
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

it('uses the host when docker topology mode is dns-alias', function (): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*' => Process::result(output: "ok\n"),
    ]);

    putenv('ORBIT_E2E_DOCKER_TOPOLOGY_MODE=dns-alias');

    try {
        $node = Node::factory()->create([
            'host' => 'dev',
            'wireguard_address' => '10.6.0.4',
            'user' => 'deploy',
        ]);

        (new SshRemoteShell)->run($node, 'hostname');

        Process::assertRan(function (PendingProcess $process): bool {
            return str_contains((string) $process->command, "'deploy'@'dev'")
                && ! str_contains((string) $process->command, "'deploy'@'10.6.0.4'");
        });
    } finally {
        putenv('ORBIT_E2E_DOCKER_TOPOLOGY_MODE');
    }
});

it('uses the host when docker topology mode is loaded from laravel env', function (): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*' => Process::result(output: "ok\n"),
    ]);

    $previousServer = $_SERVER['ORBIT_E2E_DOCKER_TOPOLOGY_MODE'] ?? null;
    $previousEnv = $_ENV['ORBIT_E2E_DOCKER_TOPOLOGY_MODE'] ?? null;
    putenv('ORBIT_E2E_DOCKER_TOPOLOGY_MODE');
    $_ENV['ORBIT_E2E_DOCKER_TOPOLOGY_MODE'] = 'dns-alias';
    $_SERVER['ORBIT_E2E_DOCKER_TOPOLOGY_MODE'] = 'dns-alias';

    try {
        $node = Node::factory()->create([
            'host' => 'dev',
            'wireguard_address' => '10.6.0.4',
            'user' => 'deploy',
        ]);

        (new SshRemoteShell)->run($node, 'hostname');

        Process::assertRan(function (PendingProcess $process): bool {
            return str_contains((string) $process->command, "'deploy'@'dev'")
                && ! str_contains((string) $process->command, "'deploy'@'10.6.0.4'");
        });
    } finally {
        if ($previousEnv === null) {
            unset($_ENV['ORBIT_E2E_DOCKER_TOPOLOGY_MODE']);
        } else {
            $_ENV['ORBIT_E2E_DOCKER_TOPOLOGY_MODE'] = $previousEnv;
        }

        if ($previousServer === null) {
            unset($_SERVER['ORBIT_E2E_DOCKER_TOPOLOGY_MODE']);
        } else {
            $_SERVER['ORBIT_E2E_DOCKER_TOPOLOGY_MODE'] = $previousServer;
        }
    }
});

it('uses the wireguard address by default when docker topology mode is not dns-alias', function (): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*' => Process::result(output: "ok\n"),
    ]);

    putenv('ORBIT_E2E_DOCKER_TOPOLOGY_MODE');

    $node = Node::factory()->create([
        'host' => 'dev',
        'wireguard_address' => '10.6.0.4',
        'user' => 'deploy',
    ]);

    (new SshRemoteShell)->run($node, 'hostname');

    Process::assertRan(function (PendingProcess $process): bool {
        return str_contains((string) $process->command, "'deploy'@'10.6.0.4'")
            && ! str_contains((string) $process->command, "'deploy'@'dev'");
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
