<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;
use Tests\E2E\Support\DockerHost;
use Tests\E2E\Support\DockerInstance;
use Tests\E2E\Support\DockerTopologyProvider;
use Tests\E2E\Support\E2EConfig;
use Tests\E2E\Support\E2EPhaseTimer;
use Tests\E2E\Support\E2ETopologyAcquisitionOptions;
use Tests\E2E\Support\E2ETopologyCapabilities;
use Tests\E2E\Support\E2ETopologyKind;
use Tests\E2E\Support\SshKeyPair;

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
        "docker image inspect 'orbit-e2e-topology:control-control-current' >/dev/null" => Process::result(exitCode: 1),
    ]);

    $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

    expect($provider->availability(E2ETopologyKind::Control)->available)->toBeFalse()
        ->and($provider->availability(E2ETopologyKind::Control)->message)->toContain('orbit-e2e-topology:control-control-current');
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

it('assigns parallel docker workers to configured host slots', function (): void {
    $networkHost = null;

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
        ], function () use (&$networkHost): void {
            $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

            $provider->acquire(E2ETopologyKind::ControlGateway, 'run123', new E2EPhaseTimer, new E2ETopologyAcquisitionOptions);

            expect($networkHost)->toBe('ssh://beast');
        });
    } finally {
        if ($previous === false) {
            putenv('TEST_TOKEN');
        } else {
            putenv("TEST_TOKEN={$previous}");
        }
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
