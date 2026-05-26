<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    Process::preventStrayProcesses();
});

it('reports docker e2e resources in dry-run json mode', function (): void {
    Process::fake([
        "docker ps -a --format '{{.Names}}' --filter 'name=orbit-e2e-'" => Process::result(output: "orbit-e2e-run-control\n"),
        "docker network ls --format '{{.Name}}' --filter 'name=orbit-e2e-'" => Process::result(output: "orbit-e2e-run\n"),
        "docker volume ls --format '{{.Name}}' --filter 'name=orbit-e2e-'" => Process::result(output: "orbit-e2e-run-control-home-control\n"),
        "docker ps -a --format '{{.Names}}' --filter 'volume=orbit-e2e-run-control-home-control'" => Process::result(output: ''),
        "docker volume inspect --format '{{json .}}' 'orbit-e2e-run-control-home-control'" => Process::result(output: json_encode(['CreatedAt' => '2026-05-03T03:00:00Z'])),
        "docker image ls --format '{{.Repository}}:{{.Tag}}'" => Process::result(output: ''),
    ]);

    $this->artisan('e2e:reap-docker', ['--older-than' => '0m', '--json' => true])
        ->expectsOutput(json_encode([
            'success' => [
                'data' => [
                    'provider' => 'docker',
                    'dry_run' => true,
                    'older_than_minutes' => 0,
                    'artifact_older_than_minutes' => 360,
                    'filter' => 'prefix',
                    'resources' => [
                        'containers' => [
                            [
                                'type' => 'container',
                                'host' => 'local',
                                'name' => 'orbit-e2e-run-control',
                                'deleted' => false,
                            ],
                        ],
                        'networks' => [
                            [
                                'type' => 'network',
                                'host' => 'local',
                                'name' => 'orbit-e2e-run',
                                'deleted' => false,
                            ],
                        ],
                        'volumes' => [
                            [
                                'type' => 'volume',
                                'host' => 'local',
                                'name' => 'orbit-e2e-run-control-home-control',
                                'created' => '2026-05-03T03:00:00Z',
                                'deleted' => false,
                            ],
                        ],
                        'images' => [],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR))
        ->assertSuccessful();
});

it('reports only old unused orbit docker artifact images and volumes', function (): void {
    Process::fake(function ($process) {
        return match ($process->command) {
            "docker ps -a --format '{{.Names}}' --filter 'name=orbit-e2e-'" => Process::result(output: ''),
            "docker network ls --format '{{.Name}}' --filter 'name=orbit-e2e-'" => Process::result(output: ''),
            "docker volume ls --format '{{.Name}}' --filter 'name=orbit-e2e-'" => Process::result(output: "orbit-e2e-run-stale-home\norbit-e2e-run-fresh-home\norbit-e2e-run-used-home\n"),
            "docker ps -a --format '{{.Names}}' --filter 'volume=orbit-e2e-run-stale-home'",
            "docker ps -a --format '{{.Names}}' --filter 'volume=orbit-e2e-run-fresh-home'" => Process::result(output: ''),
            "docker ps -a --format '{{.Names}}' --filter 'volume=orbit-e2e-run-used-home'" => Process::result(output: "orbit-e2e-run-control\n"),
            "docker volume inspect --format '{{json .}}' 'orbit-e2e-run-stale-home'" => Process::result(output: json_encode(['CreatedAt' => '2026-05-03T03:00:00Z'])),
            "docker volume inspect --format '{{json .}}' 'orbit-e2e-run-fresh-home'" => Process::result(output: json_encode(['CreatedAt' => '2026-05-03T09:00:00Z'])),
            "docker image ls --format '{{.Repository}}:{{.Tag}}'" => Process::result(output: "orbit-e2e-topology-runtime:prepared-current\norbit-e2e-topology-runtime:fresh\norbit-runtime:prepared-current\norbit-runtime:production\nubuntu:24.04\n"),
            "docker image inspect --format '{{json .}}' 'orbit-e2e-topology-runtime:prepared-current'" => Process::result(output: json_encode(['Created' => '2026-05-03T03:00:00Z', 'Config' => ['Labels' => ['org.orbit.e2e.source' => 'prepared-checkout']]])),
            "docker image inspect --format '{{json .}}' 'orbit-e2e-topology-runtime:fresh'" => Process::result(output: json_encode(['Created' => '2026-05-03T09:00:00Z', 'Config' => ['Labels' => ['org.orbit.e2e.source' => 'prepared-checkout']]])),
            "docker image inspect --format '{{json .}}' 'orbit-runtime:prepared-current'" => Process::result(output: json_encode(['Created' => '2026-05-03T02:00:00Z', 'Config' => ['Labels' => ['org.orbit.e2e.artifact' => 'true']]])),
            "docker image inspect --format '{{json .}}' 'orbit-runtime:production'" => Process::result(output: json_encode(['Created' => '2026-05-03T02:00:00Z', 'Config' => ['Labels' => null]])),
            "docker ps -a --format '{{.Names}}' --filter 'ancestor=orbit-e2e-topology-runtime:prepared-current'",
            "docker ps -a --format '{{.Names}}' --filter 'ancestor=orbit-runtime:prepared-current'" => Process::result(output: ''),
            default => Process::result(),
        };
    });

    $this->travelTo(new DateTimeImmutable('2026-05-03T10:00:00Z'));

    $this->artisan('e2e:reap-docker', ['--json' => true])
        ->expectsOutput(json_encode([
            'success' => [
                'data' => [
                    'provider' => 'docker',
                    'dry_run' => true,
                    'older_than_minutes' => 30,
                    'artifact_older_than_minutes' => 360,
                    'filter' => 'prefix',
                    'resources' => [
                        'containers' => [],
                        'networks' => [],
                        'volumes' => [
                            [
                                'type' => 'volume',
                                'host' => 'local',
                                'name' => 'orbit-e2e-run-stale-home',
                                'created' => '2026-05-03T03:00:00Z',
                                'deleted' => false,
                            ],
                        ],
                        'images' => [
                            [
                                'type' => 'image',
                                'host' => 'local',
                                'name' => 'orbit-e2e-topology-runtime:prepared-current',
                                'created' => '2026-05-03T03:00:00Z',
                                'deleted' => false,
                            ],
                            [
                                'type' => 'image',
                                'host' => 'local',
                                'name' => 'orbit-runtime:prepared-current',
                                'created' => '2026-05-03T02:00:00Z',
                                'deleted' => false,
                            ],
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR))
        ->assertSuccessful();

    Process::assertNotRan(fn ($process): bool => str_contains($process->command, 'prune'));
});

it('deletes old unused orbit docker artifact images and volumes when forced', function (): void {
    Process::fake([
        "docker ps -a --format '{{.Names}}' --filter 'name=orbit-e2e-'" => Process::result(output: ''),
        "docker network ls --format '{{.Name}}' --filter 'name=orbit-e2e-'" => Process::result(output: ''),
        "docker volume ls --format '{{.Name}}' --filter 'name=orbit-e2e-'" => Process::result(output: "orbit-e2e-run-stale-home\n"),
        "docker ps -a --format '{{.Names}}' --filter 'volume=orbit-e2e-run-stale-home'" => Process::result(output: ''),
        "docker volume inspect --format '{{json .}}' 'orbit-e2e-run-stale-home'" => Process::result(output: json_encode(['CreatedAt' => '2026-05-03T03:00:00Z'])),
        "docker image ls --format '{{.Repository}}:{{.Tag}}'" => Process::result(output: "orbit-e2e-topology-runtime:prepared-current\n"),
        "docker image inspect --format '{{json .}}' 'orbit-e2e-topology-runtime:prepared-current'" => Process::result(output: json_encode(['Created' => '2026-05-03T03:00:00Z', 'Config' => ['Labels' => ['org.orbit.e2e.source' => 'prepared-checkout']]])),
        "docker ps -a --format '{{.Names}}' --filter 'ancestor=orbit-e2e-topology-runtime:prepared-current'" => Process::result(output: ''),
        "docker volume rm 'orbit-e2e-run-stale-home'" => Process::result(),
        "docker image rm 'orbit-e2e-topology-runtime:prepared-current'" => Process::result(),
    ]);

    $this->travelTo(new DateTimeImmutable('2026-05-03T10:00:00Z'));

    $this->artisan('e2e:reap-docker', ['--force' => true])
        ->assertSuccessful();

    Process::assertRan("docker volume rm 'orbit-e2e-run-stale-home'");
    Process::assertRan("docker image rm 'orbit-e2e-topology-runtime:prepared-current'");
    Process::assertNotRan(fn ($process): bool => str_contains($process->command, 'prune'));
});

it('removes docker e2e resources when forced', function (): void {
    Process::fake([
        "docker ps -a --format '{{.Names}}' --filter 'name=orbit-e2e-'" => Process::result(output: "orbit-e2e-run-control\n"),
        "docker network ls --format '{{.Name}}' --filter 'name=orbit-e2e-'" => Process::result(output: "orbit-e2e-run\n"),
        "docker volume ls --format '{{.Name}}' --filter 'name=orbit-e2e-'" => Process::result(output: "orbit-e2e-run-control-home-control\n"),
        "docker ps -a --format '{{.Names}}' --filter 'volume=orbit-e2e-run-control-home-control'" => Process::result(output: ''),
        "docker volume inspect --format '{{json .}}' 'orbit-e2e-run-control-home-control'" => Process::result(output: json_encode(['CreatedAt' => '2026-05-03T03:00:00Z'])),
        "docker image ls --format '{{.Repository}}:{{.Tag}}'" => Process::result(output: ''),
        "docker rm -f 'orbit-e2e-run-control'" => Process::result(),
        "docker network rm 'orbit-e2e-run'" => Process::result(),
        "docker volume rm 'orbit-e2e-run-control-home-control'" => Process::result(),
    ]);

    $this->artisan('e2e:reap-docker', ['--older-than' => '0m', '--artifacts-older-than' => '0m', '--force' => true])
        ->assertSuccessful();

    Process::assertRan("docker rm -f 'orbit-e2e-run-control'");
    Process::assertRan("docker network rm 'orbit-e2e-run'");
    Process::assertRan("docker volume rm 'orbit-e2e-run-control-home-control'");
});

it('removes docker e2e home volumes when forced', function (): void {
    Process::fake([
        "docker ps -a --format '{{.Names}}' --filter 'name=orbit-e2e-'" => Process::result(output: ''),
        "docker network ls --format '{{.Name}}' --filter 'name=orbit-e2e-'" => Process::result(output: ''),
        "docker volume ls --format '{{.Name}}' --filter 'name=orbit-e2e-'" => Process::result(output: "orbit-e2e-run-control-home-control\norbit-e2e-run-gateway-home-orbit\n"),
        "docker ps -a --format '{{.Names}}' --filter 'volume=orbit-e2e-run-control-home-control'" => Process::result(output: ''),
        "docker volume inspect --format '{{json .}}' 'orbit-e2e-run-control-home-control'" => Process::result(output: json_encode(['CreatedAt' => '2026-05-03T03:00:00Z'])),
        "docker ps -a --format '{{.Names}}' --filter 'volume=orbit-e2e-run-gateway-home-orbit'" => Process::result(output: ''),
        "docker volume inspect --format '{{json .}}' 'orbit-e2e-run-gateway-home-orbit'" => Process::result(output: json_encode(['CreatedAt' => '2026-05-03T03:00:00Z'])),
        "docker image ls --format '{{.Repository}}:{{.Tag}}'" => Process::result(output: ''),
        "docker volume rm 'orbit-e2e-run-control-home-control'" => Process::result(),
        "docker volume rm 'orbit-e2e-run-gateway-home-orbit'" => Process::result(),
    ]);

    $this->artisan('e2e:reap-docker', ['--older-than' => '0m', '--artifacts-older-than' => '0m', '--force' => true])
        ->assertSuccessful();

    Process::assertRan("docker volume rm 'orbit-e2e-run-control-home-control'");
    Process::assertRan("docker volume rm 'orbit-e2e-run-gateway-home-orbit'");
});

it('reaps docker slot hosts when no docker host list is configured', function (): void {
    $runs = [];

    Process::fake(function ($process) use (&$runs) {
        $runs[] = [
            'command' => $process->command,
            'environment' => $process->environment,
        ];

        return match ($process->command) {
            "docker ps -a --format '{{.Names}}' --filter 'name=orbit-e2e-'" => Process::result(output: "orbit-e2e-run-control\n"),
            "docker network ls --format '{{.Name}}' --filter 'name=orbit-e2e-'" => Process::result(output: "orbit-e2e-run\n"),
            "docker volume ls --format '{{.Name}}' --filter 'name=orbit-e2e-'" => Process::result(output: "orbit-e2e-run-control-home-control\norbit-e2e-run-gateway-home-orbit\n"),
            "docker ps -a --format '{{.Names}}' --filter 'volume=orbit-e2e-run-control-home-control'",
            "docker ps -a --format '{{.Names}}' --filter 'volume=orbit-e2e-run-gateway-home-orbit'",
            "docker image ls --format '{{.Repository}}:{{.Tag}}'" => Process::result(output: ''),
            "docker volume inspect --format '{{json .}}' 'orbit-e2e-run-control-home-control'",
            "docker volume inspect --format '{{json .}}' 'orbit-e2e-run-gateway-home-orbit'" => Process::result(output: json_encode(['CreatedAt' => '2026-05-03T03:00:00Z'])),
            default => Process::result(),
        };
    });

    withE2EConfigEnvironment([
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'sidecar1:1:28',
    ], function (): void {
        $this->artisan('e2e:reap-docker', ['--older-than' => '0m', '--artifacts-older-than' => '0m', '--force' => true])
            ->assertSuccessful();
    });

    $commands = array_column($runs, 'command');
    $hosts = array_values(array_unique(array_map(
        fn (array $run): string => $run['environment']['DOCKER_HOST'] ?? 'local',
        $runs,
    )));

    expect($hosts)->toBe(['ssh://sidecar1'])
        ->and($commands)->toContain(
            "docker volume ls --format '{{.Name}}' --filter 'name=orbit-e2e-'",
            "docker volume rm 'orbit-e2e-run-control-home-control'",
            "docker volume rm 'orbit-e2e-run-gateway-home-orbit'",
        );
});

it('uses configured e2e prefix and docker hosts when reaping resources', function (): void {
    Process::fake([
        "docker ps -a --format '{{.Names}}' --filter 'name=orbit-custom-'" => Process::result(output: "orbit-custom-run-control\n"),
        "docker network ls --format '{{.Name}}' --filter 'name=orbit-custom-'" => Process::result(output: "orbit-custom-run\n"),
        "docker volume ls --format '{{.Name}}' --filter 'name=orbit-custom-'" => Process::result(output: "orbit-custom-run-control-home-control\n"),
        "docker ps -a --format '{{.Names}}' --filter 'volume=orbit-custom-run-control-home-control'" => Process::result(output: ''),
        "docker volume inspect --format '{{json .}}' 'orbit-custom-run-control-home-control'" => Process::result(output: json_encode(['CreatedAt' => '2026-05-03T03:00:00Z'])),
        "docker image ls --format '{{.Repository}}:{{.Tag}}'" => Process::result(output: ''),
    ]);

    withE2EConfigEnvironment([
        'ORBIT_E2E_INSTANCE_PREFIX' => 'orbit-custom',
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'beast:1:28',
    ], function (): void {
        $this->artisan('e2e:reap-docker', ['--older-than' => '0m'])
            ->expectsOutputToContain('stale: container orbit-custom-run-control on beast')
            ->expectsOutputToContain('stale: network orbit-custom-run on beast')
            ->expectsOutputToContain('stale: volume orbit-custom-run-control-home-control on beast')
            ->assertSuccessful();
    });
});

it('fails when docker resource listing fails', function (): void {
    Process::fake([
        "docker ps -a --format '{{.Names}}' --filter 'name=orbit-e2e-'" => Process::result(errorOutput: 'daemon down', exitCode: 1),
    ]);

    $this->artisan('e2e:reap-docker', ['--json' => true])
        ->expectsOutput(json_encode([
            'error' => [
                'code' => 'docker_failed',
                'message' => 'Could not list Docker E2E resources on local: daemon down',
            ],
        ], JSON_THROW_ON_ERROR))
        ->assertFailed();
});

it('fails when forced docker resource deletion fails', function (): void {
    Process::fake([
        "docker ps -a --format '{{.Names}}' --filter 'name=orbit-e2e-'" => Process::result(output: "orbit-e2e-run-control\n"),
        "docker network ls --format '{{.Name}}' --filter 'name=orbit-e2e-'" => Process::result(output: ''),
        "docker volume ls --format '{{.Name}}' --filter 'name=orbit-e2e-'" => Process::result(output: ''),
        "docker image ls --format '{{.Repository}}:{{.Tag}}'" => Process::result(output: ''),
        "docker rm -f 'orbit-e2e-run-control'" => Process::result(errorOutput: 'delete failed', exitCode: 1),
    ]);

    $this->artisan('e2e:reap-docker', ['--older-than' => '0m', '--force' => true])
        ->expectsOutputToContain('Could not delete Docker container orbit-e2e-run-control on local: delete failed')
        ->assertFailed();
});
