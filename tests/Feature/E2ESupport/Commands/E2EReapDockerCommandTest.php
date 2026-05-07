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
    ]);

    $this->artisan('e2e:reap-docker', ['--older-than' => '0m', '--json' => true])
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
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR))
        ->assertSuccessful();
});

it('removes docker e2e resources when forced', function (): void {
    Process::fake([
        "docker ps -a --format '{{.Names}}' --filter 'name=orbit-e2e-'" => Process::result(output: "orbit-e2e-run-control\n"),
        "docker network ls --format '{{.Name}}' --filter 'name=orbit-e2e-'" => Process::result(output: "orbit-e2e-run\n"),
        "docker rm -f 'orbit-e2e-run-control'" => Process::result(),
        "docker network rm 'orbit-e2e-run'" => Process::result(),
    ]);

    $this->artisan('e2e:reap-docker', ['--older-than' => '0m', '--force' => true])
        ->assertSuccessful();

    Process::assertRan("docker rm -f 'orbit-e2e-run-control'");
    Process::assertRan("docker network rm 'orbit-e2e-run'");
});

it('uses configured e2e prefix and docker hosts when reaping resources', function (): void {
    Process::fake([
        "docker ps -a --format '{{.Names}}' --filter 'name=orbit-custom-'" => Process::result(output: "orbit-custom-run-control\n"),
        "docker network ls --format '{{.Name}}' --filter 'name=orbit-custom-'" => Process::result(output: "orbit-custom-run\n"),
    ]);

    withE2EConfigEnvironment([
        'ORBIT_E2E_INSTANCE_PREFIX' => 'orbit-custom',
        'ORBIT_E2E_DOCKER_HOSTS' => 'beast',
    ], function (): void {
        $this->artisan('e2e:reap-docker', ['--older-than' => '0m'])
            ->expectsOutputToContain('stale: container orbit-custom-run-control on beast')
            ->expectsOutputToContain('stale: network orbit-custom-run on beast')
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
        "docker rm -f 'orbit-e2e-run-control'" => Process::result(errorOutput: 'delete failed', exitCode: 1),
    ]);

    $this->artisan('e2e:reap-docker', ['--older-than' => '0m', '--force' => true])
        ->expectsOutputToContain('Could not delete Docker container orbit-e2e-run-control on local: delete failed')
        ->assertFailed();
});
