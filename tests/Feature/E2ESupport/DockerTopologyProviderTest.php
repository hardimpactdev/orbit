<?php

declare(strict_types=1);

use App\E2E\Support\DockerHost;
use App\E2E\Support\DockerInstance;
use App\E2E\Support\DockerTopologyNetworkPlan;
use App\E2E\Support\DockerTopologyProvider;
use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EPhaseTimer;
use App\E2E\Support\E2EResourceLeasePool;
use App\E2E\Support\E2ETopologyAcquisitionOptions;
use App\E2E\Support\E2ETopologyCapabilities;
use App\E2E\Support\E2ETopologyKind;
use App\E2E\Support\SshKeyPair;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    putenv('ORBIT_E2E_DOCKER_TEST_RUNNERS=local:8:64,beast:8:64,sidecar1:8:64,sidecar2:8:64');
    $this->dockerLeaseDirectory = storage_path('framework/e2e/test-leases-'.bin2hex(random_bytes(4)));
    putenv("ORBIT_E2E_LEASE_DIRECTORY={$this->dockerLeaseDirectory}");
    putenv('ORBIT_E2E_SLOT_WAIT_SECONDS=0');
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->dockerLeaseDirectory));
    putenv('ORBIT_E2E_DOCKER_TEST_RUNNERS');
    putenv('ORBIT_E2E_LEASE_DIRECTORY');
    putenv('ORBIT_E2E_SLOT_WAIT_SECONDS');
});

it('runs docker exec for instance commands', function (): void {
    Process::fake([
        "docker exec 'orbit-e2e-run-operator' sh -lc *" => Process::result(output: "ok\n"),
    ]);

    $instance = new DockerInstance(new DockerHost(E2EConfig::fromEnvironment()), 'orbit-e2e-run-operator');

    $result = $instance->exec('echo ok');

    expect($result->successful())->toBeTrue()
        ->and($result->output())->toBe("ok\n");
});

it('maps ssh transport to user-scoped docker exec for container feature topologies', function (): void {
    Process::fake([
        "docker exec --user 'orbit' 'orbit-e2e-run-operator' sh -lc *" => Process::result(output: "orbit\n"),
    ]);

    $instance = new DockerInstance(new DockerHost(E2EConfig::fromEnvironment()), 'orbit-e2e-run-operator');

    $result = $instance->ssh('orbit', new SshKeyPair('/tmp/fake', '/tmp/fake.pub'), 'whoami');

    expect($result->successful())->toBeTrue()
        ->and($result->output())->toBe("orbit\n");
});

it('reads ipv4 from the named docker network only', function (): void {
    Process::fake([
        "docker inspect -f '{{(index .NetworkSettings.Networks \"orbit-e2e-run\").IPAddress}}' 'orbit-e2e-run-operator'" => Process::result(output: "10.6.0.3\n"),
    ]);

    $instance = new DockerInstance(new DockerHost(E2EConfig::fromEnvironment()), 'orbit-e2e-run-operator', 'orbit-e2e-run');

    expect($instance->waitForIpv4())->toBe('10.6.0.3');
});

it('starts Docker client topology nodes without a runtime sibling container', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = $process->command;

        return Process::result(output: str_starts_with($process->command, 'docker run -d ') ? "container-id\n" : '');
    });

    $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

    $lease = $provider->acquire(E2ETopologyKind::Control, 'run123', new E2EPhaseTimer, new E2ETopologyAcquisitionOptions);

    $setup = implode("\n", $commands);

    expect($setup)
        ->toContain('--group-add "$(stat -c %g /var/run/docker.sock 2>/dev/null || stat -f %g /var/run/docker.sock)"')
        ->toContain("--volume '/var/run/docker.sock:/var/run/docker.sock'")
        ->toContain("--mount 'type=volume,src=orbit-e2e-run123-operator-home-orbit,dst=/home/orbit'")
        ->toContain("--env 'ORBIT_E2E_DOCKER_NETWORK=orbit-e2e-run123'")
        ->not->toContain('ORBIT_RUNTIME_CONTAINER=orbit-e2e-run123-operator-orbit-runtime')
        ->not->toContain("docker run -d --restart unless-stopped --name 'orbit-e2e-run123-operator-orbit-runtime'")
        ->not->toContain('/home/control');

    $lease->cleanup();
});

it('does not use host PHP or host Caddy paths while starting Docker gateway API support', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = $process->command;

        return Process::result(output: str_starts_with($process->command, 'docker run -d ') ? "container-id\n" : '');
    });

    $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

    $lease = $provider->acquire(
        E2ETopologyKind::ControlGateway,
        'run123',
        new E2EPhaseTimer,
        new E2ETopologyAcquisitionOptions(startGatewayApi: true),
    );

    $setup = implode("\n", $commands);
    $gatewayRuntimeStart = strpos($setup, "docker run -d --restart unless-stopped --name 'orbit-e2e-run123-gateway-orbit-runtime'");
    $gatewayCertificate = strpos($setup, 'issueLeaf');

    expect($gatewayRuntimeStart)->toBeInt()
        ->and($gatewayCertificate)->toBeInt()
        ->and($gatewayRuntimeStart)->toBeLessThan($gatewayCertificate);

    expect($setup)
        ->toContain('orbit tinker --execute=')
        ->toContain('php -d display_errors=0 -S')
        ->not->toContain('orbit serve --host=')
        ->not->toContain('php artisan')
        ->not->toContain('nohup php')
        ->not->toContain('php -r')
        ->not->toContain('systemctl stop caddy');

    $lease->cleanup();
});

it('reports docker unavailable when docker is missing', function (): void {
    Process::fake([
        'command -v docker >/dev/null' => Process::result(exitCode: 1),
    ]);

    $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

    expect($provider->availability(E2ETopologyKind::Control)->available)->toBeFalse();
});

it('reports docker unavailable when prepared per-role image is missing', function (): void {
    Process::fake([
        'command -v docker >/dev/null' => Process::result(),
        'docker info >/dev/null' => Process::result(),
        "docker image inspect 'orbit-runtime:prepared-current' >/dev/null" => Process::result(),
        "docker image inspect 'orbit-e2e-topology:prepared-operator-operator-dns-alias-current' >/dev/null" => Process::result(exitCode: 1),
        "docker image inspect 'orbit-e2e-topology:control-control-current' >/dev/null" => Process::result(exitCode: 1),
    ]);

    $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

    expect($provider->availability(E2ETopologyKind::Control)->available)->toBeFalse()
        ->and($provider->availability(E2ETopologyKind::Control)->message)->toContain('orbit-e2e-topology:prepared-operator-operator-dns-alias-current');
});

it('requires the orbit runtime sibling image only for gateway-backed Docker topology', function (): void {
    Process::fake(function ($process) {
        if ($process->command === 'command -v docker >/dev/null' || $process->command === 'docker info >/dev/null') {
            return Process::result();
        }

        if ($process->command === "docker image inspect 'orbit-runtime:prepared-current' >/dev/null") {
            return Process::result(exitCode: 1);
        }

        if (str_starts_with($process->command, 'docker image inspect ')) {
            return Process::result();
        }

        return Process::result();
    });

    $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

    expect($provider->availability(E2ETopologyKind::Control)->available)->toBeTrue()
        ->and($provider->availability(E2ETopologyKind::ControlGateway)->available)->toBeFalse()
        ->and($provider->availability(E2ETopologyKind::ControlGateway)->message)->toContain('orbit-runtime:prepared-current');
});

it('advertises container feature capabilities', function (): void {
    $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

    expect($provider->capabilities())->toEqual(E2ETopologyCapabilities::containerFeature());
});

it('advertises sibling container Docker support', function (): void {
    $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

    expect($provider->capabilities()->dockerSiblingContainers)->toBeTrue();
});

it('targets remote docker hosts through docker host environment', function (): void {
    $seenEnvironment = null;

    Process::fake(function ($process) use (&$seenEnvironment) {
        $seenEnvironment = $process->environment;

        return Process::result();
    });

    (new DockerHost(E2EConfig::fromEnvironment(), 'beast'))->run('docker info >/dev/null');

    expect($seenEnvironment)->toMatchArray(['DOCKER_HOST' => 'ssh://beast']);
});

it('selects the first docker test runner with image availability', function (): void {
    Process::fake(function ($process) {
        $host = $process->environment['DOCKER_HOST'] ?? 'local';

        if ($process->command === 'command -v docker >/dev/null') {
            return Process::result();
        }

        if ($process->command === 'docker info >/dev/null') {
            return Process::result();
        }

        if (str_contains($process->command, 'docker image inspect') && $host === 'ssh://beast') {
            return Process::result(exitCode: 1);
        }

        if (str_contains($process->command, 'docker image inspect')) {
            return Process::result();
        }

        if ($process->command === "docker ps --format '{{.Names}}' --filter 'name=orbit-e2e-'") {
            return $host === 'ssh://beast'
                ? Process::result(output: "orbit-e2e-a\norbit-e2e-b\n")
                : Process::result(output: '');
        }

        return Process::result();
    });

    withE2EConfigEnvironment([
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'beast:1:2,local:1:2',
    ], function (): void {
        $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

        $availability = $provider->availability(E2ETopologyKind::Control);

        expect($availability->available)->toBeTrue()
            ->and($availability->message)->toContain('local');
    });
});

it('selects the next docker test runner when the first runner is missing images', function (): void {
    Process::fake(function ($process) {
        $host = $process->environment['DOCKER_HOST'] ?? 'local';

        if ($process->command === 'command -v docker >/dev/null') {
            return Process::result();
        }

        if ($process->command === 'docker info >/dev/null') {
            return Process::result();
        }

        if (str_contains($process->command, 'docker image inspect') && $host === 'ssh://sidecar1') {
            return Process::result(exitCode: 1);
        }

        if (str_contains($process->command, 'docker image inspect')) {
            return Process::result();
        }

        if ($process->command === "docker ps --format '{{.Names}}' --filter 'name=orbit-e2e-'") {
            return match ($host) {
                'ssh://sidecar1' => Process::result(output: "orbit-e2e-a\n"),
                'ssh://beast' => Process::result(output: "orbit-e2e-a\norbit-e2e-b\n"),
                default => Process::result(output: ''),
            };
        }

        return Process::result();
    });

    withE2EConfigEnvironment([
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'sidecar1:1:1,beast:1:3',
    ], function (): void {
        $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

        $availability = $provider->availability(E2ETopologyKind::Operator);

        expect($availability->available)->toBeTrue()
            ->and($availability->message)->toContain('beast');
    });
});

it('allows slow remote docker metadata probes during host selection', function (): void {
    $probeTimeouts = [];

    Process::fake(function ($process) use (&$probeTimeouts) {
        if ($process->command === 'docker info >/dev/null' || str_starts_with($process->command, 'docker image inspect ') || str_starts_with($process->command, 'docker ps ')) {
            $probeTimeouts[$process->command] = $process->timeout;
        }

        return Process::result();
    });

    withE2EConfigEnvironment([
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'beast:1:64',
        'ORBIT_E2E_TIMEOUT_SECONDS' => '600',
    ], function () use (&$probeTimeouts): void {
        $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

        expect($provider->availability(E2ETopologyKind::Control)->available)->toBeTrue()
            ->and($probeTimeouts['docker info >/dev/null'])->toBe(120)
            ->and($probeTimeouts["docker image inspect 'orbit-e2e-topology:prepared-operator-operator-dns-alias-current' >/dev/null"])->toBe(120);
    });
});

it('counts running docker containers with the configured e2e instance prefix', function (): void {
    Process::fake(function ($process) {
        if ($process->command === 'command -v docker >/dev/null') {
            return Process::result();
        }

        if ($process->command === 'docker info >/dev/null') {
            return Process::result();
        }

        if (str_contains($process->command, 'docker image inspect')) {
            return Process::result();
        }

        if ($process->command === "docker ps --format '{{.Names}}' --filter 'name=orbit-custom-'") {
            return Process::result(output: "orbit-custom-a\norbit-custom-b\n");
        }

        return Process::result(exitCode: 1, errorOutput: $process->command);
    });

    withE2EConfigEnvironment([
        'ORBIT_E2E_INSTANCE_PREFIX' => 'orbit-custom',
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'local:1:2',
    ], function (): void {
        $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

        expect(fn () => $provider->acquire(E2ETopologyKind::Control, 'run123', new E2EPhaseTimer, new E2ETopologyAcquisitionOptions))
            ->toThrow(RuntimeException::class, 'docker capacity exceeded');
    });
});

it('accounts for the gateway runtime sibling when checking docker capacity', function (): void {
    Process::fake(function ($process) {
        if ($process->command === 'command -v docker >/dev/null' || $process->command === 'docker info >/dev/null') {
            return Process::result();
        }

        if (str_starts_with($process->command, 'docker image inspect ')) {
            return Process::result();
        }

        if ($process->command === "docker ps --format '{{.Names}}' --filter 'name=orbit-e2e-'") {
            return Process::result(output: "orbit-e2e-running-operator\n");
        }

        return Process::result(exitCode: 1, errorOutput: $process->command);
    });

    withE2EConfigEnvironment([
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'local:1:3',
    ], function (): void {
        $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

        expect(fn () => $provider->acquire(E2ETopologyKind::ControlGateway, 'run123', new E2EPhaseTimer, new E2ETopologyAcquisitionOptions))
            ->toThrow(RuntimeException::class, 'docker capacity exceeded');
    });
});

it('does not fail availability on transient docker capacity when host slots are configured', function (): void {
    $probedCapacity = false;

    Process::fake(function ($process) use (&$probedCapacity) {
        if ($process->command === 'command -v docker >/dev/null') {
            return Process::result();
        }

        if ($process->command === 'docker info >/dev/null') {
            return Process::result();
        }

        if (str_contains($process->command, 'docker image inspect')) {
            return Process::result();
        }

        if (str_starts_with($process->command, 'docker ps ')) {
            $probedCapacity = true;

            return Process::result(output: "orbit-e2e-a\norbit-e2e-b\n");
        }

        return Process::result(exitCode: 1, errorOutput: $process->command);
    });

    withE2EConfigEnvironment([
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'sidecar1:1:1',
    ], function () use (&$probedCapacity): void {
        $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());
        $availability = $provider->availability(E2ETopologyKind::ControlGateway);

        expect($availability->available)->toBeTrue()
            ->and($availability->message)->toContain('sidecar1')
            ->and($probedCapacity)->toBeFalse();
    });
});

it('acquires an operator-gateway lease by launching containers from prepared images', function (): void {
    Process::fake(function ($process) {
        $command = $process->command;

        if (
            $command === 'command -v docker >/dev/null'
            || $command === 'docker info >/dev/null'
            || str_starts_with($command, 'docker image inspect ')
            || $command === "docker ps --format '{{.Names}}' --filter 'name=orbit-e2e-'"
            || (str_starts_with($command, "docker network create --subnet '10.") && str_ends_with($command, "'orbit-e2e-run123'"))
            || str_starts_with($command, "docker run -d --name 'orbit-e2e-run123-operator' ")
            || str_starts_with($command, "docker run -d --name 'orbit-e2e-run123-gateway' ")
            || str_starts_with($command, "docker run -d --restart unless-stopped --name 'orbit-e2e-run123-gateway-orbit-runtime' ")
            || str_starts_with($command, 'docker exec ')
        ) {
            return str_contains($command, 'docker run -d ')
                ? Process::result(output: "container-id\n")
                : Process::result();
        }

        return Process::result(exitCode: 1, errorOutput: $command);
    });

    $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

    $lease = $provider->acquire(E2ETopologyKind::ControlGateway, 'run123', new E2EPhaseTimer, new E2ETopologyAcquisitionOptions);

    expect($lease->control()->name())->toBe('orbit-e2e-run123-operator')
        ->and($lease->gateway()?->name())->toBe('orbit-e2e-run123-gateway');

    $lease->cleanup();
});

it('reuses image resolution from host selection when starting docker containers', function (): void {
    $imageInspectCounts = [];

    Process::fake(function ($process) use (&$imageInspectCounts) {
        if ($process->command === 'command -v docker >/dev/null'
            || $process->command === 'docker info >/dev/null'
            || $process->command === "docker ps --format '{{.Names}}' --filter 'name=orbit-e2e-'"
            || str_starts_with($process->command, 'docker network create ')
            || str_starts_with($process->command, 'docker run -d ')
            || str_starts_with($process->command, 'docker exec ')
        ) {
            return Process::result(output: str_starts_with($process->command, 'docker run -d ') ? "container-id\n" : '');
        }

        if (str_starts_with($process->command, 'docker image inspect ')) {
            $imageInspectCounts[$process->command] = ($imageInspectCounts[$process->command] ?? 0) + 1;

            return Process::result();
        }

        return Process::result(exitCode: 1, errorOutput: $process->command);
    });

    $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

    $lease = $provider->acquire(E2ETopologyKind::ControlGateway, 'run123', new E2EPhaseTimer, new E2ETopologyAcquisitionOptions);

    expect($imageInspectCounts["docker image inspect 'orbit-e2e-topology:prepared-operator_gateway_app-dev_app-prod_agent-operator-dns-alias-current' >/dev/null"])->toBe(1)
        ->and($imageInspectCounts["docker image inspect 'orbit-e2e-topology:prepared-operator_gateway_app-dev_app-prod_agent-gateway-dns-alias-current' >/dev/null"])->toBe(1);

    $lease->cleanup();
});

it('retries run-scoped docker subnets when Docker reports an overlap', function (): void {
    withE2EEnvironment(['TEST_TOKEN'], [
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'local:8:64',
    ], function (): void {
        $commands = [];
        $firstPlan = DockerTopologyNetworkPlan::fromEnvironment('run123');
        $retryPlan = DockerTopologyNetworkPlan::fromEnvironment('run123', attempt: 1);

        Process::fake(function ($process) use (&$commands, $firstPlan, $retryPlan) {
            $command = (string) $process->command;
            $commands[] = $command;

            if ($command === 'command -v docker >/dev/null'
                || $command === 'docker info >/dev/null'
                || str_starts_with($command, 'docker image inspect ')
                || $command === "docker ps --format '{{.Names}}' --filter 'name=orbit-e2e-'"
                || str_starts_with($command, 'docker exec ')
            ) {
                return Process::result();
            }

            if ($command === "docker network create --subnet '{$firstPlan->subnet()}' 'orbit-e2e-run123'") {
                return Process::result(errorOutput: 'Error response from daemon: invalid pool request: Pool overlaps with other one on this address space', exitCode: 1);
            }

            if ($command === "docker network create --subnet '{$retryPlan->subnet()}' 'orbit-e2e-run123'") {
                return Process::result();
            }

            if (str_starts_with($command, 'docker run -d ')) {
                return Process::result(output: "container-id\n");
            }

            return Process::result(exitCode: 1, errorOutput: $command);
        });

        $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

        $lease = $provider->acquire(E2ETopologyKind::ControlGateway, 'run123', new E2EPhaseTimer, new E2ETopologyAcquisitionOptions);

        expect($lease->gatewayApiIp())->toBe($retryPlan->ipForRole('gateway'))
            ->and($commands)->toContain("docker network create --subnet '{$firstPlan->subnet()}' 'orbit-e2e-run123'")
            ->and($commands)->toContain("docker network create --subnet '{$retryPlan->subnet()}' 'orbit-e2e-run123'");

        $lease->cleanup();
    });
});

it('launches operator-gateway from the prepared base image', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $command = (string) $process->command;
        $commands[] = $command;

        if ($command === 'command -v docker >/dev/null'
            || $command === 'docker info >/dev/null'
            || $command === "docker image inspect 'orbit-runtime:prepared-current' >/dev/null"
            || $command === "docker ps --format '{{.Names}}' --filter 'name=orbit-e2e-'"
            || str_starts_with($command, 'docker network create ')
            || str_starts_with($command, 'docker exec ')
        ) {
            return Process::result();
        }

        if (str_contains($command, 'prepared-operator_gateway_app-dev_app-prod_agent-operator')
            || str_contains($command, 'prepared-operator_gateway_app-dev_app-prod_agent-gateway')) {
            return Process::result();
        }

        if (str_starts_with($command, 'docker image inspect ')) {
            return Process::result(exitCode: 1);
        }

        if (str_starts_with($command, 'docker run -d ')) {
            return Process::result(output: "container-id\n");
        }

        return Process::result(exitCode: 1, errorOutput: $command);
    });

    withE2EEnvironment([], [
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'local:1:4',
    ], function () use (&$commands): void {
        $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

        $lease = $provider->acquire(E2ETopologyKind::ControlGateway, 'run123', new E2EPhaseTimer, new E2ETopologyAcquisitionOptions);

        expect($lease->control()->name())->toBe('orbit-e2e-run123-operator')
            ->and($lease->gateway()?->name())->toBe('orbit-e2e-run123-gateway')
            ->and($lease->devApp())->toBeNull()
            ->and($lease->prodApp())->toBeNull();

        $lease->cleanup();
    });

    $setup = implode("\n", $commands);

    expect($setup)->toContain("docker image inspect 'orbit-e2e-topology:prepared-operator_gateway_app-dev_app-prod_agent-operator-dns-alias-current' >/dev/null")
        ->and($setup)->toContain("docker image inspect 'orbit-e2e-topology:prepared-operator_gateway_app-dev_app-prod_agent-gateway-dns-alias-current' >/dev/null")
        ->and($setup)->toContain('orbit-e2e-topology:prepared-operator_gateway_app-dev_app-prod_agent-operator-dns-alias-current')
        ->and($setup)->toContain('orbit-e2e-topology:prepared-operator_gateway_app-dev_app-prod_agent-gateway-dns-alias-current')
        ->and($setup)->not->toContain('orbit-e2e-run123-dev')
        ->and($setup)->not->toContain('orbit-e2e-run123-prod')
        ->and($setup)->not->toContain('orbit-e2e-run123-agent');
});

it('launches app production ingress as a prod-node role', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $command = (string) $process->command;
        $commands[] = $command;

        if (str_contains($command, 'cat ~/.ssh/id_ed25519.pub')) {
            return Process::result(output: "ssh-ed25519 AAAATEST orbit-e2e-gateway\n");
        }

        if ($command === 'command -v docker >/dev/null'
            || $command === 'docker info >/dev/null'
            || $command === "docker image inspect 'orbit-runtime:prepared-current' >/dev/null"
            || $command === "docker image inspect 'orbit-e2e-topology-runtime:prepared-current' >/dev/null"
            || $command === "docker ps --format '{{.Names}}' --filter 'name=orbit-e2e-'"
            || str_starts_with($command, 'docker network create ')
            || str_starts_with($command, 'docker exec ')
        ) {
            return Process::result();
        }

        if (str_contains($command, 'prepared-operator_gateway_app-dev_app-prod_agent-operator')
            || str_contains($command, 'prepared-operator_gateway_app-dev_app-prod_agent-gateway')
            || str_contains($command, 'prepared-operator_gateway_app-dev_app-prod_agent-prod')) {
            return Process::result();
        }

        if (str_starts_with($command, 'docker image inspect ')) {
            return Process::result(exitCode: 1);
        }

        if (str_starts_with($command, 'docker run -d ')) {
            return Process::result(output: "container-id\n");
        }

        return Process::result(exitCode: 1, errorOutput: $command);
    });

    withE2EEnvironment([], [
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'local:1:6',
    ], function (): void {
        $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

        $lease = $provider->acquire(
            E2ETopologyKind::OperatorGatewayAppprodIngress,
            'run123',
            new E2EPhaseTimer,
            new E2ETopologyAcquisitionOptions(startGatewayApi: true),
        );

        expect($lease->control()->name())->toBe('orbit-e2e-run123-operator')
            ->and($lease->gateway()?->name())->toBe('orbit-e2e-run123-gateway')
            ->and($lease->prodApp()?->name())->toBe('orbit-e2e-run123-prod')
            ->and($lease->ingress()?->name())->toBe('orbit-e2e-run123-prod')
            ->and($lease->instanceNames())->toBe([
                'orbit-e2e-run123-operator',
                'orbit-e2e-run123-gateway',
                'orbit-e2e-run123-prod',
            ]);

        $lease->cleanup();
    });

    $setup = implode("\n", $commands);

    expect($setup)
        ->toContain("docker image inspect 'orbit-e2e-topology:prepared-operator_gateway_app-dev_app-prod_agent-operator-dns-alias-current' >/dev/null")
        ->toContain("docker image inspect 'orbit-e2e-topology:prepared-operator_gateway_app-dev_app-prod_agent-gateway-dns-alias-current' >/dev/null")
        ->toContain("docker image inspect 'orbit-e2e-topology:prepared-operator_gateway_app-dev_app-prod_agent-prod-dns-alias-current' >/dev/null")
        ->toContain('orbit-e2e-topology:prepared-operator_gateway_app-dev_app-prod_agent-prod-dns-alias-current')
        ->toContain("docker run -d --name 'orbit-e2e-run123-prod'")
        ->toContain('app-prod-1')
        ->not->toContain('orbit-e2e-run123-ingress')
        ->not->toContain('orbit-e2e-topology-runtime:prepared-current')
        ->not->toContain('orbit orbit:internal:bake-ingress-node app-prod-1')
        ->not->toContain('orbit orbit:internal:bake-app-node app-prod-1 --role=app-prod')
        ->not->toContain('edge-1');
});

it('uses the parallel worker token to create a non-overlapping docker network', function (): void {
    Process::fake([
        'command -v docker >/dev/null' => Process::result(),
        'docker info >/dev/null' => Process::result(),
        "docker image inspect 'orbit-runtime:prepared-current' >/dev/null" => Process::result(),
        "docker image inspect 'orbit-e2e-topology:prepared-operator_gateway_app-dev_app-prod_agent-operator-dns-alias-current' >/dev/null" => Process::result(),
        "docker image inspect 'orbit-e2e-topology:prepared-operator_gateway_app-dev_app-prod_agent-gateway-dns-alias-current' >/dev/null" => Process::result(),
        "docker ps --format '{{.Names}}' --filter 'name=orbit-e2e-'" => Process::result(),
        "docker network create --subnet * 'orbit-e2e-run123'" => Process::result(),
        "docker run -d --name 'orbit-e2e-run123-operator' *" => Process::result(output: "control-id\n"),
        "docker run -d --name 'orbit-e2e-run123-gateway' *" => Process::result(output: "gateway-id\n"),
        "docker run -d --restart unless-stopped --name 'orbit-e2e-run123-gateway-orbit-runtime' *" => Process::result(output: "runtime-id\n"),
        'docker exec *' => Process::result(),
    ]);

    $previous = getenv('TEST_TOKEN');
    putenv('TEST_TOKEN=2');

    try {
        $networkPlan = DockerTopologyNetworkPlan::fromEnvironment('run123');
        $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());
        $lease = $provider->acquire(E2ETopologyKind::ControlGateway, 'run123', new E2EPhaseTimer, new E2ETopologyAcquisitionOptions);

        expect($lease->control()->name())->toBe('orbit-e2e-run123-operator')
            ->and($lease->gatewayApiIp())->toBe($networkPlan->ipForRole('gateway'));

        Process::assertRan("docker network create --subnet '{$networkPlan->subnet()}' 'orbit-e2e-run123'");

        $lease->cleanup();
    } finally {
        if ($previous === false) {
            putenv('TEST_TOKEN');
        } else {
            putenv("TEST_TOKEN={$previous}");
        }
    }
});

it('leases docker host slots independently from the parallel worker token', function (): void {
    $networkHost = null;
    $leaseDirectory = storage_path('framework/e2e/test-leases-'.bin2hex(random_bytes(4)));

    exec('rm -rf '.escapeshellarg($leaseDirectory));

    Process::fake(function ($process) use (&$networkHost) {
        $host = $process->environment['DOCKER_HOST'] ?? 'local';

        if ($process->command === 'command -v docker >/dev/null') {
            return Process::result();
        }

        if ($process->command === 'docker info >/dev/null') {
            return Process::result();
        }

        if (str_contains($process->command, 'docker image inspect')) {
            return Process::result();
        }

        if ($process->command === "docker ps --format '{{.Names}}' --filter 'name=orbit-e2e-'") {
            return Process::result();
        }

        if (str_starts_with($process->command, "docker network create --subnet '10.90.")
            && str_contains($process->command, ".0/24'")) {
            $networkHost = $host;

            return Process::result();
        }

        if (str_starts_with($process->command, 'docker run -d ')) {
            return Process::result(output: "container-id\n");
        }

        if (str_starts_with($process->command, 'docker exec ')) {
            return Process::result();
        }

        return Process::result(exitCode: 1, errorOutput: $process->command);
    });

    $previous = getenv('TEST_TOKEN');
    putenv('TEST_TOKEN=5');

    try {
        withE2EConfigEnvironment([
            'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'sidecar1:2:64,sidecar2:2:64,beast:3:64',
            'ORBIT_E2E_LEASE_DIRECTORY' => $leaseDirectory,
        ], function () use (&$networkHost): void {
            $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

            $provider->acquire(E2ETopologyKind::ControlGateway, 'run123', new E2EPhaseTimer, new E2ETopologyAcquisitionOptions);

            expect($networkHost)->toBe('ssh://sidecar1');
        });
    } finally {
        exec('rm -rf '.escapeshellarg($leaseDirectory));

        if ($previous === false) {
            putenv('TEST_TOKEN');
        } else {
            putenv("TEST_TOKEN={$previous}");
        }
    }
});

it('releases docker host slots during topology cleanup', function (): void {
    $leaseDirectory = storage_path('framework/e2e/test-leases-'.bin2hex(random_bytes(4)));

    exec('rm -rf '.escapeshellarg($leaseDirectory));

    Process::fake([
        '*command -v docker*' => Process::result(),
        '*docker info*' => Process::result(),
        '*docker image inspect*' => Process::result(),
        '*docker ps*' => Process::result(),
        '*docker network create*' => Process::result(),
        '*docker run -d*' => Process::result(output: "container-id\n"),
        '*docker exec*' => Process::result(),
        '*docker rm -f*' => Process::result(),
        '*docker network rm*' => Process::result(),
    ]);

    try {
        withE2EConfigEnvironment([
            'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'sidecar1:1:64',
            'ORBIT_E2E_LEASE_DIRECTORY' => $leaseDirectory,
            'ORBIT_E2E_SLOT_WAIT_SECONDS' => '0',
        ], function () use ($leaseDirectory): void {
            $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());
            $lease = $provider->acquire(E2ETopologyKind::ControlGateway, 'run123', new E2EPhaseTimer, new E2ETopologyAcquisitionOptions);
            $pool = new E2EResourceLeasePool($leaseDirectory, waitSeconds: 0, staleSeconds: 60);

            expect($pool->snapshot('docker', ['sidecar1' => 1]))->toMatchArray([
                ['host' => 'sidecar1', 'slot' => 1, 'leased' => true],
            ]);

            $lease->cleanup();

            expect($pool->snapshot('docker', ['sidecar1' => 1]))->toMatchArray([
                ['host' => 'sidecar1', 'slot' => 1, 'leased' => false],
            ]);
        });
    } finally {
        exec('rm -rf '.escapeshellarg($leaseDirectory));
    }
});

it('keeps the docker network until final cleanup across fresh resets', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = $process->command;

        return Process::result(output: str_starts_with($process->command, 'docker run -d ') ? "container-id\n" : '');
    });

    $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());
    $lease = $provider->acquire(E2ETopologyKind::Control, 'run123', new E2EPhaseTimer, new E2ETopologyAcquisitionOptions);

    $lease->reset();

    expect(collect($commands)->filter(fn (string $command): bool => str_starts_with($command, 'docker network create '))->count())->toBe(1)
        ->and(collect($commands)->contains("docker network rm 'orbit-e2e-run123' >/dev/null 2>&1 || true"))->toBeFalse();

    $lease->cleanup();

    expect(collect($commands)->filter(fn (string $command): bool => $command === "docker network rm 'orbit-e2e-run123' >/dev/null 2>&1 || true")->count())->toBe(1);
});

it('cleans containers and network when docker acquire fails partway through', function (): void {
    Process::fake(function ($process) {
        $command = $process->command;

        if (
            $command === 'command -v docker >/dev/null'
            || $command === 'docker info >/dev/null'
            || str_starts_with($command, 'docker image inspect ')
            || $command === "docker ps --format '{{.Names}}' --filter 'name=orbit-e2e-'"
            || (str_starts_with($command, "docker network create --subnet '10.") && str_ends_with($command, "'orbit-e2e-run123'"))
            || $command === "docker rm -f 'orbit-e2e-run123-operator-orbit-caddy' 'orbit-e2e-run123-operator' 'orbit-e2e-run123-gateway-orbit-runtime' 'orbit-e2e-run123-gateway-orbit-caddy' 'orbit-e2e-run123-gateway' >/dev/null 2>&1 || true"
            || $command === "docker volume rm -f 'orbit-e2e-run123-operator-home-orbit' 'orbit-e2e-run123-gateway-home-orbit' >/dev/null 2>&1 || true"
            || $command === "docker network rm 'orbit-e2e-run123' >/dev/null 2>&1 || true"
        ) {
            return Process::result();
        }

        if (str_starts_with($command, "docker run -d --name 'orbit-e2e-run123-operator' ")) {
            return Process::result(output: "control-id\n");
        }

        if (str_starts_with($command, "docker run -d --name 'orbit-e2e-run123-gateway' ")) {
            return Process::result(exitCode: 1, errorOutput: "failed\n");
        }

        return Process::result(exitCode: 1, errorOutput: $command);
    });

    $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

    expect(fn () => $provider->acquire(E2ETopologyKind::ControlGateway, 'run123', new E2EPhaseTimer, new E2ETopologyAcquisitionOptions))
        ->toThrow(RuntimeException::class, 'Could not start container');

    Process::assertRan("docker rm -f 'orbit-e2e-run123-operator-orbit-caddy' 'orbit-e2e-run123-operator' 'orbit-e2e-run123-gateway-orbit-runtime' 'orbit-e2e-run123-gateway-orbit-caddy' 'orbit-e2e-run123-gateway' >/dev/null 2>&1 || true");
    Process::assertRan("docker network rm 'orbit-e2e-run123' >/dev/null 2>&1 || true");
});

it('starts docker containers as a batch and rolls back when one start fails', function (): void {
    withE2EEnvironment(['ORBIT_E2E_DOCKER_PARALLEL_STARTS', 'TEST_TOKEN'], [
        'ORBIT_E2E_DOCKER_PARALLEL_STARTS' => '1',
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'local:8:64',
    ], function (): void {
        Process::fake([
            'command -v docker >/dev/null' => Process::result(),
            'docker info >/dev/null' => Process::result(),
            "docker image inspect 'orbit-runtime:prepared-current' >/dev/null" => Process::result(),
            "docker image inspect 'orbit-e2e-topology:prepared-operator_gateway_app-dev_app-prod_agent-operator-dns-alias-current' >/dev/null" => Process::result(),
            "docker image inspect 'orbit-e2e-topology:prepared-operator_gateway_app-dev_app-prod_agent-gateway-dns-alias-current' >/dev/null" => Process::result(),
            "docker ps --format '{{.Names}}' --filter 'name=orbit-e2e-'" => Process::result(),
            "docker network create --subnet * 'orbit-e2e-run123'" => Process::result(),
            "docker run -d --name 'orbit-e2e-run123-operator' *" => Process::result(exitCode: 1, errorOutput: "control failed\n"),
            "docker run -d --name 'orbit-e2e-run123-gateway' *" => Process::result(output: "gateway-id\n"),
            "docker rm -f 'orbit-e2e-run123-operator-orbit-caddy' 'orbit-e2e-run123-operator' 'orbit-e2e-run123-gateway-orbit-runtime' 'orbit-e2e-run123-gateway-orbit-caddy' 'orbit-e2e-run123-gateway' >/dev/null 2>&1 || true" => Process::result(),
            "docker volume rm -f 'orbit-e2e-run123-operator-home-orbit' 'orbit-e2e-run123-gateway-home-orbit' >/dev/null 2>&1 || true" => Process::result(),
            "docker network rm 'orbit-e2e-run123' >/dev/null 2>&1 || true" => Process::result(),
        ]);

        $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

        expect(fn () => $provider->acquire(E2ETopologyKind::ControlGateway, 'run123', new E2EPhaseTimer, new E2ETopologyAcquisitionOptions))
            ->toThrow(RuntimeException::class, 'Could not start container orbit-e2e-run123-operator');

        Process::assertRan(fn ($process): bool => is_string($process->command)
            && str_contains($process->command, "docker run -d --name 'orbit-e2e-run123-gateway'")
            && str_contains($process->command, '--group-add "$(stat -c %g /var/run/docker.sock 2>/dev/null || stat -f %g /var/run/docker.sock)"')
            && str_contains($process->command, "--volume '/var/run/docker.sock:/var/run/docker.sock'")
            && str_contains($process->command, "--env 'ORBIT_RUNTIME_CONTAINER=orbit-e2e-run123-gateway-orbit-runtime'")
            && str_contains($process->command, "'orbit-e2e-topology:prepared-operator_gateway_app-dev_app-prod_agent-gateway-dns-alias-current'"));
        Process::assertRan("docker rm -f 'orbit-e2e-run123-operator-orbit-caddy' 'orbit-e2e-run123-operator' 'orbit-e2e-run123-gateway-orbit-runtime' 'orbit-e2e-run123-gateway-orbit-caddy' 'orbit-e2e-run123-gateway' >/dev/null 2>&1 || true");
    });
});

it('starts docker containers sequentially by default to avoid ssh startup bursts', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = $process->command;

        if (str_contains($process->command, "docker run -d --name 'orbit-e2e-run123-operator'")) {
            return Process::result(exitCode: 1, errorOutput: "control failed\n");
        }

        return Process::result(output: str_starts_with($process->command, 'docker run -d ') ? "container-id\n" : '');
    });

    $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

    expect(fn () => $provider->acquire(E2ETopologyKind::ControlGateway, 'run123', new E2EPhaseTimer, new E2ETopologyAcquisitionOptions))
        ->toThrow(RuntimeException::class, 'Could not start container orbit-e2e-run123-operator');

    expect(implode("\n", $commands))->not->toContain("docker run -d --name 'orbit-e2e-run123-gateway'");
});

it('uses dns aliases and primes the gateway api in Docker topology runs', function (): void {
    Process::fake([
        'command -v docker >/dev/null' => Process::result(),
        'docker info >/dev/null' => Process::result(),
        "docker image inspect 'orbit-runtime:prepared-current' >/dev/null" => Process::result(),
        "docker image inspect 'orbit-e2e-topology:prepared-operator_gateway_app-dev_app-prod_agent-operator-dns-alias-current' >/dev/null" => Process::result(),
        "docker image inspect 'orbit-e2e-topology:prepared-operator_gateway_app-dev_app-prod_agent-gateway-dns-alias-current' >/dev/null" => Process::result(),
        "docker ps --format '{{.Names}}' --filter 'name=orbit-e2e-'" => Process::result(),
        "docker network create --subnet * 'orbit-e2e-run123'" => Process::result(),
        "docker run -d --name 'orbit-e2e-run123-operator' *" => Process::result(output: "control-id\n"),
        "docker run -d --name 'orbit-e2e-run123-gateway' *" => Process::result(output: "gateway-id\n"),
        "docker run -d --restart unless-stopped --name 'orbit-e2e-run123-gateway-orbit-runtime' *" => Process::result(output: "runtime-id\n"),
        'docker exec *' => Process::result(),
    ]);

    withE2EEnvironment(['TEST_TOKEN'], [
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'local:8:64',
    ], function (): void {
        $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

        $lease = $provider->acquire(
            E2ETopologyKind::ControlGateway,
            'run123',
            new E2EPhaseTimer,
            new E2ETopologyAcquisitionOptions(startGatewayApi: true),
        );

        expect($lease->gateway()?->name())->toBe('orbit-e2e-run123-gateway');

        $lease->cleanup();
    });

    Process::assertNotRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, 'bootstrap-gateway-local'));
    Process::assertRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, 'issueLeaf')
        && str_contains($process->command, 'gateway')
        && str_contains($process->command, '10.6.0.2'));
});

it('maps parallel docker subnet peer ips back to canonical dns-alias identities', function (): void {
    $commands = [];
    $networkPlan = null;

    Process::fake(function ($process) use (&$commands) {
        $commands[] = $process->command;

        return Process::result();
    });

    withE2EEnvironment(['TEST_TOKEN'], [
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'local:8:64',
        'TEST_TOKEN' => '1',
    ], function () use (&$networkPlan): void {
        $networkPlan = DockerTopologyNetworkPlan::fromEnvironment('run123');
        $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

        $lease = $provider->acquire(
            E2ETopologyKind::ControlGateway,
            'run123',
            new E2EPhaseTimer,
            new E2ETopologyAcquisitionOptions(startGatewayApi: true),
        );

        $lease->cleanup();
    });

    expect($networkPlan)->toBeInstanceOf(DockerTopologyNetworkPlan::class)
        ->and($commands)->toContain("docker network create --subnet '{$networkPlan->subnet()}' 'orbit-e2e-run123'");
    expect(implode("\n", $commands))
        ->toContain('sudo docker exec --detach')
        ->toContain('orbit tinker --execute=')
        ->toContain('$peerIdentityMap = array')
        ->toContain($networkPlan->ipForRole('operator'))
        ->toContain('10.6.0.3')
        ->toContain($networkPlan->ipForRole('gateway'))
        ->toContain('10.6.0.2');
});
