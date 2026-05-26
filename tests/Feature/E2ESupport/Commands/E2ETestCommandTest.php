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
                    ->environment->ORBIT_E2E_TOPOLOGY_CACHE_LIMIT->toBe('1'),
                fn ($lane) => $lane
                    ->lane->toBe('incus')
                    ->environment->ORBIT_E2E_TOPOLOGY_PROVIDER->toBe('incus')
                    ->environment->ORBIT_E2E_TOPOLOGY_PROVIDERS->toBe('incus')
                    ->environment->ORBIT_E2E_TOPOLOGY_CACHE_LIMIT->toBe('1'),
            );
    });
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
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'beast:4:20,sidecar1:4:4',
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

it('skips the docker lane when no configured docker runner is reachable', function (): void {
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
            ->expectsOutputToContain('E2E lane [docker] skipped: no configured Docker test runner is reachable.')
            ->assertSuccessful();
    });

    Process::assertRanTimes(fn ($process): bool => is_array($process->command)
        && in_array('test', $process->command, true), 0);
});

it('keeps eight docker canary workers when the sidecars allow twenty containers', function (): void {
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

it('runs explicit docker e2e paths sequentially when requested', function (): void {
    withE2EEnvironment([], [
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'sidecar1:4:28,sidecar2:4:28',
    ], function (): void {
        $exitCode = Artisan::call('e2e:test --dry-run --json --lanes=docker --sequential-tests tests/E2E/AppListTest.php');
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);
        $command = $payload['success']['data']['lanes'][0]['command'];

        expect($exitCode)->toBe(0)
            ->and($command)->toContain('tests/E2E/AppListTest.php')
            ->and($command)->not->toContain('--parallel')
            ->and($command)->not->toContain('--processes=8');
    });
});

it('documents explicit docker container caps for every configured host', function (): void {
    $example = collect(file(base_path('.env.e2e.example'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [])
        ->reject(fn (string $line): bool => str_starts_with(trim($line), '#'))
        ->mapWithKeys(function (string $line): array {
            [$key, $value] = explode('=', $line, 2);

            return [$key => $value];
        })
        ->all();

    expect($example['ORBIT_E2E_DOCKER_TEST_RUNNERS'])->toBe('sidecar1:4:28,sidecar2:4:28')
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
        ->and($generatedPath)->toStartWith('tests/E2E/.docker-feature-tests/run_')
        ->and($lane['test_files'])->toContain('tests/E2E/IngressProductionTopologyTest.php')
        ->and($lane['test_files'])->toContain('tests/E2E/ToolCredentialsTest.php')
        ->and($lane['test_files'])->not->toContain('tests/E2E/ToolStartTest.php')
        ->and($lane['test_files'])->not->toContain('tests/E2E/RuntimeBackendHostInitTest.php');
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
            'tests/E2E/Support/Pest.php',
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

it('includes the agent topology coverage in the incus lane', function (): void {
    withE2EEnvironment(['ORBIT_E2E_INCUS_PARALLEL_PROCESSES'], [
        'ORBIT_E2E_INCUS_HOST_VM_CAPS' => 'beast:12',
        'ORBIT_E2E_INCUS_PARALLEL_PROCESSES' => '3',
    ], function (): void {
        $exitCode = Artisan::call('e2e:test', [
            '--dry-run' => true,
            '--json' => true,
            '--lanes' => 'incus',
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);
        $lane = $payload['success']['data']['lanes'][0];

        expect($exitCode)->toBe(0)
            ->and($lane['test_files'])->toContain('tests/E2E/NodeListAgentTopologyTest.php')
            ->and($lane['environment']['ORBIT_E2E_TOPOLOGY_CACHE'])->toBe('process')
            ->and($lane['environment']['ORBIT_E2E_CHECKOUT_CACHE'])->toBe('process')
            ->and($lane['command'])->toContain('--processes=3');
    });
});

it('sizes Incus workers from the largest selected topology', function (): void {
    withE2EEnvironment(['ORBIT_E2E_INCUS_PARALLEL_PROCESSES'], [
        'ORBIT_E2E_INCUS_HOST_VM_CAPS' => 'beast:12',
        'ORBIT_E2E_INCUS_PARALLEL_PROCESSES' => '4',
    ], function (): void {
        $exitCode = Artisan::call('e2e:test', [
            '--dry-run' => true,
            '--json' => true,
            '--lanes' => 'incus',
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);
        $lane = $payload['success']['data']['lanes'][0];

        expect($exitCode)->toBe(0)
            ->and($lane['command'])->toContain('--processes=4');
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
        ->and($firstPath)->toStartWith('tests/E2E/.docker-feature-tests/run_')
        ->and($secondPath)->toStartWith('tests/E2E/.docker-feature-tests/run_')
        ->and($firstPath)->not->toBe($secondPath);
});

it('does not include generated docker test files in future docker plans', function (): void {
    $directory = base_path('tests/E2E/.docker-feature-tests');

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
            ->and($lane['test_files'])->not->toContain('tests/E2E/.docker-feature-tests/Docker999GeneratedTest.php');
    } finally {
        @unlink($directory.'/Docker999GeneratedTest.php');
        @rmdir($directory);
    }
});

it('rejects unsupported lanes', function (): void {
    $this->artisan('e2e:test --dry-run --lanes=redis')
        ->expectsOutputToContain('Unsupported E2E lane(s): redis. Supported lanes: docker, incus.')
        ->assertFailed();
});

it('skips unavailable incus lanes before invoking pest', function (): void {
    withE2EEnvironment([], [
        'ORBIT_E2E_INCUS_HOST_VM_CAPS' => 'beast:12',
    ], function (): void {
        Process::fake(['*' => Process::result()]);
        Process::preventStrayProcesses();

        $command = app(E2ETestCommand::class);
        $command->setLaneAvailabilityResolver(fn (): array => [
            'incus' => 'incus: prepared topology control-gateway is not available on any Incus host',
        ]);
        $this->app->instance(E2ETestCommand::class, $command);

        $this->artisan('e2e:test --lanes=incus')
            ->expectsOutputToContain('E2E lane [incus] skipped: incus: prepared topology control-gateway is not available on any Incus host')
            ->assertSuccessful();

        Process::assertRanTimes(fn ($process): bool => is_array($process->command)
            && in_array('test', $process->command, true), 0);
    });
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
        if (str_starts_with($argument, 'tests/E2E/.docker-feature-tests/run_')) {
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
    $testPath = 'tests/E2E/.docker-feature-tests/run_failure_'.bin2hex(random_bytes(4));

    return [
        'docker' => [
            'lane' => 'docker',
            'command' => ['php', 'artisan', 'test'],
            'environment' => [
                'ORBIT_E2E' => '1',
            ],
            'test_path' => $testPath,
            'test_files' => [
                'tests/E2E/ToolCredentialsTest.php',
                'tests/E2E/DefinitelyMissingTest.php',
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
    $directory = base_path('tests/E2E/.topology-scheduling-fixtures/'.bin2hex(random_bytes(4)));

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
