<?php

declare(strict_types=1);

use App\Console\Commands\E2EPrepareDockerHostsCommand;
use App\Services\E2E\DockerImageDistributor;
use Illuminate\Support\Facades\Process;
use Mockery as m;

beforeEach(function (): void {
    Process::preventStrayProcesses();
});

afterEach(function (): void {
    m::close();
});

it('is hidden', function (): void {
    $command = app(E2EPrepareDockerHostsCommand::class);

    expect($command->isHidden())->toBeTrue();
});

it('documents host preparation without force using docker test runners', function (): void {
    Process::fake();

    withE2EConfigEnvironment([
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'sidecar1:2:28,sidecar2:2:28,beast:3:56',
        'ORBIT_E2E_DOCKER_IMAGE_BUILD_HOSTS' => 'beast',
    ], function (): void {
        $this->artisan('e2e:prepare-docker-hosts', ['kind' => 'control-gateway-dev-prod'])
            ->expectsOutputToContain('Dry run')
            ->expectsOutputToContain('builder: beast')
            ->expectsOutputToContain('planned: sidecar1')
            ->expectsOutputToContain('planned: sidecar2')
            ->expectsOutputToContain('planned: beast')
            ->assertSuccessful();
    });

    Process::assertNothingRan();
});

it('documents ingress host preparation without force', function (): void {
    Process::fake();

    withE2EConfigEnvironment([
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'sidecar1:2:28,sidecar2:2:28',
        'ORBIT_E2E_DOCKER_IMAGE_BUILD_HOSTS' => 'beast',
    ], function (): void {
        $this->artisan('e2e:prepare-docker-hosts', ['kind' => 'operator_gateway_app-prod_ingress'])
            ->expectsOutputToContain('Dry run')
            ->expectsOutputToContain('builder: beast')
            ->expectsOutputToContain('planned: sidecar1')
            ->expectsOutputToContain('planned: sidecar2')
            ->assertSuccessful();
    });

    Process::assertNothingRan();
});

it('builds docker images once on the build host and distributes them to runner hosts', function (): void {
    $runs = [];
    $distributions = [];

    Process::fake(function ($process) use (&$runs) {
        $runs[] = [
            'command' => $process->command,
            'environment' => $process->environment,
        ];

        if (str_starts_with($process->command, 'docker image inspect ')) {
            return Process::result(exitCode: 1);
        }

        return Process::result();
    });

    $distributor = m::mock(DockerImageDistributor::class);
    $distributor->shouldReceive('distribute')
        ->once()
        ->andReturnUsing(function (array $images, array $hosts) use (&$distributions): array {
            $distributions[] = [
                'images' => $images,
                'hosts' => $hosts,
            ];

            return [];
        });

    app()->bind(DockerImageDistributor::class, fn (): DockerImageDistributor => $distributor);

    withE2EConfigEnvironment([
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'sidecar1:2:28,sidecar2:2:28',
        'ORBIT_E2E_DOCKER_IMAGE_BUILD_HOSTS' => 'beast',
        'ORBIT_E2E_DOCKER_COMPOSER_CACHE' => '/home/build/.cache/composer',
        'ORBIT_E2E_DOCKER_COMPOSER_CACHE_READ_ONLY' => '1',
    ], function (): void {
        $this->artisan('e2e:prepare-docker-hosts', [
            'kind' => 'control-gateway-dev-prod',
            '--force' => true,
        ])->assertSuccessful();
    });

    $buildRuns = array_values(array_filter($runs, fn (array $run): bool => str_contains($run['command'], 'composer e2e:prepare-docker-')));

    expect($buildRuns)->toHaveCount(2)
        ->and($buildRuns[0]['environment'])->toMatchArray([
            'DOCKER_HOST' => 'ssh://beast',
            'ORBIT_E2E_DOCKER_COMPOSER_CACHE' => '/home/build/.cache/composer',
            'ORBIT_E2E_DOCKER_COMPOSER_CACHE_READ_ONLY' => '1',
        ])
        ->and($buildRuns[0]['command'])->toContain('composer e2e:prepare-docker-runtime -- --force')
        ->and($buildRuns[1]['environment'])->toMatchArray([
            'DOCKER_HOST' => 'ssh://beast',
            'ORBIT_E2E_DOCKER_COMPOSER_CACHE' => '/home/build/.cache/composer',
            'ORBIT_E2E_DOCKER_COMPOSER_CACHE_READ_ONLY' => '1',
        ])
        ->and($buildRuns[1]['command'])->toContain('composer e2e:prepare-docker-topology -- --force')
        ->and($distributions)->toHaveCount(1)
        ->and($distributions[0]['hosts'])->toBe(['sidecar1', 'sidecar2'])
        ->and($distributions[0]['images'])->toHaveCount(10)
        ->and($distributions[0]['images'][0])->toBe(['role' => 'topology-runtime', 'image' => 'orbit-e2e-topology-runtime:prepared-current'])
        ->and($distributions[0]['images'][1])->toBe(['role' => 'orbit-runtime', 'image' => 'orbit-runtime:prepared-current'])
        ->and($distributions[0]['images'][2])->toBe(['role' => 'orbit-caddy', 'image' => 'caddy:2-alpine'])
        ->and($distributions[0]['images'][3])->toBe(['role' => 'frankenphp-runtime', 'image' => 'dunglas/frankenphp:1-php8.5-bookworm']);
});

it('distributes docker images from the prepared artifact namespace', function (): void {
    $runs = [];
    $distributions = [];

    Process::fake(function ($process) use (&$runs) {
        $runs[] = [
            'command' => $process->command,
            'environment' => $process->environment,
        ];

        if (str_starts_with($process->command, 'docker image inspect ')) {
            return Process::result(exitCode: 1);
        }

        return Process::result();
    });

    $distributor = m::mock(DockerImageDistributor::class);
    $distributor->shouldReceive('distribute')
        ->once()
        ->andReturnUsing(function (array $images, array $hosts) use (&$distributions): array {
            $distributions[] = [
                'images' => $images,
                'hosts' => $hosts,
            ];

            return [];
        });

    app()->bind(DockerImageDistributor::class, fn (): DockerImageDistributor => $distributor);

    withE2EConfigEnvironment([
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'sidecar1:2:28,sidecar2:2:28',
        'ORBIT_E2E_DOCKER_IMAGE_BUILD_HOSTS' => 'beast',
    ], function (): void {
        $this->artisan('e2e:prepare-docker-hosts', [
            'kind' => 'operator_gateway_app-dev_app-prod_agent',
            '--force' => true,
        ])->assertSuccessful();
    });

    $buildRuns = array_values(array_filter($runs, fn (array $run): bool => str_contains($run['command'], 'composer e2e:prepare-docker-')));

    expect($distributions)->toHaveCount(1)
        ->and($buildRuns)->toHaveCount(2)
        ->and($buildRuns[0]['environment'])->toMatchArray([
            'DOCKER_HOST' => 'ssh://beast',
        ])
        ->and($buildRuns[1]['environment'])->toMatchArray([
            'DOCKER_HOST' => 'ssh://beast',
        ])
        ->and($distributions[0]['images'][0])->toBe(['role' => 'topology-runtime', 'image' => 'orbit-e2e-topology-runtime:prepared-current'])
        ->and($distributions[0]['images'][1])->toBe(['role' => 'orbit-runtime', 'image' => 'orbit-runtime:prepared-current'])
        ->and($distributions[0]['images'][2])->toBe(['role' => 'orbit-caddy', 'image' => 'caddy:2-alpine'])
        ->and($distributions[0]['images'][3])->toBe(['role' => 'frankenphp-runtime', 'image' => 'dunglas/frankenphp:1-php8.5-bookworm'])
        ->and($distributions[0]['images'])->toHaveCount(10)
        ->and($distributions[0]['images'][4])->toBe(['role' => 'operator', 'image' => 'orbit-e2e-topology:prepared-operator-operator-dns-alias-current'])
        ->and($distributions[0]['images'][5])->toBe(['role' => 'operator', 'image' => 'orbit-e2e-topology:prepared-operator_gateway_app-dev_app-prod_agent-operator-dns-alias-current'])
        ->and($distributions[0]['images'][6])->toBe(['role' => 'gateway', 'image' => 'orbit-e2e-topology:prepared-operator_gateway_app-dev_app-prod_agent-gateway-dns-alias-current'])
        ->and($distributions[0]['images'][7])->toBe(['role' => 'dev', 'image' => 'orbit-e2e-topology:prepared-operator_gateway_app-dev_app-prod_agent-dev-dns-alias-current'])
        ->and($distributions[0]['images'][8])->toBe(['role' => 'prod', 'image' => 'orbit-e2e-topology:prepared-operator_gateway_app-dev_app-prod_agent-prod-dns-alias-current'])
        ->and($distributions[0]['images'][9])->toBe(['role' => 'agent', 'image' => 'orbit-e2e-topology:prepared-operator_gateway_app-dev_app-prod_agent-agent-dns-alias-current'])
        ->and($buildRuns[1]['command'])->toContain("'operator_gateway_app-dev_app-prod_agent'");
});

it('syncs existing build host images without rebuilding', function (): void {
    $runs = [];
    $distributions = [];

    Process::fake(function ($process) use (&$runs) {
        $runs[] = $process->command;

        return Process::result();
    });

    $distributor = m::mock(DockerImageDistributor::class);
    $distributor->shouldReceive('distribute')
        ->once()
        ->andReturnUsing(function (array $images, array $hosts) use (&$distributions): array {
            $distributions[] = [
                'images' => $images,
                'hosts' => $hosts,
            ];

            return [];
        });

    app()->bind(DockerImageDistributor::class, fn (): DockerImageDistributor => $distributor);

    withE2EConfigEnvironment([
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'sidecar1:2:28,sidecar2:2:28',
        'ORBIT_E2E_DOCKER_IMAGE_BUILD_HOSTS' => 'beast',
    ], function (): void {
        $this->artisan('e2e:prepare-docker-hosts', [
            'kind' => 'operator_gateway_agent',
            '--force' => true,
        ])
            ->expectsOutputToContain('existing: beast runtime')
            ->expectsOutputToContain('existing: beast topology:operator+operator_gateway_app-dev_app-prod_agent')
            ->assertSuccessful();
    });

    expect(implode("\n", $runs))
        ->not->toContain('composer e2e:prepare-docker-runtime')
        ->not->toContain('composer e2e:prepare-docker-topology')
        ->and($distributions)->toHaveCount(1)
        ->and($distributions[0]['hosts'])->toBe(['sidecar1', 'sidecar2'])
        ->and($distributions[0]['images'])->toHaveCount(10);
});

it('prepares app production ingress from the default docker role images', function (): void {
    $runs = [];
    $distributions = [];

    Process::fake(function ($process) use (&$runs) {
        $runs[] = $process->command;

        if (str_starts_with($process->command, 'docker image inspect ')) {
            return Process::result(exitCode: 1);
        }

        return Process::result();
    });

    $distributor = m::mock(DockerImageDistributor::class);
    $distributor->shouldReceive('distribute')
        ->once()
        ->andReturnUsing(function (array $images, array $hosts) use (&$distributions): array {
            $distributions[] = [
                'images' => $images,
                'hosts' => $hosts,
            ];

            return [];
        });

    app()->bind(DockerImageDistributor::class, fn (): DockerImageDistributor => $distributor);

    withE2EConfigEnvironment([
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'sidecar1:2:28,sidecar2:2:28',
        'ORBIT_E2E_DOCKER_IMAGE_BUILD_HOSTS' => 'beast',
    ], function (): void {
        $this->artisan('e2e:prepare-docker-hosts', [
            'kind' => 'operator_gateway_app-prod_ingress',
            '--force' => true,
        ])->assertSuccessful();
    });

    $buildRuns = implode("\n", $runs);

    expect($buildRuns)
        ->toContain("'operator_gateway_app-prod_ingress'")
        ->and($distributions[0]['images'])->toHaveCount(10)
        ->and($distributions[0]['images'][4])->toBe(['role' => 'operator', 'image' => 'orbit-e2e-topology:prepared-operator-operator-dns-alias-current'])
        ->and($distributions[0]['images'][5])->toBe(['role' => 'operator', 'image' => 'orbit-e2e-topology:prepared-operator_gateway_app-dev_app-prod_agent-operator-dns-alias-current'])
        ->and($distributions[0]['images'][6])->toBe(['role' => 'gateway', 'image' => 'orbit-e2e-topology:prepared-operator_gateway_app-dev_app-prod_agent-gateway-dns-alias-current'])
        ->and($distributions[0]['images'][8])->toBe(['role' => 'prod', 'image' => 'orbit-e2e-topology:prepared-operator_gateway_app-dev_app-prod_agent-prod-dns-alias-current']);
});

it('rejects multiple docker image build hosts to keep topology images combined', function (): void {
    Process::fake();

    withE2EConfigEnvironment([
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'sidecar1:2:28,sidecar2:2:28',
        'ORBIT_E2E_DOCKER_IMAGE_BUILD_HOSTS' => 'beast,sidecar1',
    ], function (): void {
        $this->artisan('e2e:prepare-docker-hosts', [
            'kind' => 'control-gateway-dev-prod',
            '--force' => true,
        ])
            ->expectsOutputToContain('Configure exactly one Docker image build host')
            ->assertFailed();
    });

    Process::assertNothingRan();
});

it('prepares Docker topology images', function (): void {
    $runs = [];

    Process::fake(function ($process) use (&$runs) {
        $runs[] = $process->command;

        if (str_starts_with($process->command, 'docker image inspect ')) {
            return Process::result(exitCode: 1);
        }

        return Process::result();
    });

    withE2EConfigEnvironment([
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'local:1:28',
    ], function (): void {
        $this->artisan('e2e:prepare-docker-hosts', [
            'kind' => 'control-gateway',
            '--force' => true,
            '--topology-only' => true,
        ])->assertSuccessful();
    });

    $buildRuns = array_values(array_filter($runs, fn (string $command): bool => str_contains($command, 'composer e2e:prepare-docker-')));

    expect($buildRuns)->toHaveCount(1)
        ->and($buildRuns[0])->toContain("composer e2e:prepare-docker-topology -- --force 'operator_gateway'");
});

it('defaults host preparation to prepared topology images', function (): void {
    $runs = [];

    Process::fake(function ($process) use (&$runs) {
        $runs[] = $process->command;

        if (str_starts_with($process->command, 'docker image inspect ')) {
            return Process::result(exitCode: 1);
        }

        return Process::result();
    });

    withE2EConfigEnvironment([
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'local:1:28',
    ], function (): void {
        $this->artisan('e2e:prepare-docker-hosts', [
            'kind' => 'control-gateway',
            '--force' => true,
            '--topology-only' => true,
        ])->assertSuccessful();
    });

    expect(implode("\n", $runs))
        ->toContain("composer e2e:prepare-docker-topology -- --force 'operator_gateway'");
});

it('supports preparing only topology images', function (): void {
    Process::fake(function ($process) {
        if (str_starts_with($process->command, 'docker image inspect ')) {
            return Process::result(exitCode: 1);
        }

        return Process::result();
    });

    withE2EConfigEnvironment([
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'beast:1:28',
    ], function (): void {
        $this->artisan('e2e:prepare-docker-hosts', [
            'kind' => 'control-gateway-dev-prod',
            '--force' => true,
            '--topology-only' => true,
        ])->assertSuccessful();
    });

    Process::assertRan(fn ($process): bool => str_contains($process->command, 'composer e2e:prepare-docker-topology -- --force'));
    Process::assertNotRan(fn ($process): bool => str_contains($process->command, 'composer e2e:prepare-docker-runtime'));
});

it('fails when one host preparation fails and reports the host', function (): void {
    Process::fake(function ($process) {
        $host = $process->environment['DOCKER_HOST'] ?? null;

        if (str_starts_with($process->command, 'docker image inspect ')) {
            return Process::result(exitCode: 1);
        }

        if ($host === 'ssh://beast') {
            return Process::result(output: 'build failed', exitCode: 1);
        }

        return Process::result();
    });

    withE2EConfigEnvironment([
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'sidecar1:2:28,sidecar2:2:28',
        'ORBIT_E2E_DOCKER_IMAGE_BUILD_HOSTS' => 'beast',
    ], function (): void {
        $this->artisan('e2e:prepare-docker-hosts', [
            'kind' => 'control-gateway-dev-prod',
            '--force' => true,
        ])
            ->expectsOutputToContain('beast')
            ->assertFailed();
    });
});
