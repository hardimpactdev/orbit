<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    Process::preventStrayProcesses();
});

it('reports docker e2e resources in dry-run json mode', function (): void {
    Process::fake([
        "docker ps -a --format '{{.Names}}' --filter 'name=orbit-e2e-'" => Process::result(
            output: "orbit-e2e-run-operator\n",
        ),
        "docker network ls --format '{{.Name}}' --filter 'name=orbit-e2e-'" => Process::result(
            output: "orbit-e2e-run\n",
        ),
        "docker volume ls --format '{{.Name}}' --filter 'name=orbit-e2e-'" => Process::result(
            output: "orbit-e2e-run-operator-home-operator\n",
        ),
    ]);

    $this
        ->artisan('e2e:reap-docker', ['--older-than' => '0m', '--json' => true])
        ->expectsOutput(json_encode([
            'success' => [
                'data' => [
                    'provider' => 'docker',
                    'dry_run' => true,
                    'older_than_minutes' => 0,
                    'filter' => 'prefix',
                    'resources' => [
                        'containers' => [
                            [
                                'type' => 'container',
                                'host' => 'local',
                                'name' => 'orbit-e2e-run-operator',
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
                                'name' => 'orbit-e2e-run-operator-home-operator',
                                'deleted' => false,
                            ],
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR))
        ->assertSuccessful();
});

it('removes docker e2e resources when forced', function (): void {
    Process::fake([
        "docker ps -a --format '{{.Names}}' --filter 'name=orbit-e2e-'" => Process::result(
            output: "orbit-e2e-run-operator\n",
        ),
        "docker network ls --format '{{.Name}}' --filter 'name=orbit-e2e-'" => Process::result(
            output: "orbit-e2e-run\n",
        ),
        "docker volume ls --format '{{.Name}}' --filter 'name=orbit-e2e-'" => Process::result(
            output: "orbit-e2e-run-operator-home-operator\n",
        ),
        "docker rm -f 'orbit-e2e-run-operator'" => Process::result(),
        "docker network rm 'orbit-e2e-run'" => Process::result(),
        "docker volume rm 'orbit-e2e-run-operator-home-operator'" => Process::result(),
    ]);

    $this->artisan('e2e:reap-docker', ['--older-than' => '0m', '--force' => true])
        ->assertSuccessful();

    Process::assertRan("docker rm -f 'orbit-e2e-run-operator'");
    Process::assertRan("docker network rm 'orbit-e2e-run'");
    Process::assertRan("docker volume rm 'orbit-e2e-run-operator-home-operator'");
});

it('removes docker e2e home volumes when forced', function (): void {
    Process::fake([
        "docker ps -a --format '{{.Names}}' --filter 'name=orbit-e2e-'" => Process::result(output: ''),
        "docker network ls --format '{{.Name}}' --filter 'name=orbit-e2e-'" => Process::result(output: ''),
        "docker volume ls --format '{{.Name}}' --filter 'name=orbit-e2e-'" => Process::result(
            output: "orbit-e2e-run-operator-home-operator\norbit-e2e-run-gateway-home-orbit\n",
        ),
        "docker volume rm 'orbit-e2e-run-operator-home-operator'" => Process::result(),
        "docker volume rm 'orbit-e2e-run-gateway-home-orbit'" => Process::result(),
    ]);

    $this->artisan('e2e:reap-docker', ['--older-than' => '0m', '--force' => true])
        ->assertSuccessful();

    Process::assertRan("docker volume rm 'orbit-e2e-run-operator-home-operator'");
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
            "docker ps -a --format '{{.Names}}' --filter 'name=orbit-e2e-'" => Process::result(
                output: "orbit-e2e-run-operator\n",
            ),
            "docker network ls --format '{{.Name}}' --filter 'name=orbit-e2e-'" => Process::result(
                output: "orbit-e2e-run\n",
            ),
            "docker volume ls --format '{{.Name}}' --filter 'name=orbit-e2e-'" => Process::result(
                output: "orbit-e2e-run-operator-home-operator\norbit-e2e-run-gateway-home-orbit\n",
            ),
            default => Process::result(),
        };
    });

    withE2EConfigEnvironment([
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'sidecar1:1:28',
    ], function (): void {
        $this->artisan('e2e:reap-docker', ['--older-than' => '0m', '--force' => true])
            ->assertSuccessful();
    });

    $commands = array_column($runs, 'command');
    $hosts = array_values(array_unique(array_map(
        fn (array $run): string => $run['environment']['DOCKER_HOST'] ?? 'local',
        $runs,
    )));

    expect($hosts)
        ->toBe(['ssh://sidecar1'])
        ->and($commands)
        ->toContain(
            "docker volume ls --format '{{.Name}}' --filter 'name=orbit-e2e-'",
            "docker volume rm 'orbit-e2e-run-operator-home-operator'",
            "docker volume rm 'orbit-e2e-run-gateway-home-orbit'",
        );
});

it('uses configured e2e prefix and docker hosts when reaping resources', function (): void {
    Process::fake([
        "docker ps -a --format '{{.Names}}' --filter 'name=orbit-custom-'" => Process::result(
            output: "orbit-custom-run-operator\n",
        ),
        "docker network ls --format '{{.Name}}' --filter 'name=orbit-custom-'" => Process::result(
            output: "orbit-custom-run\n",
        ),
        "docker volume ls --format '{{.Name}}' --filter 'name=orbit-custom-'" => Process::result(
            output: "orbit-custom-run-operator-home-operator\n",
        ),
    ]);

    withE2EConfigEnvironment([
        'ORBIT_E2E_INSTANCE_PREFIX' => 'orbit-custom',
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'beast:1:28',
    ], function (): void {
        $this
            ->artisan('e2e:reap-docker', ['--older-than' => '0m'])
            ->expectsOutputToContain('stale: container orbit-custom-run-operator on beast')
            ->expectsOutputToContain('stale: network orbit-custom-run on beast')
            ->expectsOutputToContain('stale: volume orbit-custom-run-operator-home-operator on beast')
            ->assertSuccessful();
    });
});

it('fails when docker resource listing fails', function (): void {
    Process::fake([
        "docker ps -a --format '{{.Names}}' --filter 'name=orbit-e2e-'" => Process::result(
            errorOutput: 'daemon down',
            exitCode: 1,
        ),
    ]);

    $this
        ->artisan('e2e:reap-docker', ['--json' => true])
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
        "docker ps -a --format '{{.Names}}' --filter 'name=orbit-e2e-'" => Process::result(
            output: "orbit-e2e-run-operator\n",
        ),
        "docker network ls --format '{{.Name}}' --filter 'name=orbit-e2e-'" => Process::result(output: ''),
        "docker volume ls --format '{{.Name}}' --filter 'name=orbit-e2e-'" => Process::result(output: ''),
        "docker rm -f 'orbit-e2e-run-operator'" => Process::result(errorOutput: 'delete failed', exitCode: 1),
    ]);

    $this
        ->artisan('e2e:reap-docker', ['--older-than' => '0m', '--force' => true])
        ->expectsOutputToContain('Could not delete Docker container orbit-e2e-run-operator on local: delete failed')
        ->assertFailed();
});
