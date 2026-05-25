<?php

declare(strict_types=1);

use App\E2E\Support\DockerHost;
use App\E2E\Support\DockerInstance;
use App\E2E\Support\DockerTopologyProvider;
use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EPhaseTimer;
use App\E2E\Support\E2EResourceLeasePool;
use App\E2E\Support\E2ETopologyAcquisitionOptions;
use App\E2E\Support\E2ETopologyCapabilities;
use App\E2E\Support\E2ETopologyKind;
use App\E2E\Support\SshKeyPair;
use Illuminate\Support\Facades\Process;

it('runs docker exec for instance commands', function (): void {
    Process::fake([
        "docker exec 'orbit-e2e-run-control' sh -lc *" => Process::result(output: "ok\n"),
    ]);

    $instance = new DockerInstance(new DockerHost(E2EConfig::fromEnvironment()), 'orbit-e2e-run-control');

    $result = $instance->exec('echo ok');

    expect($result->successful())->toBeTrue()
        ->and($result->output())->toBe("ok\n");
});

it('maps ssh transport to user-scoped docker exec for container feature topologies', function (): void {
    Process::fake([
        "docker exec --user 'control' 'orbit-e2e-run-control' sh -lc *" => Process::result(output: "control\n"),
    ]);

    $instance = new DockerInstance(new DockerHost(E2EConfig::fromEnvironment()), 'orbit-e2e-run-control');

    $result = $instance->ssh('control', new SshKeyPair('/tmp/fake', '/tmp/fake.pub'), 'whoami');

    expect($result->successful())->toBeTrue()
        ->and($result->output())->toBe("control\n");
});

it('reads ipv4 from the named docker network only', function (): void {
    Process::fake([
        "docker inspect -f '{{(index .NetworkSettings.Networks \"orbit-e2e-run\").IPAddress}}' 'orbit-e2e-run-control'" => Process::result(output: "10.6.0.3\n"),
    ]);

    $instance = new DockerInstance(new DockerHost(E2EConfig::fromEnvironment()), 'orbit-e2e-run-control', 'orbit-e2e-run');

    expect($instance->waitForIpv4())->toBe('10.6.0.3');
});

it('starts Docker feature topology nodes with the host Docker socket and runtime container context', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = $process->command;

        return Process::result(output: str_starts_with($process->command, 'docker run -d ') ? "container-id\n" : '');
    });

    $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

    $provider->acquire(E2ETopologyKind::Control, 'run123', new E2EPhaseTimer, new E2ETopologyAcquisitionOptions);

    $setup = implode("\n", $commands);

    expect($setup)
        ->toContain("--volume '/var/run/docker.sock:/var/run/docker.sock'")
        ->toContain("--mount 'type=volume,src=orbit-e2e-run123-control-home-control,dst=/home/control'")
        ->toContain("--mount 'type=volume,src=orbit-e2e-run123-control-home-orbit,dst=/home/orbit'")
        ->toContain("--env 'ORBIT_E2E_DOCKER_NETWORK=orbit-e2e-run123'")
        ->toContain("--env 'ORBIT_RUNTIME_CONTAINER=orbit-e2e-run123-control-orbit-runtime'")
        ->toContain("docker run -d --restart unless-stopped --name 'orbit-e2e-run123-control-orbit-runtime'")
        ->toContain("--network 'container:orbit-e2e-run123-control'")
        ->toContain("--env 'ORBIT_SOURCE_PATH=/home/control/orbit'");
});

it('does not use host PHP or host Caddy paths while starting Docker gateway API support', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = $process->command;

        return Process::result(output: str_starts_with($process->command, 'docker run -d ') ? "container-id\n" : '');
    });

    $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

    $provider->acquire(
        E2ETopologyKind::ControlGateway,
        'run123',
        new E2EPhaseTimer,
        new E2ETopologyAcquisitionOptions(startGatewayApi: true),
    );

    $setup = implode("\n", $commands);
    $gatewayRuntimeStart = strpos($setup, "docker run -d --restart unless-stopped --name 'orbit-e2e-run123-gateway-orbit-runtime'");
    $gatewayRetarget = strpos($setup, "docker exec --user 'orbit' 'orbit-e2e-run123-gateway' sh -lc 'cd /home/orbit/orbit && if orbit orbit:internal:bootstrap-gateway-local");

    expect($gatewayRuntimeStart)->toBeInt()
        ->and($gatewayRetarget)->toBeInt()
        ->and($gatewayRuntimeStart)->toBeLessThan($gatewayRetarget);

    expect($setup)
        ->toContain('orbit tinker --execute=')
        ->toContain('php -d display_errors=0 -S')
        ->not->toContain('orbit serve --host=')
        ->not->toContain('php artisan')
        ->not->toContain('nohup php')
        ->not->toContain('php -r')
        ->not->toContain('systemctl stop caddy');
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
        "docker image inspect 'orbit-runtime:current' >/dev/null" => Process::result(),
        "docker image inspect 'orbit-e2e-topology:operator-control-current' >/dev/null" => Process::result(exitCode: 1),
        "docker image inspect 'orbit-e2e-topology:control-control-current' >/dev/null" => Process::result(exitCode: 1),
    ]);

    $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

    expect($provider->availability(E2ETopologyKind::Control)->available)->toBeFalse()
        ->and($provider->availability(E2ETopologyKind::Control)->message)->toContain('orbit-e2e-topology:operator-control-current');
});

it('reports docker unavailable when the orbit runtime sibling image is missing', function (): void {
    Process::fake(function ($process) {
        if ($process->command === 'command -v docker >/dev/null' || $process->command === 'docker info >/dev/null') {
            return Process::result();
        }

        if ($process->command === "docker image inspect 'orbit-runtime:current' >/dev/null") {
            return Process::result(exitCode: 1);
        }

        if (str_starts_with($process->command, 'docker image inspect ')) {
            return Process::result();
        }

        return Process::result();
    });

    $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

    expect($provider->availability(E2ETopologyKind::Control)->available)->toBeFalse()
        ->and($provider->availability(E2ETopologyKind::Control)->message)->toContain('orbit-runtime:current');
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

it('selects the first docker host with image availability and capacity', function (): void {
    Process::fake(function ($process) {
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
            return $host === 'ssh://beast'
                ? Process::result(output: "orbit-e2e-a\norbit-e2e-b\n")
                : Process::result(output: '');
        }

        return Process::result();
    });

    withE2EConfigEnvironment([
        'ORBIT_E2E_DOCKER_HOSTS' => 'beast,local',
        'ORBIT_E2E_DOCKER_MAX_CONTAINERS_PER_HOST' => '2',
    ], function (): void {
        $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

        $availability = $provider->availability(E2ETopologyKind::Control);

        expect($availability->available)->toBeTrue()
            ->and($availability->message)->toContain('local');
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
        'ORBIT_E2E_DOCKER_HOSTS' => 'beast',
        'ORBIT_E2E_TIMEOUT_SECONDS' => '600',
    ], function () use (&$probeTimeouts): void {
        $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

        expect($provider->availability(E2ETopologyKind::Control)->available)->toBeTrue()
            ->and($probeTimeouts['docker info >/dev/null'])->toBe(120)
            ->and($probeTimeouts["docker image inspect 'orbit-e2e-topology:operator-control-current' >/dev/null"])->toBe(120)
            ->and($probeTimeouts["docker ps --format '{{.Names}}' --filter 'name=orbit-e2e-'"])->toBe(120);
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
        'ORBIT_E2E_DOCKER_MAX_CONTAINERS_PER_HOST' => '2',
    ], function (): void {
        $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

        $availability = $provider->availability(E2ETopologyKind::Control);

        expect($availability->available)->toBeFalse()
            ->and($availability->message)->toContain('docker capacity exceeded');
    });
});

it('accounts for runtime sibling containers when checking docker capacity', function (): void {
    Process::fake(function ($process) {
        if ($process->command === 'command -v docker >/dev/null' || $process->command === 'docker info >/dev/null') {
            return Process::result();
        }

        if (str_starts_with($process->command, 'docker image inspect ')) {
            return Process::result();
        }

        if ($process->command === "docker ps --format '{{.Names}}' --filter 'name=orbit-e2e-'") {
            return Process::result(output: "orbit-e2e-running-control\n");
        }

        return Process::result(exitCode: 1, errorOutput: $process->command);
    });

    withE2EConfigEnvironment([
        'ORBIT_E2E_DOCKER_MAX_CONTAINERS_PER_HOST' => '4',
    ], function (): void {
        $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

        $availability = $provider->availability(E2ETopologyKind::ControlGateway);

        expect($availability->available)->toBeFalse()
            ->and($availability->message)->toContain('docker capacity exceeded');
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
        'ORBIT_E2E_DOCKER_HOST_SLOTS' => 'sidecar1:1',
        'ORBIT_E2E_DOCKER_MAX_CONTAINERS_PER_HOST' => '1',
    ], function () use (&$probedCapacity): void {
        $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());
        $availability = $provider->availability(E2ETopologyKind::ControlGateway);

        expect($availability->available)->toBeTrue()
            ->and($availability->message)->toContain('sidecar1')
            ->and($probedCapacity)->toBeFalse();
    });
});

it('acquires a control-gateway lease by launching containers from prepared images', function (): void {
    Process::fake(function ($process) {
        $command = $process->command;

        if (
            $command === 'command -v docker >/dev/null'
            || $command === 'docker info >/dev/null'
            || str_starts_with($command, 'docker image inspect ')
            || $command === "docker ps --format '{{.Names}}' --filter 'name=orbit-e2e-'"
            || (str_starts_with($command, "docker network create --subnet '10.") && str_ends_with($command, "'orbit-e2e-run123'"))
            || str_starts_with($command, "docker run -d --name 'orbit-e2e-run123-control' ")
            || str_starts_with($command, "docker run -d --restart unless-stopped --name 'orbit-e2e-run123-control-orbit-runtime' ")
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

    expect($lease->control()->name())->toBe('orbit-e2e-run123-control')
        ->and($lease->gateway()?->name())->toBe('orbit-e2e-run123-gateway');
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

    $provider->acquire(E2ETopologyKind::ControlGateway, 'run123', new E2EPhaseTimer, new E2ETopologyAcquisitionOptions);

    expect($imageInspectCounts["docker image inspect 'orbit-e2e-topology:operator_gateway-control-current' >/dev/null"])->toBe(1)
        ->and($imageInspectCounts["docker image inspect 'orbit-e2e-topology:operator_gateway-gateway-current' >/dev/null"])->toBe(1);
});

it('uses the parallel worker token to create a non-overlapping docker network', function (): void {
    Process::fake([
        'command -v docker >/dev/null' => Process::result(),
        'docker info >/dev/null' => Process::result(),
        "docker image inspect 'orbit-runtime:current' >/dev/null" => Process::result(),
        "docker image inspect 'orbit-e2e-topology:operator_gateway-control-current' >/dev/null" => Process::result(),
        "docker image inspect 'orbit-e2e-topology:operator_gateway-gateway-current' >/dev/null" => Process::result(),
        "docker ps --format '{{.Names}}' --filter 'name=orbit-e2e-'" => Process::result(),
        "docker network create --subnet '10.42.0.0/16' 'orbit-e2e-run123'" => Process::result(),
        "docker run -d --name 'orbit-e2e-run123-control' *" => Process::result(output: "control-id\n"),
        "docker run -d --restart unless-stopped --name 'orbit-e2e-run123-control-orbit-runtime' *" => Process::result(output: "runtime-id\n"),
        "docker run -d --name 'orbit-e2e-run123-gateway' *" => Process::result(output: "gateway-id\n"),
        "docker run -d --restart unless-stopped --name 'orbit-e2e-run123-gateway-orbit-runtime' *" => Process::result(output: "runtime-id\n"),
        'docker exec *' => Process::result(),
    ]);

    $previous = getenv('TEST_TOKEN');
    putenv('TEST_TOKEN=2');

    try {
        $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());
        $lease = $provider->acquire(E2ETopologyKind::ControlGateway, 'run123', new E2EPhaseTimer, new E2ETopologyAcquisitionOptions);

        expect($lease->control()->name())->toBe('orbit-e2e-run123-control')
            ->and($lease->gatewayApiIp())->toBe('10.42.0.2');

        Process::assertRan("docker network create --subnet '10.42.0.0/16' 'orbit-e2e-run123'");
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

        if (str_starts_with($process->command, "docker network create --subnet '10.90.0.0/16'")) {
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
            'ORBIT_E2E_DOCKER_HOST_SLOTS' => 'sidecar1:2,sidecar2:2,beast:3',
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
            'ORBIT_E2E_DOCKER_HOST_SLOTS' => 'sidecar1:1',
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
            || $command === "docker rm -f 'orbit-e2e-run123-control' 'orbit-e2e-run123-control-orbit-runtime' 'orbit-e2e-run123-control-orbit-caddy' 'orbit-e2e-run123-gateway' 'orbit-e2e-run123-gateway-orbit-runtime' 'orbit-e2e-run123-gateway-orbit-caddy' >/dev/null 2>&1 || true"
            || $command === "docker volume rm -f 'orbit-e2e-run123-control-home-control' 'orbit-e2e-run123-control-home-orbit' 'orbit-e2e-run123-gateway-home-control' 'orbit-e2e-run123-gateway-home-orbit' >/dev/null 2>&1 || true"
            || $command === "docker network rm 'orbit-e2e-run123' >/dev/null 2>&1 || true"
        ) {
            return Process::result();
        }

        if (str_starts_with($command, "docker run -d --name 'orbit-e2e-run123-control' ")) {
            return Process::result(output: "control-id\n");
        }

        if (str_starts_with($command, "docker run -d --restart unless-stopped --name 'orbit-e2e-run123-control-orbit-runtime' ")) {
            return Process::result(output: "runtime-id\n");
        }

        if (str_starts_with($command, "docker run -d --name 'orbit-e2e-run123-gateway' ")) {
            return Process::result(exitCode: 1, errorOutput: "failed\n");
        }

        return Process::result(exitCode: 1, errorOutput: $command);
    });

    $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

    expect(fn () => $provider->acquire(E2ETopologyKind::ControlGateway, 'run123', new E2EPhaseTimer, new E2ETopologyAcquisitionOptions))
        ->toThrow(RuntimeException::class, 'Could not start container');

    Process::assertRan("docker rm -f 'orbit-e2e-run123-control' 'orbit-e2e-run123-control-orbit-runtime' 'orbit-e2e-run123-control-orbit-caddy' 'orbit-e2e-run123-gateway' 'orbit-e2e-run123-gateway-orbit-runtime' 'orbit-e2e-run123-gateway-orbit-caddy' >/dev/null 2>&1 || true");
    Process::assertRan("docker network rm 'orbit-e2e-run123' >/dev/null 2>&1 || true");
});

it('starts docker containers as a batch and rolls back when one start fails', function (): void {
    withE2EEnvironment(['ORBIT_E2E_DOCKER_PARALLEL_STARTS', 'TEST_TOKEN'], [
        'ORBIT_E2E_DOCKER_PARALLEL_STARTS' => '1',
    ], function (): void {
        Process::fake([
            'command -v docker >/dev/null' => Process::result(),
            'docker info >/dev/null' => Process::result(),
            "docker image inspect 'orbit-runtime:current' >/dev/null" => Process::result(),
            "docker image inspect 'orbit-e2e-topology:operator_gateway-control-current' >/dev/null" => Process::result(),
            "docker image inspect 'orbit-e2e-topology:operator_gateway-gateway-current' >/dev/null" => Process::result(),
            "docker ps --format '{{.Names}}' --filter 'name=orbit-e2e-'" => Process::result(),
            "docker network create --subnet '10.6.0.0/16' 'orbit-e2e-run123'" => Process::result(),
            "docker run -d --name 'orbit-e2e-run123-control' *" => Process::result(exitCode: 1, errorOutput: "control failed\n"),
            "docker run -d --name 'orbit-e2e-run123-gateway' *" => Process::result(output: "gateway-id\n"),
            "docker rm -f 'orbit-e2e-run123-control' 'orbit-e2e-run123-control-orbit-runtime' 'orbit-e2e-run123-control-orbit-caddy' 'orbit-e2e-run123-gateway' 'orbit-e2e-run123-gateway-orbit-runtime' 'orbit-e2e-run123-gateway-orbit-caddy' >/dev/null 2>&1 || true" => Process::result(),
            "docker volume rm -f 'orbit-e2e-run123-control-home-control' 'orbit-e2e-run123-control-home-orbit' 'orbit-e2e-run123-gateway-home-control' 'orbit-e2e-run123-gateway-home-orbit' >/dev/null 2>&1 || true" => Process::result(),
            "docker network rm 'orbit-e2e-run123' >/dev/null 2>&1 || true" => Process::result(),
        ]);

        $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

        expect(fn () => $provider->acquire(E2ETopologyKind::ControlGateway, 'run123', new E2EPhaseTimer, new E2ETopologyAcquisitionOptions))
            ->toThrow(RuntimeException::class, 'Could not start container orbit-e2e-run123-control');

        Process::assertRan(fn ($process): bool => is_string($process->command)
            && str_contains($process->command, "docker run -d --name 'orbit-e2e-run123-gateway'")
            && str_contains($process->command, "--volume '/var/run/docker.sock:/var/run/docker.sock'")
            && str_contains($process->command, "--env 'ORBIT_RUNTIME_CONTAINER=orbit-e2e-run123-gateway-orbit-runtime'")
            && str_contains($process->command, "'orbit-e2e-topology:operator_gateway-gateway-current'"));
        Process::assertRan("docker rm -f 'orbit-e2e-run123-control' 'orbit-e2e-run123-control-orbit-runtime' 'orbit-e2e-run123-control-orbit-caddy' 'orbit-e2e-run123-gateway' 'orbit-e2e-run123-gateway-orbit-runtime' 'orbit-e2e-run123-gateway-orbit-caddy' >/dev/null 2>&1 || true");
    });
});

it('starts docker containers sequentially by default to avoid ssh startup bursts', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = $process->command;

        if (str_contains($process->command, "docker run -d --name 'orbit-e2e-run123-control'")) {
            return Process::result(exitCode: 1, errorOutput: "control failed\n");
        }

        return Process::result(output: str_starts_with($process->command, 'docker run -d ') ? "container-id\n" : '');
    });

    $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

    expect(fn () => $provider->acquire(E2ETopologyKind::ControlGateway, 'run123', new E2EPhaseTimer, new E2ETopologyAcquisitionOptions))
        ->toThrow(RuntimeException::class, 'Could not start container orbit-e2e-run123-control');

    expect(implode("\n", $commands))->not->toContain("docker run -d --name 'orbit-e2e-run123-gateway'");
});

it('uses dns aliases and primes the gateway api in dns-alias mode', function (): void {
    Process::fake([
        'command -v docker >/dev/null' => Process::result(),
        'docker info >/dev/null' => Process::result(),
        "docker image inspect 'orbit-runtime:current' >/dev/null" => Process::result(),
        "docker image inspect 'orbit-e2e-topology:operator_gateway-control-dns-alias-current' >/dev/null" => Process::result(),
        "docker image inspect 'orbit-e2e-topology:operator_gateway-gateway-dns-alias-current' >/dev/null" => Process::result(),
        "docker ps --format '{{.Names}}' --filter 'name=orbit-e2e-'" => Process::result(),
        "docker network create --subnet '10.6.0.0/16' 'orbit-e2e-run123'" => Process::result(),
        "docker run -d --name 'orbit-e2e-run123-control' *" => Process::result(output: "control-id\n"),
        "docker run -d --restart unless-stopped --name 'orbit-e2e-run123-control-orbit-runtime' *" => Process::result(output: "runtime-id\n"),
        "docker run -d --name 'orbit-e2e-run123-gateway' *" => Process::result(output: "gateway-id\n"),
        "docker run -d --restart unless-stopped --name 'orbit-e2e-run123-gateway-orbit-runtime' *" => Process::result(output: "runtime-id\n"),
        'docker exec *' => Process::result(),
    ]);

    withE2EEnvironment(['ORBIT_E2E_DOCKER_TOPOLOGY_MODE', 'TEST_TOKEN'], [
        'ORBIT_E2E_DOCKER_TOPOLOGY_MODE' => 'dns-alias',
    ], function (): void {
        $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

        $lease = $provider->acquire(
            E2ETopologyKind::ControlGateway,
            'run123',
            new E2EPhaseTimer,
            new E2ETopologyAcquisitionOptions(startGatewayApi: true),
        );

        expect($lease->gateway()?->name())->toBe('orbit-e2e-run123-gateway');
    });

    Process::assertNotRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, 'bootstrap-gateway-local'));
    Process::assertRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, 'issueLeaf')
        && str_contains($process->command, 'gateway'));
});

it('maps parallel docker subnet peer ips back to canonical dns-alias identities', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = $process->command;

        return Process::result();
    });

    withE2EEnvironment(['ORBIT_E2E_DOCKER_TOPOLOGY_MODE', 'TEST_TOKEN'], [
        'ORBIT_E2E_DOCKER_TOPOLOGY_MODE' => 'dns-alias',
        'TEST_TOKEN' => '1',
    ], function (): void {
        $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

        $provider->acquire(
            E2ETopologyKind::ControlGateway,
            'run123',
            new E2EPhaseTimer,
            new E2ETopologyAcquisitionOptions(startGatewayApi: true),
        );
    });

    expect($commands)->toContain("docker network create --subnet '10.26.0.0/16' 'orbit-e2e-run123'");
    expect(implode("\n", $commands))
        ->toContain('sudo docker exec --detach')
        ->toContain('orbit tinker --execute=')
        ->toContain('$peerIdentityMap = array')
        ->toContain('10.26.0.3')
        ->toContain('10.6.0.3')
        ->toContain('10.26.0.2')
        ->toContain('10.6.0.2');
});
