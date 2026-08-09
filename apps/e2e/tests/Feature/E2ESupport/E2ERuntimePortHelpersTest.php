<?php

declare(strict_types=1);

use App\E2E\Support\DockerHost;
use App\E2E\Support\DockerInstance;
use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;
use App\E2E\Support\E2ETopologyLease;
use App\E2E\Support\SshKeyPair;
use Illuminate\Support\Facades\Process;

it('builds an OS-assigned runtime port command', function (): void {
    expect(e2eRuntimeAvailablePortCommand('0.0.0.0'))
        ->toContain('stream_socket_server')
        ->toContain('0.0.0.0');
});

it('parses a reserved runtime port', function (): void {
    expect(e2eRuntimePortFromOutput("0.0.0.0:49152\n"))->toBe(49_152);
});

it('rejects invalid reserved runtime port output', function (): void {
    expect(fn (): int => e2eRuntimePortFromOutput('not-an-address'))
        ->toThrow(RuntimeException::class, 'Unable to determine the reserved E2E runtime port.');
});

it('supports externally reachable runtime PHP bindings', function (): void {
    expect(e2eRuntimePhpServerCommand(
        48_123,
        [
            'router_path' => '/tmp/router.php',
            'log_path' => '/tmp/router.log',
            'pid_path' => '/tmp/router.pid',
            'bind_address' => '0.0.0.0',
            'display_errors' => false,
        ],
    ))
        ->toContain('0.0.0.0:48123')
        ->toContain('-d display_errors=0');
});

it('reserves an available port inside the selected runtime', function (): void {
    Process::fake([
        '*stream_socket_server*' => Process::result(output: '0.0.0.0:49152'),
    ]);
    Process::preventStrayProcesses();

    expect(e2eAvailableRuntimePort(
        topology: e2eRuntimePortHarness(),
        role: 'gateway',
        bindAddress: '0.0.0.0',
    ))->toBe(49_152);
});

it('retries a runtime PHP server startup race with a newly assigned port', function (): void {
    Process::fake([
        '*stream_socket_server*' => Process::sequence()
            ->push(Process::result(output: '0.0.0.0:49152'))
            ->push(Process::result(output: '0.0.0.0:49153')),
        '*curl -fsS*' => Process::sequence()
            ->push(Process::result(exitCode: 1))
            ->push(Process::result()),
        '*' => Process::result(),
    ]);
    Process::preventStrayProcesses();

    $port = e2eStartRuntimePhpServerOnAvailablePort(
        topology: e2eRuntimePortHarness(),
        role: 'gateway',
        server: [
            'router_path' => '/tmp/router.php',
            'log_path' => '/tmp/router.log',
            'pid_path' => '/tmp/router.pid',
            'health_path' => '/health',
            'bind_address' => '0.0.0.0',
            'display_errors' => false,
        ],
    );

    expect($port)->toBe(49_153);
});

function e2eRuntimePortHarness(): E2ETopologyHarness
{
    $host = new DockerHost(E2EConfig::fromEnvironment());

    return new E2ETopologyHarness(new E2ETopologyLease(
        kind: E2ETopologyKind::OperatorGateway,
        operator: new DockerInstance($host, 'orbit-e2e-runtime-port-operator'),
        gateway: new DockerInstance($host, 'orbit-e2e-runtime-port-gateway'),
        dev: null,
        prod: null,
        sshKeyPair: new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub'),
        rebuild: fn () => throw new RuntimeException('not expected'),
    ));
}
