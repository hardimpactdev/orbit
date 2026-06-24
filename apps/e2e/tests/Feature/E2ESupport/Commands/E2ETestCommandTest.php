<?php

declare(strict_types=1);

use App\Console\Commands\E2ETestCommand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

it('plans docker and incus lanes by default', function (): void {
    withE2EEnvironment(['ORBIT_E2E_LANES'], [
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'sidecar1:4:28,sidecar2:4:28',
        'ORBIT_E2E_INCUS_HOST_VM_CAPS' => 'beast:12',
    ], function (): void {
        $exitCode = Artisan::call('e2e:test', [
            '--dry-run' => true,
            '--json' => true,
            '--lanes' => 'all',
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['lanes'])->sequence(
                fn ($lane) => $lane
                    ->lane->toBe('docker')
                    ->environment->ORBIT_E2E_TOPOLOGY_PROVIDER->toBe('docker')
                    ->environment->ORBIT_E2E_TOPOLOGY_PROVIDERS->toBe('docker')
                    ->environment->ORBIT_E2E_FAIL_ON_TOPOLOGY_UNAVAILABLE->toBe('1')
                    ->environment->ORBIT_E2E_TOPOLOGY_CACHE_LIMIT->toBe('1'),
                fn ($lane) => $lane
                    ->lane->toBe('incus')
                    ->environment->ORBIT_E2E_TOPOLOGY_PROVIDER->toBe('incus')
                    ->environment->ORBIT_E2E_TOPOLOGY_PROVIDERS->toBe('incus')
                    ->environment->ORBIT_E2E_FAIL_ON_TOPOLOGY_UNAVAILABLE->toBe('1')
                    ->environment->ORBIT_E2E_TOPOLOGY_CACHE_LIMIT->toBe('1'),
            );
    });
});

it('passes GitHub auth to lane workers without exposing it in dry-run plans', function (): void {
    Process::fake(fn () => Process::result());
    Process::preventStrayProcesses();

    withE2EEnvironment(['GH_TOKEN', 'GITHUB_TOKEN'], [
        'GH_TOKEN' => 'ghp_lane_secret',
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'sidecar1:4:28',
    ], function (): void {
        $this->artisan('e2e:test --lanes=docker')
            ->assertSuccessful();
    });

    Process::assertRan(fn ($process): bool => is_array($process->command)
        && in_array('test', $process->command, true)
        && ($process->environment['GH_TOKEN'] ?? null) === 'ghp_lane_secret');

    withE2EEnvironment(['GH_TOKEN', 'GITHUB_TOKEN'], [
        'GH_TOKEN' => 'ghp_lane_secret',
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'sidecar1:4:28',
        'ORBIT_E2E_INCUS_HOST_VM_CAPS' => 'beast:12',
    ], function (): void {
        $exitCode = Artisan::call('e2e:test', [
            '--dry-run' => true,
            '--json' => true,
            '--lanes' => 'all',
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and(Artisan::output())->not->toContain('ghp_lane_secret')
            ->and($payload['success']['data']['lanes'])->each(
                fn ($lane) => $lane->environment->not->toHaveKey('GH_TOKEN'),
            );
    });
});

it('formats parseable plan metadata before e2e lanes run', function (): void {
    $line = invokeE2ETestCommandMethod(app(E2ETestCommand::class), 'planMetadataLine', [[
        'lane' => 'docker',
        'command' => ['php', 'artisan', 'test', '--parallel', '--processes=4'],
        'environment' => [
            'ORBIT_E2E_TOPOLOGY_PROVIDER' => 'docker',
            'ORBIT_E2E_PARALLEL_PROCESSES' => '4',
            'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'sidecar1:2:28,sidecar2:2:28',
            'ORBIT_E2E_DOCKER_MIN_PROCESSES' => '2',
        ],
        'test_files' => [
            'tests/Feature/Commands/AppNewCommandTest.php',
            'tests/Feature/Commands/DoctorCommandTest.php',
        ],
    ], 'parallel']);

    expect($line)->toStartWith('[orbit-e2e-plan] ');

    $payload = json_decode(substr($line, strlen('[orbit-e2e-plan] ')), true, flags: JSON_THROW_ON_ERROR);

    expect($payload)->toMatchArray([
        'schema_version' => 1,
        'lane' => 'docker',
        'provider' => 'docker',
        'lane_execution_mode' => 'parallel',
        'test_execution_mode' => 'parallel',
        'command_processes' => 4,
        'test_file_count' => 2,
        'environment' => [
            'ORBIT_E2E_PARALLEL_PROCESSES' => '4',
            'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'sidecar1:2:28,sidecar2:2:28',
            'ORBIT_E2E_DOCKER_MIN_PROCESSES' => '2',
        ],
    ]);
});

it('uses selected lanes from the environment', function (): void {
    withE2EEnvironment(['ORBIT_E2E_LANES'], [
        'ORBIT_E2E_LANES' => 'incus',
        'ORBIT_E2E_INCUS_HOST_VM_CAPS' => 'beast:12',
    ], function (): void {
        $this->artisan('e2e:test --dry-run --json')
            ->expectsOutputToContain('"lane":"incus"')
            ->doesntExpectOutputToContain('"lane":"docker"')
            ->assertSuccessful();
    });
});

it('preserves an explicit topology artifact namespace for lane benchmarks', function (): void {
    withE2EEnvironment(['ORBIT_E2E_INSTANCE_PREFIX'], [
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'sidecar1:4:28,sidecar2:4:28',
        'ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE' => 'branch-a',
        'ORBIT_E2E_INCUS_HOST_VM_CAPS' => 'beast:12',
    ], function (): void {
        $exitCode = Artisan::call('e2e:test', [
            '--dry-run' => true,
            '--json' => true,
            '--lanes' => 'all',
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['lanes'])->each(
                fn ($lane) => $lane->environment->ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE->toBe('branch-a'),
            )
            ->and($payload['success']['data']['lanes'])->each(
                fn ($lane) => $lane->environment->ORBIT_E2E_INSTANCE_PREFIX->toBe('orbit-e2e-branch-a'),
            );
    });
});

it('preserves an explicit topology cache mode for lane diagnostics', function (): void {
    withE2EEnvironment([], [
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'sidecar1:4:28,sidecar2:4:28',
        'ORBIT_E2E_TOPOLOGY_CACHE' => '0',
    ], function (): void {
        $exitCode = Artisan::call('e2e:test', [
            '--dry-run' => true,
            '--json' => true,
            '--lanes' => 'docker',
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['lanes'][0]['environment']['ORBIT_E2E_TOPOLOGY_CACHE'])->toBe('0');
    });
});

it('preserves an explicit topology cache limit for lane diagnostics', function (): void {
    withE2EEnvironment([], [
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'sidecar1:4:28,sidecar2:4:28',
        'ORBIT_E2E_TOPOLOGY_CACHE_LIMIT' => '3',
    ], function (): void {
        $exitCode = Artisan::call('e2e:test', [
            '--dry-run' => true,
            '--json' => true,
            '--lanes' => 'docker',
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['lanes'][0]['environment']['ORBIT_E2E_TOPOLOGY_CACHE_LIMIT'])->toBe('3');
    });
});

it('preserves an explicit runtime instance prefix for namespaced lane benchmarks', function (): void {
    withE2EEnvironment([], [
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'sidecar1:4:28,sidecar2:4:28',
        'ORBIT_E2E_INSTANCE_PREFIX' => 'orbit-e2e-manual',
        'ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE' => 'branch-a',
    ], function (): void {
        $exitCode = Artisan::call('e2e:test', [
            '--dry-run' => true,
            '--json' => true,
            '--lanes' => 'docker',
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['lanes'][0]['environment']['ORBIT_E2E_INSTANCE_PREFIX'])->toBe('orbit-e2e-manual');
    });
});

it('does not allocate a timings file for dry-run json output', function (): void {
    $previous = getenv('ORBIT_E2E_TIMINGS');
    putenv('ORBIT_E2E_TIMINGS=1');

    try {
        withE2EEnvironment([], [
            'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'sidecar1:4:28,sidecar2:4:28',
        ], function () use (&$exitCode, &$payload, &$environment): void {
            $exitCode = Artisan::call('e2e:test', [
                '--dry-run' => true,
                '--json' => true,
                '--lanes' => 'docker',
            ]);
            $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);
            $environment = $payload['success']['data']['lanes'][0]['environment'];
        });

        expect($exitCode)->toBe(0)
            ->and($environment)->not->toHaveKey('ORBIT_E2E_TIMINGS_FILE');
    } finally {
        $previous === false
            ? putenv('ORBIT_E2E_TIMINGS')
            : putenv("ORBIT_E2E_TIMINGS={$previous}");
    }
});

it('disables pest parallel mode for list-tests passthrough', function (): void {
    withE2EEnvironment([], [
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'sidecar1:4:28,sidecar2:4:28',
    ], function (): void {
        $this->artisan('e2e:test --dry-run --json --lanes=docker --list-tests')
            ->expectsOutputToContain('"--list-tests"')
            ->doesntExpectOutputToContain('"--parallel"')
            ->assertSuccessful();
    });
});

it('plans the docker canary lane without leaking the canary flag', function (): void {
    withE2EEnvironment([], [
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'sidecar1:4:28,sidecar2:4:28',
    ], function () use (&$exitCode, &$payload, &$command): void {
        $exitCode = Artisan::call('e2e:test', [
            '--canary' => true,
            '--dry-run' => true,
            '--json' => true,
            '--lanes' => 'docker',
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);
        $command = $payload['success']['data']['lanes'][0]['command'];
    });

    expect($exitCode)->toBe(0)
        ->and($command)->toContain('--group=e2e-feature-canary')
        ->and($command)->not->toContain('--group=e2e-feature')
        ->and($command)->not->toContain('--canary');
});

it('requires explicit docker e2e capacity', function (): void {
    withE2EEnvironment([], [
        'ORBIT_E2E_PARALLEL_PROCESSES' => '1',
    ], function (): void {
        $this->artisan('e2e:test --dry-run --json --lanes=docker')
            ->expectsOutputToContain('Docker E2E capacity is not configured. Set ORBIT_E2E_DOCKER_TEST_RUNNERS=host:slots:containers.')
            ->assertFailed();
    });
});

it('derives docker worker counts from configured runner slots', function (): void {
    withE2EEnvironment([], [
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'sidecar1:4:28,sidecar2:4:28,sidecar3:4:28',
    ], function (): void {
        $exitCode = Artisan::call('e2e:test', [
            '--dry-run' => true,
            '--json' => true,
            '--lanes' => 'docker',
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);
        $lane = $payload['success']['data']['lanes'][0];

        expect($exitCode)->toBe(0)
            ->and($lane['environment']['ORBIT_E2E_DOCKER_TEST_RUNNERS'])->toBe('sidecar1:4:28,sidecar2:4:28,sidecar3:4:28')
            ->and($lane['environment']['ORBIT_E2E_PARALLEL_PROCESSES'])->toBe('12')
            ->and($lane['command'])->toContain('--processes=12');
    });
});

it('reserves selected Incus hosts out of aggregate docker dry-run plans', function (): void {
    withE2EEnvironment([], [
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'sidecar1:4:28,sidecar2:4:28,beast:4:28',
        'ORBIT_E2E_INCUS_HOSTS' => 'beast',
        'ORBIT_E2E_INCUS_HOST_VM_CAPS' => 'beast:12',
        'ORBIT_E2E_INCUS_PARALLEL_PROCESSES' => '2',
    ], function (): void {
        $exitCode = Artisan::call('e2e:test', [
            '--dry-run' => true,
            '--json' => true,
            '--lanes' => 'all',
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);
        $dockerLane = $payload['success']['data']['lanes'][0];
        $incusLane = $payload['success']['data']['lanes'][1];

        expect($exitCode)->toBe(0)
            ->and($dockerLane['lane'])->toBe('docker')
            ->and($dockerLane['environment']['ORBIT_E2E_DOCKER_TEST_RUNNERS'])->toBe('sidecar1:4:28,sidecar2:4:28')
            ->and($dockerLane['environment']['ORBIT_E2E_PARALLEL_PROCESSES'])->toBe('8')
            ->and($dockerLane['command'])->toContain('--processes=8')
            ->and($incusLane['lane'])->toBe('incus')
            ->and($incusLane['environment']['ORBIT_E2E_TOPOLOGY_PROVIDER'])->toBe('incus');
    });
});

it('caps docker worker counts at the run-scoped subnet allocator capacity', function (): void {
    withE2EEnvironment([], [
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'sidecar1:5:35,sidecar2:5:35,nmbp:5:35,beast:5:35',
    ], function (): void {
        $exitCode = Artisan::call('e2e:test', [
            '--dry-run' => true,
            '--json' => true,
            '--lanes' => 'docker',
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);
        $lane = $payload['success']['data']['lanes'][0];

        expect($exitCode)->toBe(0)
            ->and($lane['environment']['ORBIT_E2E_DOCKER_TEST_RUNNERS'])->toBe('sidecar1:4:35,sidecar2:4:35,nmbp:4:35,beast:4:35')
            ->and($lane['environment']['ORBIT_E2E_PARALLEL_PROCESSES'])->toBe('16')
            ->and($lane['command'])->toContain('--processes=16');
    });
});

it('uses requested docker worker counts by reducing host slots', function (): void {
    withE2EEnvironment(['ORBIT_E2E_PARALLEL_PROCESSES'], [
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'sidecar1:4:28,sidecar2:4:28',
        'ORBIT_E2E_PARALLEL_PROCESSES' => '6',
    ], function (): void {
        $exitCode = Artisan::call('e2e:test', [
            '--dry-run' => true,
            '--json' => true,
            '--lanes' => 'docker',
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);
        $lane = $payload['success']['data']['lanes'][0];

        expect($exitCode)->toBe(0)
            ->and($lane['environment']['ORBIT_E2E_DOCKER_TEST_RUNNERS'])->toBe('sidecar1:3:28,sidecar2:3:28')
            ->and($lane['environment']['ORBIT_E2E_PARALLEL_PROCESSES'])->toBe('6')
            ->and($lane['command'])->toContain('--processes=6');
    });
});

it('rejects invalid docker test runner entries', function (): void {
    withE2EEnvironment(['ORBIT_E2E_PARALLEL_PROCESSES'], [
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'sidecar1:4',
        'ORBIT_E2E_PARALLEL_PROCESSES' => '8',
    ], function (): void {
        $this->artisan('e2e:test --dry-run --json --lanes=docker')
            ->expectsOutputToContain('Invalid Docker test runner entry [sidecar1:4]')
            ->assertFailed();
    });
});

it('rejects docker host-specific container caps below that host slot capacity', function (): void {
    withE2EEnvironment(['ORBIT_E2E_PARALLEL_PROCESSES'], [
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'beast:4:24,sidecar1:4:2',
        'ORBIT_E2E_PARALLEL_PROCESSES' => '8',
    ], function (): void {
        $this->artisan('e2e:test --dry-run --json --lanes=docker')
            ->expectsOutputToContain('Docker host [sidecar1] needs a container cap of at least 20')
            ->assertFailed();
    });
});

it('caps docker workers to the largest selected topology capacity', function (): void {
    withE2EEnvironment(['ORBIT_E2E_PARALLEL_PROCESSES'], [
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'sidecar1:4:10,sidecar2:4:10',
        'ORBIT_E2E_PARALLEL_PROCESSES' => '8',
    ], function (): void {
        $exitCode = Artisan::call('e2e:test', [
            '--dry-run' => true,
            '--json' => true,
            '--lanes' => 'docker',
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);
        $lane = $payload['success']['data']['lanes'][0];

        expect($exitCode)->toBe(0)
            ->and($lane['environment']['ORBIT_E2E_DOCKER_TEST_RUNNERS'])->toBe('sidecar1:2:10,sidecar2:2:10')
            ->and($lane['environment']['ORBIT_E2E_PARALLEL_PROCESSES'])->toBe('4')
            ->and($lane['command'])->toContain('--processes=4');
    });
});

it('caps docker workers per host container capacity', function (): void {
    withE2EEnvironment(['ORBIT_E2E_PARALLEL_PROCESSES'], [
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'beast:4:20,sidecar1:4:10',
        'ORBIT_E2E_PARALLEL_PROCESSES' => '8',
    ], function (): void {
        $exitCode = Artisan::call('e2e:test', [
            '--dry-run' => true,
            '--json' => true,
            '--lanes' => 'docker',
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);
        $lane = $payload['success']['data']['lanes'][0];

        expect($exitCode)->toBe(0)
            ->and($lane['environment']['ORBIT_E2E_DOCKER_TEST_RUNNERS'])->toBe('beast:4:20,sidecar1:2:10')
            ->and($lane['environment']['ORBIT_E2E_PARALLEL_PROCESSES'])->toBe('6')
            ->and($lane['command'])->toContain('--processes=6');
    });
});

it('keeps aggregate docker workers off shared incus hosts', function (): void {
    Process::fake(function ($process) {
        if ($process->command === 'command -v docker >/dev/null' || $process->command === 'docker info >/dev/null') {
            return Process::result();
        }

        if (is_array($process->command) && in_array('test', $process->command, true)) {
            return Process::result();
        }

        return Process::result();
    });
    Process::preventStrayProcesses();

    withE2EEnvironment([], [
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'sidecar1:4:28,sidecar2:4:28,Beast:4:28',
        'ORBIT_E2E_INCUS_HOSTS' => 'beast',
        'ORBIT_E2E_INCUS_HOST_VM_CAPS' => 'beast:12',
        'ORBIT_E2E_INCUS_PARALLEL_PROCESSES' => '2',
    ], function (): void {
        $this->artisan('e2e:test --lanes=all')
            ->expectsOutputToContain('E2E Docker runner [beast] ignored: reserved for selected Incus lane')
            ->assertSuccessful();
    });

    Process::assertRan(fn ($process): bool => is_array($process->command)
        && in_array('test', $process->command, true)
        && in_array('--processes=8', $process->command, true)
        && ($process->environment['ORBIT_E2E_TOPOLOGY_PROVIDER'] ?? null) === 'docker'
        && ($process->environment['ORBIT_E2E_DOCKER_TEST_RUNNERS'] ?? null) === 'sidecar1:4:28,sidecar2:4:28'
        && ($process->environment['ORBIT_E2E_PARALLEL_PROCESSES'] ?? null) === '8');
});

it('filters unavailable docker runners before starting Pest workers', function (): void {
    Process::fake(function ($process) {
        if ($process->command === 'command -v docker >/dev/null') {
            return Process::result();
        }

        if ($process->command === 'docker info >/dev/null') {
            return ($process->environment['DOCKER_HOST'] ?? null) === 'ssh://macbook'
                ? Process::result(exitCode: 1, errorOutput: 'offline')
                : Process::result();
        }

        if (is_array($process->command) && in_array('test', $process->command, true)) {
            return Process::result();
        }

        return Process::result();
    });
    Process::preventStrayProcesses();

    withE2EEnvironment([], [
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'sidecar1:4:28,macbook:4:28',
        'ORBIT_E2E_DOCKER_SOURCE_PATH_SIDECAR1' => '/srv/orbit/source',
        'ORBIT_E2E_DOCKER_MIN_PROCESSES' => '4',
    ], function (): void {
        $this->artisan('e2e:test --lanes=docker')
            ->expectsOutputToContain('E2E Docker runner [macbook] ignored: docker daemon is not reachable')
            ->assertSuccessful();
    });

    Process::assertRan(fn ($process): bool => is_array($process->command)
        && in_array('test', $process->command, true)
        && in_array('--processes=4', $process->command, true)
        && ($process->environment['ORBIT_E2E_DOCKER_TEST_RUNNERS'] ?? null) === 'sidecar1:4:28'
        && ($process->environment['ORBIT_E2E_PARALLEL_PROCESSES'] ?? null) === '4');
});

it('fails the docker lane when reachable runner capacity drops below the minimum', function (): void {
    Process::fake(function ($process) {
        if ($process->command === 'command -v docker >/dev/null') {
            return Process::result();
        }

        if ($process->command === 'docker info >/dev/null') {
            return ($process->environment['DOCKER_HOST'] ?? null) === 'ssh://beast'
                ? Process::result()
                : Process::result(exitCode: 1, errorOutput: 'offline');
        }

        return Process::result();
    });
    Process::preventStrayProcesses();

    withE2EEnvironment([], [
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'sidecar1:4:28,sidecar2:4:28,nmbp:4:28,beast:4:28',
    ], function (): void {
        $this->artisan('e2e:test --lanes=docker')
            ->expectsOutputToContain('E2E Docker runner [sidecar1] ignored: docker daemon is not reachable')
            ->expectsOutputToContain('E2E Docker runner [sidecar2] ignored: docker daemon is not reachable')
            ->expectsOutputToContain('E2E Docker runner [nmbp] ignored: docker daemon is not reachable')
            ->expectsOutputToContain('reachable Docker capacity is 4 process(es), below required minimum 8')
            ->assertFailed();
    });

    Process::assertRanTimes(fn ($process): bool => is_array($process->command)
        && in_array('test', $process->command, true), 0);
});

it('allows an explicitly degraded docker capacity run', function (): void {
    Process::fake(function ($process) {
        if ($process->command === 'command -v docker >/dev/null') {
            return Process::result();
        }

        if ($process->command === 'docker info >/dev/null') {
            return ($process->environment['DOCKER_HOST'] ?? null) === 'ssh://beast'
                ? Process::result()
                : Process::result(exitCode: 1, errorOutput: 'offline');
        }

        if (is_array($process->command) && in_array('test', $process->command, true)) {
            return Process::result();
        }

        return Process::result();
    });
    Process::preventStrayProcesses();

    withE2EEnvironment([], [
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'sidecar1:4:28,sidecar2:4:28,nmbp:4:28,beast:4:28',
        'ORBIT_E2E_DOCKER_MIN_PROCESSES' => '4',
    ], function (): void {
        $this->artisan('e2e:test --lanes=docker')
            ->expectsOutputToContain('E2E Docker runner [sidecar1] ignored: docker daemon is not reachable')
            ->expectsOutputToContain('E2E Docker runner [sidecar2] ignored: docker daemon is not reachable')
            ->expectsOutputToContain('E2E Docker runner [nmbp] ignored: docker daemon is not reachable')
            ->assertSuccessful();
    });

    Process::assertRan(fn ($process): bool => is_array($process->command)
        && in_array('test', $process->command, true)
        && in_array('--processes=4', $process->command, true)
        && ($process->environment['ORBIT_E2E_DOCKER_TEST_RUNNERS'] ?? null) === 'beast:4:28'
        && ($process->environment['ORBIT_E2E_PARALLEL_PROCESSES'] ?? null) === '4');
});

it('fails the docker lane when no configured docker runner is reachable', function (): void {
    Process::fake(function ($process) {
        if ($process->command === 'command -v docker >/dev/null') {
            return Process::result();
        }

        if ($process->command === 'docker info >/dev/null') {
            return Process::result(exitCode: 1, errorOutput: 'offline');
        }

        return Process::result();
    });
    Process::preventStrayProcesses();

    withE2EEnvironment([], [
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'macbook:4:28',
    ], function (): void {
        $this->artisan('e2e:test --lanes=docker')
            ->expectsOutputToContain('E2E Docker runner [macbook] ignored: docker daemon is not reachable')
            ->expectsOutputToContain('E2E lane [docker] unavailable: no configured Docker test runner is reachable.')
            ->assertFailed();
    });

    Process::assertRanTimes(fn ($process): bool => is_array($process->command)
        && in_array('test', $process->command, true), 0);
});

it('fails the docker lane cleanly when runner probing times out', function (): void {
    Process::fake(function ($process) {
        if ($process->command === 'command -v docker >/dev/null') {
            return Process::result();
        }

        if ($process->command === 'docker info >/dev/null') {
            throw new RuntimeException('probe timed out');
        }

        return Process::result();
    });
    Process::preventStrayProcesses();

    withE2EEnvironment([], [
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'sidecar1:4:28',
    ], function (): void {
        $this->artisan('e2e:test --lanes=docker')
            ->expectsOutputToContain('E2E Docker runner [sidecar1] ignored: docker daemon is not reachable')
            ->expectsOutputToContain('E2E lane [docker] unavailable: no configured Docker test runner is reachable.')
            ->assertFailed();
    });

    Process::assertRanTimes(fn ($process): bool => is_array($process->command)
        && in_array('test', $process->command, true), 0);
});

it('fails when a required docker prepared image is missing before invoking pest', function (): void {
    Process::fake(function ($process) {
        if ($process->command === 'command -v docker >/dev/null') {
            return Process::result();
        }

        if ($process->command === 'docker info >/dev/null') {
            return Process::result();
        }

        if (is_string($process->command) && str_starts_with($process->command, 'docker image inspect ')) {
            return str_contains($process->command, 'orbit-e2e:operator_base')
                ? Process::result(exitCode: 1, errorOutput: 'missing')
                : Process::result();
        }

        return Process::result();
    });
    Process::preventStrayProcesses();

    $exitCode = null;
    $output = null;

    withE2EEnvironment([], [
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'sidecar1:4:28',
        'ORBIT_E2E_DOCKER_SOURCE_PATH_SIDECAR1' => '/srv/orbit/source',
    ], function () use (&$exitCode, &$output): void {
        $exitCode = Artisan::call('e2e:test', ['--lanes' => 'docker']);
        $output = Artisan::output();
    });

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('E2E lane [docker] unavailable:')
        ->and($output)->toContain('docker prepared image')
        ->and($output)->toContain('is not available')
        ->and($output)->toContain('composer e2e:ensure-artifacts -- --lanes=docker --roles=operator --force operator_gateway_app-dev_app-prod_agent');

    Process::assertRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, 'docker image inspect')
        && str_contains($process->command, 'orbit-e2e:operator_base'));
    Process::assertRanTimes(fn ($process): bool => is_array($process->command)
        && in_array('test', $process->command, true), 0);
});

it('checks docker prepared artifacts before invoking explicit test paths', function (): void {
    Process::fake(function ($process) {
        if ($process->command === 'command -v docker >/dev/null') {
            return Process::result();
        }

        if ($process->command === 'docker info >/dev/null') {
            return Process::result();
        }

        if (is_string($process->command) && str_starts_with($process->command, 'docker image inspect ')) {
            return str_contains($process->command, 'orbit-e2e:app-dev_base')
                ? Process::result(exitCode: 1, errorOutput: 'missing')
                : Process::result();
        }

        return Process::result();
    });
    Process::preventStrayProcesses();

    $exitCode = null;
    $output = null;

    withE2EEnvironment([], [
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'sidecar1:4:28',
        'ORBIT_E2E_DOCKER_SOURCE_PATH_SIDECAR1' => '/srv/orbit/source',
    ], function () use (&$exitCode, &$output): void {
        $exitCode = Artisan::call('e2e:test --lanes=docker');
        $output = Artisan::output();
    });

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('E2E lane [docker] unavailable:')
        ->and($output)->toContain('docker prepared image')
        ->and($output)->toContain('orbit-e2e:app-dev_base')
        ->and($output)->toContain('composer e2e:ensure-artifacts -- --lanes=docker --roles=app-dev --force');

    Process::assertRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, 'docker image inspect')
        && str_contains($process->command, 'orbit-e2e:app-dev_base'));
    Process::assertRanTimes(fn ($process): bool => is_array($process->command)
        && in_array('test', $process->command, true), 0);
});

it('suggests the runtime artifact command when docker support images are missing', function (): void {
    Process::fake(function ($process) {
        if ($process->command === 'command -v docker >/dev/null') {
            return Process::result();
        }

        if ($process->command === 'docker info >/dev/null') {
            return Process::result();
        }

        if (is_string($process->command) && str_contains($process->command, "docker image inspect 'caddy:2-alpine'")) {
            return Process::result(exitCode: 1, errorOutput: 'missing');
        }

        if (is_string($process->command) && str_starts_with($process->command, 'docker image inspect ')) {
            return Process::result();
        }

        return Process::result();
    });
    Process::preventStrayProcesses();

    $exitCode = null;
    $output = null;

    withE2EEnvironment([], [
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'sidecar1:4:28',
        'ORBIT_E2E_DOCKER_SOURCE_PATH_SIDECAR1' => '/srv/orbit/source',
    ], function () use (&$exitCode, &$output): void {
        $exitCode = Artisan::call('e2e:test', ['--lanes' => 'docker']);
        $output = Artisan::output();
    });

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('Docker orbit-caddy image caddy:2-alpine is not available')
        ->and($output)->toContain('composer e2e:ensure-artifacts -- --lanes=docker --runtime --force operator_gateway_app-dev_app-prod_agent');

    Process::assertRanTimes(fn ($process): bool => is_array($process->command)
        && in_array('test', $process->command, true), 0);
});

it('caps docker canary workers when selected topology capacity exceeds sidecar capacity', function (): void {
    withE2EEnvironment(['ORBIT_E2E_PARALLEL_PROCESSES'], [
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'sidecar1:4:20,sidecar2:4:20',
        'ORBIT_E2E_PARALLEL_PROCESSES' => '8',
    ], function (): void {
        $exitCode = Artisan::call('e2e:test', [
            '--canary' => true,
            '--dry-run' => true,
            '--json' => true,
            '--lanes' => 'docker',
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);
        $lane = $payload['success']['data']['lanes'][0];

        expect($exitCode)->toBe(0)
            ->and($lane['environment']['ORBIT_E2E_DOCKER_TEST_RUNNERS'])->toBe('sidecar1:4:20,sidecar2:4:20')
            ->and($lane['environment']['ORBIT_E2E_PARALLEL_PROCESSES'])->toBe('8')
            ->and($lane['command'])->toContain('--processes=8');
    });
});

it('normalizes explicit docker e2e paths and runs them sequentially when requested', function (): void {
    withE2EEnvironment([], [
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'sidecar1:4:28,sidecar2:4:28',
    ], function (): void {
        $exitCode = Artisan::call('e2e:test --dry-run --json --lanes=docker --sequential-tests tests/Feature/Commands/IngressProductionTopologyTest.php');
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);
        $command = $payload['success']['data']['lanes'][0]['command'];

        expect($exitCode)->toBe(0)
            ->and($command)->toContain('tests/Feature/Commands/IngressProductionTopologyTest.php')
            ->and($command)->not->toContain('--parallel')
            ->and($command)->not->toContain('--processes=8');
    });
});

it('documents explicit docker container caps for every configured host', function (): void {
    $example = collect(file(repo_path('.env.e2e.example'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [])
        ->reject(fn (string $line): bool => str_starts_with(trim($line), '#'))
        ->mapWithKeys(function (string $line): array {
            [$key, $value] = explode('=', $line, 2);

            return [$key => $value];
        })
        ->all();

    expect($example['ORBIT_E2E_DOCKER_TEST_RUNNERS'])->toBe('sidecar1:4:28,sidecar2:4:28,beast:4:28')
        ->and($example)->not->toHaveKey('ORBIT_E2E_PARALLEL_PROCESSES')
        ->and($example)->not->toHaveKeys([
            'ORBIT_E2E_DOCKER_HOSTS',
            'ORBIT_E2E_DOCKER_HOST_SLOTS',
            'ORBIT_E2E_DOCKER_HOST_CONTAINER_CAPS',
        ]);
});

it('disables pest parallel mode for a single docker process', function (): void {
    withE2EEnvironment([], [
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'sidecar1:4:28,sidecar2:4:28',
        'ORBIT_E2E_PARALLEL_PROCESSES' => '1',
    ], function (): void {
        $this->artisan('e2e:test --dry-run --json --lanes=docker')
            ->doesntExpectOutputToContain('"--parallel"')
            ->doesntExpectOutputToContain('"--processes=1"')
            ->assertSuccessful();
    });
});

it('limits docker parallel runs to docker eligible e2e files', function (): void {
    withE2EEnvironment([], [
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'sidecar1:4:28,sidecar2:4:28',
    ], function () use (&$exitCode, &$lane, &$generatedPath): void {
        $exitCode = Artisan::call('e2e:test', [
            '--dry-run' => true,
            '--json' => true,
            '--lanes' => 'docker',
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);
        $lane = $payload['success']['data']['lanes'][0];
        $generatedPath = firstGeneratedDockerPath($lane['command']);
    });

    expect($exitCode)->toBe(0)
        ->and($generatedPath)->toStartWith('tests/Feature/Commands/.docker-feature-tests/run_')
        ->and($lane['test_files'])->toContain('tests/Feature/Commands/IngressProductionTopologyTest.php')
        ->and($lane['test_files'])->toContain('tests/Feature/Commands/ToolCredentialsTest.php')
        ->and($lane['test_files'])->toContain('tests/Feature/Commands/UpdateAllDurableOperationTest.php')
        ->and($lane['test_files'])->not->toContain('tests/Feature/Commands/ToolLifecycleHostInitTest.php')
        ->and($lane['test_files'])->not->toContain('tests/Feature/Commands/RuntimeBackendHostInitTest.php');
});

it('does not select Docker E2E files with direct host PHP topology commands', function (): void {
    withE2EEnvironment([], [
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'sidecar1:4:28,sidecar2:4:28',
    ], function () use (&$exitCode, &$files): void {
        $exitCode = Artisan::call('e2e:test', [
            '--dry-run' => true,
            '--json' => true,
            '--lanes' => 'docker',
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);
        $lane = $payload['success']['data']['lanes'][0];
        $files = [
            ...$lane['test_files'],
            'tests/Feature/Commands/Support/Pest.php',
        ];
    });

    $patterns = [
        'php artisan',
        'php -S',
        'nohup php',
        'exec php',
        '/timeout\s+\S+\s+php/',
    ];

    $violations = collect($files)
        ->flatMap(function (string $file) use ($patterns): array {
            $contents = file_get_contents(base_path($file)) ?: '';

            return collect($patterns)
                ->filter(fn (string $pattern): bool => str_starts_with($pattern, '/')
                    ? preg_match($pattern, $contents) === 1
                    : str_contains($contents, $pattern))
                ->map(fn (string $pattern): string => "{$file}:{$pattern}")
                ->all();
        })
        ->values()
        ->all();

    expect($exitCode)->toBe(0)
        ->and($violations)->toBe([]);
});

it('does not select direct provisioning tests for prepared topology lanes', function (): void {
    withE2EEnvironment(['ORBIT_E2E_INCUS_PARALLEL_PROCESSES'], [
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'sidecar1:4:28,sidecar2:4:28',
        'ORBIT_E2E_INCUS_HOST_VM_CAPS' => 'beast:12',
        'ORBIT_E2E_INCUS_PARALLEL_PROCESSES' => '3',
    ], function () use (&$exitCode, &$files): void {
        $exitCode = Artisan::call('e2e:test', [
            '--dry-run' => true,
            '--json' => true,
            '--lanes' => 'all',
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);
        $files = collect($payload['success']['data']['lanes'])
            ->flatMap(fn (array $lane): array => $lane['test_files'] ?? [])
            ->unique()
            ->values()
            ->all();
    });

    $patterns = [
        'launchBase(',
        'pushBundle(',
        'provisionInstance(',
        'e2e-provision-node',
        'orbit-source.tar.gz',
        '--source-archive',
    ];

    $violations = collect($files)
        ->flatMap(function (string $file) use ($patterns): array {
            $contents = file_get_contents(base_path($file)) ?: '';

            return collect($patterns)
                ->filter(fn (string $pattern): bool => str_contains($contents, $pattern))
                ->map(fn (string $pattern): string => "{$file}:{$pattern}")
                ->all();
        })
        ->values()
        ->all();

    expect($exitCode)->toBe(0)
        ->and($violations)->toBe([]);
});

it('includes the agent topology coverage in the incus lane', function (): void {
    withE2EEnvironment(['ORBIT_E2E_INCUS_PARALLEL_PROCESSES'], [
        'ORBIT_E2E_INCUS_HOST_VM_CAPS' => 'beast:12',
        'ORBIT_E2E_INCUS_PARALLEL_PROCESSES' => '3',
        'ORBIT_E2E_INCUS_HOST_SLOTS' => 'beast:1',
    ], function (): void {
        $exitCode = Artisan::call('e2e:test', [
            '--dry-run' => true,
            '--json' => true,
            '--lanes' => 'incus',
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);
        $lane = $payload['success']['data']['lanes'][0];

        expect($exitCode)->toBe(0)
            ->and($lane['test_files'])->toContain('tests/Feature/Commands/NodeListAgentTopologyTest.php')
            ->and($lane['environment']['ORBIT_E2E_TOPOLOGY_CACHE'])->toBe('process')
            ->and($lane['environment']['ORBIT_E2E_CHECKOUT_CACHE'])->toBe('process')
            ->and($lane['environment']['ORBIT_E2E_INCUS_HOST_SLOTS'])->toBe('')
            ->and($lane['command'])->toContain('--processes=3');
    });
});

it('uses the requested Incus worker count without topology-size capping', function (): void {
    withE2EEnvironment(['ORBIT_E2E_INCUS_PARALLEL_PROCESSES'], [
        'ORBIT_E2E_INCUS_HOST_VM_CAPS' => 'beast:12',
        'ORBIT_E2E_INCUS_PARALLEL_PROCESSES' => '8',
    ], function (): void {
        $exitCode = Artisan::call('e2e:test', [
            '--dry-run' => true,
            '--json' => true,
            '--lanes' => 'incus',
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);
        $lane = $payload['success']['data']['lanes'][0];

        expect($exitCode)->toBe(0)
            ->and($lane['command'])->toContain('--processes=8');
    });
});

it('requires Incus VM caps before planning Incus lanes', function (): void {
    withE2EEnvironment(['ORBIT_E2E_INCUS_PARALLEL_PROCESSES'], [
        'ORBIT_E2E_INCUS_PARALLEL_PROCESSES' => '2',
    ], function (): void {
        $exitCode = Artisan::call('e2e:test --dry-run --json --lanes=incus tests/Feature/Commands/NodeListAgentTopologyTest.php');
        $output = Artisan::output();

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('Missing Incus VM cap for host [beast]. Set ORBIT_E2E_INCUS_HOST_VM_CAPS.');
    });
});

it('fails Incus lane planning when warm snapshots are requested but missing', function (): void {
    Process::fake(fn () => Process::result(exitCode: 1));

    withE2EEnvironment([
        'ORBIT_E2E_INCUS_PARALLEL_PROCESSES',
    ], [
        'ORBIT_E2E_INCUS_WARM_SNAPSHOTS' => '1',
        'ORBIT_E2E_INCUS_HOST_VM_CAPS' => 'beast:12',
        'ORBIT_E2E_INCUS_PARALLEL_PROCESSES' => '2',
    ], function (): void {
        $exitCode = Artisan::call('e2e:test --dry-run --json --lanes=incus tests/Feature/Commands/NodeListAgentTopologyTest.php');
        $output = Artisan::output();

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('Incus warm prepared topology [operator_gateway_agent] is missing')
            ->and($output)->toContain('composer e2e:prepare-warm-topology -- --force operator_gateway_agent');
    });
});

it('balances test file ordering across topology sizes', function (): void {
    withE2EEnvironment([], [], function (): void {
        $files = createTopologySchedulingFixtureFiles([
            '01-operator.php' => 'e2e-feature-operator',
            '02-gateway.php' => 'e2e-feature-operator_gateway',
            '03-dev-a.php' => 'e2e-feature-operator_gateway_app-dev',
            '04-dev-b.php' => 'e2e-feature-operator_gateway_app-dev',
            '05-prod.php' => 'e2e-feature-operator_gateway_app-dev_app-prod',
            '06-full.php' => 'e2e-feature-operator_gateway_app-dev_app-prod_agent',
        ]);

        try {
            foreach (['docker', 'incus'] as $provider) {
                $ordered = invokeE2ETestCommandMethod(app(E2ETestCommand::class), 'topologyBalancedTestFiles', [$files, $provider]);

                expect($ordered)->toBe([
                    $files['06-full.php'],
                    $files['05-prod.php'],
                    $files['03-dev-a.php'],
                    $files['02-gateway.php'],
                    $files['01-operator.php'],
                    $files['04-dev-b.php'],
                ]);
            }
        } finally {
            removeTopologySchedulingFixtureFiles($files);
        }
    });
});

it('uses a unique generated docker test directory per plan', function (): void {
    withE2EEnvironment([], [
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'sidecar1:4:28,sidecar2:4:28',
    ], function () use (&$exitCode, &$first, &$second): void {
        $exitCode = Artisan::call('e2e:test', [
            '--dry-run' => true,
            '--json' => true,
            '--lanes' => 'docker',
        ]);
        $first = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        $exitCode = max($exitCode, Artisan::call('e2e:test', [
            '--dry-run' => true,
            '--json' => true,
            '--lanes' => 'docker',
        ]));
        $second = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);
    });

    $firstPath = firstGeneratedDockerPath($first['success']['data']['lanes'][0]['command']);
    $secondPath = firstGeneratedDockerPath($second['success']['data']['lanes'][0]['command']);

    expect($exitCode)->toBe(0)
        ->and($firstPath)->toStartWith('tests/Feature/Commands/.docker-feature-tests/run_')
        ->and($secondPath)->toStartWith('tests/Feature/Commands/.docker-feature-tests/run_')
        ->and($firstPath)->not->toBe($secondPath);
});

it('does not include generated docker test files in future docker plans', function (): void {
    $directory = repo_path('apps/e2e/tests/Feature/Commands/.docker-feature-tests');

    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }

    file_put_contents($directory.'/Docker999GeneratedTest.php', <<<'PHP'
<?php

it('is generated')->group('e2e-feature');
PHP);

    try {
        withE2EEnvironment([], [
            'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'sidecar1:4:28,sidecar2:4:28',
        ], function () use (&$exitCode, &$lane): void {
            $exitCode = Artisan::call('e2e:test', [
                '--dry-run' => true,
                '--json' => true,
                '--lanes' => 'docker',
            ]);
            $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);
            $lane = $payload['success']['data']['lanes'][0];
        });

        expect($exitCode)->toBe(0)
            ->and($lane['test_files'])->not->toContain('tests/Feature/Commands/.docker-feature-tests/Docker999GeneratedTest.php');
    } finally {
        @unlink($directory.'/Docker999GeneratedTest.php');
        @rmdir($directory);
    }
});

it('copies e2e support files into generated docker test suites', function (): void {
    $command = app(E2ETestCommand::class);
    $testPath = 'tests/Feature/Commands/.docker-feature-tests/run_support_'.bin2hex(random_bytes(4));
    $plans = [
        'docker' => [
            'lane' => 'docker',
            'command' => ['php', 'artisan', 'test'],
            'environment' => [
                'ORBIT_E2E' => '1',
            ],
            'test_path' => $testPath,
            'test_files' => [
                'tests/Feature/Commands/NodeListAgentTopologyTest.php',
            ],
        ],
    ];

    try {
        invokeE2ETestCommandMethod($command, 'preparePlanArtifacts', [&$plans]);

        expect(is_file(base_path($testPath.'/Docker000NodeListAgentTopologyTest.php')))->toBeTrue()
            ->and(is_file(base_path($testPath.'/Support/SqliteDatabaseFixture.php')))->toBeTrue();
    } finally {
        invokeE2ETestCommandMethod($command, 'cleanupPlanArtifacts', [$plans]);
    }
});

it('rejects unsupported lanes', function (): void {
    $this->artisan('e2e:test --dry-run --lanes=redis')
        ->expectsOutputToContain('Unsupported E2E lane(s): redis. Supported lanes: docker, incus.')
        ->assertFailed();
});

it('fails before invoking pest when required e2e runtime dependencies are missing', function (): void {
    withE2EEnvironment([], [
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'sidecar1:4:28',
    ], function (): void {
        Process::fake(['*' => Process::result()]);
        Process::preventStrayProcesses();

        $command = app(E2ETestCommand::class);
        $command->setRequiredRuntimeDependencyClasses([
            'Orbit\\Core\\MissingRuntimeDependency',
        ]);
        $this->app->instance(E2ETestCommand::class, $command);

        $exitCode = Artisan::call('e2e:test', ['--lanes' => 'docker']);
        $output = Artisan::output();

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('E2E runtime dependencies are missing or Composer autoload is stale')
            ->and($output)->toContain('Orbit\\Core\\MissingRuntimeDependency')
            ->and($output)->toContain('cd apps/e2e && composer install');

        Process::assertRanTimes(fn ($process): bool => is_array($process->command)
            && in_array('test', $process->command, true), 0);
    });
});

it('fails unavailable incus lanes before invoking pest', function (): void {
    withE2EEnvironment([], [
        'ORBIT_E2E_INCUS_HOST_VM_CAPS' => 'beast:12',
    ], function (): void {
        Process::fake(['*' => Process::result()]);
        Process::preventStrayProcesses();

        $command = app(E2ETestCommand::class);
        $command->setLaneAvailabilityResolver(fn (): array => [
            'incus' => 'incus: prepared topology operator_gateway is not available on any Incus host',
        ]);
        $this->app->instance(E2ETestCommand::class, $command);

        $exitCode = Artisan::call('e2e:test', ['--lanes' => 'incus']);
        $output = Artisan::output();

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('E2E lane [incus] unavailable: incus: prepared topology operator_gateway is not available on any Incus host')
            ->and($output)->toContain('composer e2e:prepare-topology -- --force operator_gateway_app-dev_app-prod_agent');

        Process::assertRanTimes(fn ($process): bool => is_array($process->command)
            && in_array('test', $process->command, true), 0);
    });
});

it('fails the aggregate run when any selected lane is unavailable', function (): void {
    withE2EEnvironment([], [
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'sidecar1:4:28',
        'ORBIT_E2E_INCUS_HOST_VM_CAPS' => 'beast:12',
    ], function (): void {
        Process::fake(['*' => Process::result()]);
        Process::preventStrayProcesses();

        $command = app(E2ETestCommand::class);
        $command->setLaneAvailabilityResolver(fn (): array => [
            'incus' => 'incus: prepared topology operator_gateway_agent is not available on any Incus host',
        ]);
        $this->app->instance(E2ETestCommand::class, $command);

        $exitCode = Artisan::call('e2e:test', ['--lanes' => 'all']);
        $output = Artisan::output();

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('E2E lane [incus] unavailable: incus: prepared topology operator_gateway_agent is not available on any Incus host')
            ->and($output)->toContain('composer e2e:prepare-topology -- --force operator_gateway_app-dev_app-prod_agent');

        Process::assertRanTimes(fn ($process): bool => is_array($process->command)
            && in_array('test', $process->command, true), 0);
    });
});

it('passes explicit incus test paths into artifact availability checks', function (): void {
    Process::fake();
    Process::preventStrayProcesses();

    $seenTestFiles = null;
    $command = app(E2ETestCommand::class);
    $command->setLaneAvailabilityResolver(function (array $plans) use (&$seenTestFiles): array {
        $seenTestFiles = $plans['incus']['test_files'] ?? [];

        return [
            'incus' => 'incus: prepared topology operator_gateway_agent is not available on any Incus host',
        ];
    });
    $this->app->instance(E2ETestCommand::class, $command);

    withE2EEnvironment([], [
        'ORBIT_E2E_INCUS_HOST_VM_CAPS' => 'beast:12',
    ], function () use (&$exitCode, &$output): void {
        $exitCode = Artisan::call('e2e:test --lanes=incus tests/Feature/Commands/NodeListAgentTopologyTest.php');
        $output = Artisan::output();
    });

    expect($seenTestFiles)->toBe(['tests/Feature/Commands/NodeListAgentTopologyTest.php'])
        ->and($exitCode)->toBe(1)
        ->and($output)->toContain('E2E lane [incus] unavailable:')
        ->and($output)->toContain('composer e2e:prepare-topology -- --force operator_gateway_app-dev_app-prod_agent');

    Process::assertRanTimes(fn ($process): bool => is_array($process->command)
        && in_array('test', $process->command, true), 0);
});

it('allocates a timings file env for timed runs and removes it during cleanup', function (): void {
    $previous = getenv('ORBIT_E2E_TIMINGS');
    putenv('ORBIT_E2E_TIMINGS=1');

    try {
        $command = app(E2ETestCommand::class);
        $reflection = new ReflectionClass($command);
        $prepare = $reflection->getMethod('preparePlanArtifacts');
        $cleanup = $reflection->getMethod('cleanupPlanArtifacts');

        $plans = [
            'docker' => [
                'lane' => 'docker',
                'command' => ['php', 'artisan', 'test'],
                'environment' => [
                    'ORBIT_E2E' => '1',
                ],
            ],
        ];

        $prepare->invokeArgs($command, [&$plans]);

        $timingsFile = $plans['docker']['timings_file'] ?? null;

        expect($timingsFile)->toBeString()
            ->and($plans['docker']['environment']['ORBIT_E2E_TIMINGS_FILE'] ?? null)->toBe($timingsFile)
            ->and(is_file($timingsFile))->toBeTrue();

        file_put_contents($timingsFile, "[orbit-e2e] checkout.worker checkout.reset 0.111s\n", FILE_APPEND | LOCK_EX);

        $cleanup->invoke($command, $plans);

        expect(is_file($timingsFile))->toBeFalse();
    } finally {
        $previous === false
            ? putenv('ORBIT_E2E_TIMINGS')
            : putenv("ORBIT_E2E_TIMINGS={$previous}");
    }
});

it('replays a timings file to the command error output', function (): void {
    $timingsFile = tempnam(sys_get_temp_dir(), 'orbit-e2e-replay-');
    file_put_contents($timingsFile, "[orbit-e2e] checkout.worker checkout.reset 0.111s\n");

    $command = app(E2ETestCommand::class);
    $reflection = new ReflectionClass($command);
    $outputProperty = $reflection->getProperty('output');
    $outputProperty->setAccessible(true);

    $output = new class extends BufferedOutput implements ConsoleOutputInterface
    {
        public BufferedOutput $errorOutput;

        public function __construct()
        {
            parent::__construct();
            $this->errorOutput = new BufferedOutput;
        }

        public function getErrorOutput(): BufferedOutput
        {
            return $this->errorOutput;
        }

        public function setErrorOutput(OutputInterface $error): void
        {
            if ($error instanceof BufferedOutput) {
                $this->errorOutput = $error;
            }
        }

        public function section(): never
        {
            throw new RuntimeException('Not used in this test.');
        }
    };
    $outputProperty->setValue($command, $output);

    $method = $reflection->getMethod('replayTimingsFile');
    $method->invoke($command, [
        'lane' => 'docker',
        'command' => ['php', 'artisan', 'test'],
        'environment' => [],
        'timings_file' => $timingsFile,
    ]);

    expect($output->errorOutput->fetch())->toContain('[orbit-e2e] checkout.worker checkout.reset 0.111s');

    @unlink($timingsFile);
});

it('cleans up partial artifacts when preparation fails before parallel lanes start', function (): void {
    $previous = getenv('ORBIT_E2E_TIMINGS');
    putenv('ORBIT_E2E_TIMINGS=1');

    try {
        $command = app(E2ETestCommand::class);
        setE2ETestCommandInput($command, []);

        $plans = failingPreparationPlans();

        expect(function () use ($command, &$plans): void {
            invokeE2ETestCommandMethod($command, 'runPlans', [&$plans]);
        })->toThrow(ErrorException::class, 'DefinitelyMissingTest.php');

        assertNoLeakedPlanArtifacts($plans);
    } finally {
        $previous === false
            ? putenv('ORBIT_E2E_TIMINGS')
            : putenv("ORBIT_E2E_TIMINGS={$previous}");
    }
});

it('cleans up partial artifacts when preparation fails before sequential lanes start', function (): void {
    $previous = getenv('ORBIT_E2E_TIMINGS');
    putenv('ORBIT_E2E_TIMINGS=1');

    try {
        $command = app(E2ETestCommand::class);
        setE2ETestCommandInput($command, ['--sequential-lanes' => true]);

        $plans = failingPreparationPlans();

        expect(function () use ($command, &$plans): void {
            invokeE2ETestCommandMethod($command, 'runPlansSequentially', [&$plans]);
        })->toThrow(ErrorException::class, 'DefinitelyMissingTest.php');

        assertNoLeakedPlanArtifacts($plans);
    } finally {
        $previous === false
            ? putenv('ORBIT_E2E_TIMINGS')
            : putenv("ORBIT_E2E_TIMINGS={$previous}");
    }
});

it('reaps active docker and incus resources during interrupt cleanup', function (): void {
    Process::fake(['*' => Process::result()]);
    Process::preventStrayProcesses();

    runE2EInterruptReapers([
        'docker' => [
            'lane' => 'docker',
            'command' => ['php', 'artisan', 'test'],
            'environment' => [],
        ],
        'incus' => [
            'lane' => 'incus',
            'command' => ['php', 'artisan', 'test'],
            'environment' => [],
        ],
    ]);

    Process::assertRan(fn ($process): bool => $process->path === base_path()
        && $process->command === ['php', 'artisan', 'e2e:reap-docker', '--force', '--older-than=0m']);
    Process::assertRan(fn ($process): bool => $process->path === base_path()
        && $process->command === ['php', 'artisan', 'e2e:reap-incus', '--force', '--older-than=0m']);
});

it('passes active lane runtime isolation to interrupt cleanup reapers', function (): void {
    Process::fake(['*' => Process::result()]);
    Process::preventStrayProcesses();

    runE2EInterruptReapers([
        'docker' => [
            'lane' => 'docker',
            'command' => ['php', 'artisan', 'test'],
            'environment' => [
                'ORBIT_E2E_INSTANCE_PREFIX' => 'orbit-e2e-branch-a',
                'ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE' => 'branch-a',
            ],
        ],
    ]);

    Process::assertRan(fn ($process): bool => $process->command === ['php', 'artisan', 'e2e:reap-docker', '--force', '--older-than=0m']
        && ($process->environment['ORBIT_E2E_INSTANCE_PREFIX'] ?? null) === 'orbit-e2e-branch-a'
        && ($process->environment['ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE'] ?? null) === 'branch-a');
});

it('only runs reapers for selected interrupt lanes', function (): void {
    Process::fake(['*' => Process::result()]);
    Process::preventStrayProcesses();

    runE2EInterruptReapers([
        'docker' => [
            'lane' => 'docker',
            'command' => ['php', 'artisan', 'test'],
            'environment' => [],
        ],
    ]);

    Process::assertRan(fn ($process): bool => $process->command === ['php', 'artisan', 'e2e:reap-docker', '--force', '--older-than=0m']);
    Process::assertRanTimes(fn ($process): bool => $process->command === ['php', 'artisan', 'e2e:reap-incus', '--force', '--older-than=0m'], 0);
});

/**
 * @param  array<string, array{lane: string, command: list<string>, environment: array<string, string>}>  $plans
 */
function runE2EInterruptReapers(array $plans): void
{
    $command = app(E2ETestCommand::class);
    $reflection = new ReflectionClass($command);

    $activePlans = $reflection->getProperty('activePlans');
    $activePlans->setValue($command, $plans);

    $runInterruptReapers = $reflection->getMethod('runInterruptReapers');
    $runInterruptReapers->invoke($command);
}

/**
 * @param  list<string>  $command
 */
function firstGeneratedDockerPath(array $command): ?string
{
    foreach ($command as $argument) {
        if (str_starts_with($argument, 'tests/Feature/Commands/.docker-feature-tests/run_')) {
            return $argument;
        }
    }

    return null;
}

/**
 * @return array<string, array{lane: string, command: list<string>, environment: array<string, string>, test_path: string, test_files: list<string>, timings_file?: string}>
 */
function failingPreparationPlans(): array
{
    $testPath = 'tests/Feature/Commands/.docker-feature-tests/run_failure_'.bin2hex(random_bytes(4));

    return [
        'docker' => [
            'lane' => 'docker',
            'command' => ['php', 'artisan', 'test'],
            'environment' => [
                'ORBIT_E2E' => '1',
            ],
            'test_path' => $testPath,
            'test_files' => [
                'tests/Feature/Commands/ToolCredentialsTest.php',
                'tests/Feature/Commands/DefinitelyMissingTest.php',
            ],
        ],
    ];
}

/**
 * @param  array<string, array{lane: string, command: list<string>, environment: array<string, string>, test_path?: string, test_files?: list<string>, timings_file?: string}>  $plans
 */
function assertNoLeakedPlanArtifacts(array $plans): void
{
    $plan = $plans['docker'];
    $testPath = $plan['test_path'] ?? null;
    $timingsFile = $plan['timings_file'] ?? null;

    expect($testPath)->toBeString()
        ->and(is_dir(base_path($testPath)))->toBeFalse()
        ->and(is_dir(dirname(base_path($testPath))))->toBeFalse();

    if (is_string($timingsFile)) {
        expect(file_exists($timingsFile))->toBeFalse();
    }
}

/**
 * @param  array<int|string, mixed>  $arguments
 */
function setE2ETestCommandInput(E2ETestCommand $command, array $arguments): void
{
    $reflection = new ReflectionClass($command);
    $input = $reflection->getProperty('input');
    $input->setAccessible(true);
    $input->setValue($command, new ArrayInput($arguments, $command->getDefinition()));

    $output = $reflection->getProperty('output');
    $output->setAccessible(true);
    $output->setValue($command, new BufferedOutput);
}

/**
 * @param  list<mixed>  $arguments
 */
function invokeE2ETestCommandMethod(E2ETestCommand $command, string $method, array $arguments = []): mixed
{
    $reflection = new ReflectionClass($command);
    $target = $reflection->getMethod($method);

    return $target->invokeArgs($command, $arguments);
}

/**
 * @param  array<string, string>  $groups
 * @return array<string, string>
 */
function createTopologySchedulingFixtureFiles(array $groups): array
{
    $directory = repo_path('apps/e2e/tests/Feature/Commands/.topology-scheduling-fixtures/'.bin2hex(random_bytes(4)));

    if (! mkdir($directory, 0777, true) && ! is_dir($directory)) {
        throw new RuntimeException("Could not create fixture directory [{$directory}].");
    }

    $files = [];

    foreach ($groups as $file => $group) {
        $path = "{$directory}/{$file}";
        file_put_contents($path, <<<PHP
<?php

it('is scheduled')->group('e2e-feature', '{$group}');
PHP);

        $files[$file] = str_replace(base_path().'/', '', $path);
    }

    return $files;
}

/**
 * @param  array<string, string>  $files
 */
function removeTopologySchedulingFixtureFiles(array $files): void
{
    foreach ($files as $file) {
        @unlink(base_path($file));
    }

    $directories = array_unique(array_map(
        fn (string $file): string => dirname(base_path($file)),
        $files,
    ));

    foreach ($directories as $directory) {
        @rmdir($directory);
        @rmdir(dirname($directory));
    }
}
