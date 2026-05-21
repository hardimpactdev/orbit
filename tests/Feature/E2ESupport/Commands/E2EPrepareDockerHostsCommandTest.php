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

it('documents host preparation without force using docker host slots', function (): void {
    Process::fake();

    withE2EConfigEnvironment([
        'ORBIT_E2E_DOCKER_HOST_SLOTS' => 'sidecar1:2,sidecar2:2,beast:3',
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
        'ORBIT_E2E_DOCKER_HOST_SLOTS' => 'sidecar1:2,sidecar2:2',
        'ORBIT_E2E_DOCKER_IMAGE_BUILD_HOSTS' => 'beast',
    ], function (): void {
        $this->artisan('e2e:prepare-docker-hosts', ['kind' => 'operator-gateway-appprod-ingress'])
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
        'ORBIT_E2E_DOCKER_HOST_SLOTS' => 'sidecar1:2,sidecar2:2',
        'ORBIT_E2E_DOCKER_IMAGE_BUILD_HOSTS' => 'beast',
    ], function (): void {
        $this->artisan('e2e:prepare-docker-hosts', [
            'kind' => 'control-gateway-dev-prod',
            '--force' => true,
        ])->assertSuccessful();
    });

    $buildRuns = array_values(array_filter($runs, fn (array $run): bool => str_contains($run['command'], 'composer e2e:prepare-docker-')));

    expect($buildRuns)->toHaveCount(2)
        ->and($buildRuns[0]['environment'])->toMatchArray(['DOCKER_HOST' => 'ssh://beast'])
        ->and($buildRuns[0]['command'])->toContain('composer e2e:prepare-docker-runtime -- --force')
        ->and($buildRuns[1]['environment'])->toMatchArray(['DOCKER_HOST' => 'ssh://beast'])
        ->and($buildRuns[1]['command'])->toContain('composer e2e:prepare-docker-topology -- --force')
        ->and($distributions)->toHaveCount(1)
        ->and($distributions[0]['hosts'])->toBe(['sidecar1', 'sidecar2'])
        ->and($distributions[0]['images'])->toHaveCount(5)
        ->and($distributions[0]['images'][0])->toBe(['role' => 'runtime', 'image' => 'orbit-e2e-topology-runtime:current']);
});

it('rejects multiple docker image build hosts to keep topology images combined', function (): void {
    Process::fake();

    withE2EConfigEnvironment([
        'ORBIT_E2E_DOCKER_HOST_SLOTS' => 'sidecar1:2,sidecar2:2',
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

it('passes topology mode through to topology preparation', function (): void {
    $runs = [];

    Process::fake(function ($process) use (&$runs) {
        $runs[] = $process->command;

        return Process::result();
    });

    withE2EConfigEnvironment([
        'ORBIT_E2E_DOCKER_HOSTS' => 'local',
    ], function (): void {
        $this->artisan('e2e:prepare-docker-hosts', [
            'kind' => 'control-gateway',
            '--force' => true,
            '--topology-only' => true,
            '--topology-mode' => 'dns-alias',
        ])->assertSuccessful();
    });

    $buildRuns = array_values(array_filter($runs, fn (string $command): bool => str_contains($command, 'composer e2e:prepare-docker-')));

    expect($buildRuns)->toHaveCount(1)
        ->and($buildRuns[0])->toContain("composer e2e:prepare-docker-topology -- --force --topology-mode='dns-alias' 'operator-gateway'");
});

it('defaults host preparation to dns alias topology images', function (): void {
    $runs = [];

    Process::fake(function ($process) use (&$runs) {
        $runs[] = $process->command;

        return Process::result();
    });

    withE2EConfigEnvironment([
        'ORBIT_E2E_DOCKER_HOSTS' => 'local',
    ], function (): void {
        $this->artisan('e2e:prepare-docker-hosts', [
            'kind' => 'control-gateway',
            '--force' => true,
            '--topology-only' => true,
        ])->assertSuccessful();
    });

    expect(implode("\n", $runs))->toContain("--topology-mode='dns-alias'");
});

it('rejects unsupported host preparation topology mode', function (): void {
    $this->artisan('e2e:prepare-docker-hosts', [
        '--topology-mode' => 'invalid',
    ])
        ->expectsOutputToContain('Invalid topology mode')
        ->assertFailed();
});

it('supports preparing only topology images', function (): void {
    Process::fake([
        '*' => Process::result(),
    ]);

    withE2EConfigEnvironment([
        'ORBIT_E2E_DOCKER_HOSTS' => 'beast',
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

        if ($host === 'ssh://beast') {
            return Process::result(output: 'build failed', exitCode: 1);
        }

        return Process::result();
    });

    withE2EConfigEnvironment([
        'ORBIT_E2E_DOCKER_HOSTS' => 'sidecar1,sidecar2',
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
