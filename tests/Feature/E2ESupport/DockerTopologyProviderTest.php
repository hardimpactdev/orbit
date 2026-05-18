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
        "docker image inspect 'orbit-e2e-topology:operator-control-current' >/dev/null" => Process::result(exitCode: 1),
        "docker image inspect 'orbit-e2e-topology:control-control-current' >/dev/null" => Process::result(exitCode: 1),
    ]);

    $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

    expect($provider->availability(E2ETopologyKind::Control)->available)->toBeFalse()
        ->and($provider->availability(E2ETopologyKind::Control)->message)->toContain('orbit-e2e-topology:operator-control-current');
});

it('advertises container feature capabilities', function (): void {
    $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

    expect($provider->capabilities())->toEqual(E2ETopologyCapabilities::containerFeature());
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

it('acquires a control-gateway lease by launching containers from prepared images', function (): void {
    Process::fake([
        'command -v docker >/dev/null' => Process::result(),
        'docker info >/dev/null' => Process::result(),
        "docker image inspect 'orbit-e2e-topology:control-gateway-control-current' >/dev/null" => Process::result(),
        "docker image inspect 'orbit-e2e-topology:control-gateway-gateway-current' >/dev/null" => Process::result(),
        "docker ps --format '{{.Names}}' --filter 'name=orbit-e2e-'" => Process::result(),
        "docker network create --subnet '10.6.0.0/16' 'orbit-e2e-run123'" => Process::result(),
        "docker run -d --name 'orbit-e2e-run123-control' --network 'orbit-e2e-run123' --ip '10.6.0.3' --cap-add NET_ADMIN --cap-add NET_BIND_SERVICE 'orbit-e2e-topology:control-gateway-control-current'" => Process::result(output: "control-id\n"),
        "docker run -d --name 'orbit-e2e-run123-gateway' --network 'orbit-e2e-run123' --ip '10.6.0.2' --cap-add NET_ADMIN --cap-add NET_BIND_SERVICE 'orbit-e2e-topology:control-gateway-gateway-current'" => Process::result(output: "gateway-id\n"),
        'docker exec *' => Process::result(),
    ]);

    $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

    $lease = $provider->acquire(E2ETopologyKind::ControlGateway, 'run123', new E2EPhaseTimer, new E2ETopologyAcquisitionOptions);

    expect($lease->control()->name())->toBe('orbit-e2e-run123-control')
        ->and($lease->gateway()?->name())->toBe('orbit-e2e-run123-gateway');
});

it('uses the parallel worker token to create a non-overlapping docker network', function (): void {
    Process::fake([
        'command -v docker >/dev/null' => Process::result(),
        'docker info >/dev/null' => Process::result(),
        "docker image inspect 'orbit-e2e-topology:control-gateway-control-current' >/dev/null" => Process::result(),
        "docker image inspect 'orbit-e2e-topology:control-gateway-gateway-current' >/dev/null" => Process::result(),
        "docker ps --format '{{.Names}}' --filter 'name=orbit-e2e-'" => Process::result(),
        "docker network create --subnet '10.62.0.0/16' 'orbit-e2e-run123'" => Process::result(),
        "docker run -d --name 'orbit-e2e-run123-control' --network 'orbit-e2e-run123' --ip '10.62.0.3' --cap-add NET_ADMIN --cap-add NET_BIND_SERVICE 'orbit-e2e-topology:control-gateway-control-current'" => Process::result(output: "control-id\n"),
        "docker run -d --name 'orbit-e2e-run123-gateway' --network 'orbit-e2e-run123' --ip '10.62.0.2' --cap-add NET_ADMIN --cap-add NET_BIND_SERVICE 'orbit-e2e-topology:control-gateway-gateway-current'" => Process::result(output: "gateway-id\n"),
        'docker exec *' => Process::result(),
    ]);

    $previous = getenv('TEST_TOKEN');
    putenv('TEST_TOKEN=2');

    try {
        $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());
        $lease = $provider->acquire(E2ETopologyKind::ControlGateway, 'run123', new E2EPhaseTimer, new E2ETopologyAcquisitionOptions);

        expect($lease->control()->name())->toBe('orbit-e2e-run123-control')
            ->and($lease->gatewayApiIp())->toBe('10.62.0.2');

        Process::assertRan("docker network create --subnet '10.62.0.0/16' 'orbit-e2e-run123'");
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

        if (str_starts_with($process->command, "docker network create --subnet '10.65.0.0/16'")) {
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

it('cleans containers and network when docker acquire fails partway through', function (): void {
    Process::fake([
        'command -v docker >/dev/null' => Process::result(),
        'docker info >/dev/null' => Process::result(),
        "docker image inspect 'orbit-e2e-topology:control-gateway-control-current' >/dev/null" => Process::result(),
        "docker image inspect 'orbit-e2e-topology:control-gateway-gateway-current' >/dev/null" => Process::result(),
        "docker ps --format '{{.Names}}' --filter 'name=orbit-e2e-'" => Process::result(),
        "docker network create --subnet '10.6.0.0/16' 'orbit-e2e-run123'" => Process::result(),
        "docker run -d --name 'orbit-e2e-run123-control' *" => Process::result(output: "control-id\n"),
        "docker run -d --name 'orbit-e2e-run123-gateway' *" => Process::result(exitCode: 1, errorOutput: "failed\n"),
        "docker rm -f 'orbit-e2e-run123-control' >/dev/null 2>&1 || true" => Process::result(),
        "docker rm -f 'orbit-e2e-run123-gateway' >/dev/null 2>&1 || true" => Process::result(),
        "docker network rm 'orbit-e2e-run123' >/dev/null 2>&1 || true" => Process::result(),
    ]);

    $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

    expect(fn () => $provider->acquire(E2ETopologyKind::ControlGateway, 'run123', new E2EPhaseTimer, new E2ETopologyAcquisitionOptions))
        ->toThrow(RuntimeException::class, 'Could not start container');

    Process::assertRan("docker rm -f 'orbit-e2e-run123-control' >/dev/null 2>&1 || true");
    Process::assertRan("docker rm -f 'orbit-e2e-run123-gateway' >/dev/null 2>&1 || true");
    Process::assertRan("docker network rm 'orbit-e2e-run123' >/dev/null 2>&1 || true");
});
