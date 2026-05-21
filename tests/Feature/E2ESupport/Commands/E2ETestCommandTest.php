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
    putenv('ORBIT_E2E_LANES');

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
                ->environment->ORBIT_E2E_TOPOLOGY_PROVIDERS->toBe('docker'),
            fn ($lane) => $lane
                ->lane->toBe('incus')
                ->environment->ORBIT_E2E_TOPOLOGY_PROVIDER->toBe('incus')
                ->environment->ORBIT_E2E_TOPOLOGY_PROVIDERS->toBe('incus'),
        );
});

it('uses selected lanes from the environment', function (): void {
    putenv('ORBIT_E2E_LANES=incus');

    try {
        $this->artisan('e2e:test --dry-run --json')
            ->expectsOutputToContain('"lane":"incus"')
            ->doesntExpectOutputToContain('"lane":"docker"')
            ->assertSuccessful();
    } finally {
        putenv('ORBIT_E2E_LANES');
    }
});

it('does not allocate a timings file for dry-run json output', function (): void {
    $previous = getenv('ORBIT_E2E_TIMINGS');
    putenv('ORBIT_E2E_TIMINGS=1');

    try {
        $exitCode = Artisan::call('e2e:test', [
            '--dry-run' => true,
            '--json' => true,
            '--lanes' => 'docker',
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);
        $environment = $payload['success']['data']['lanes'][0]['environment'];

        expect($exitCode)->toBe(0)
            ->and($environment)->not->toHaveKey('ORBIT_E2E_TIMINGS_FILE');
    } finally {
        $previous === false
            ? putenv('ORBIT_E2E_TIMINGS')
            : putenv("ORBIT_E2E_TIMINGS={$previous}");
    }
});

it('disables pest parallel mode for list-tests passthrough', function (): void {
    $this->artisan('e2e:test --dry-run --json --lanes=docker --list-tests')
        ->expectsOutputToContain('"--list-tests"')
        ->doesntExpectOutputToContain('"--parallel"')
        ->assertSuccessful();
});

it('plans the docker canary lane without leaking the canary flag', function (): void {
    $exitCode = Artisan::call('e2e:test', [
        '--canary' => true,
        '--dry-run' => true,
        '--json' => true,
        '--lanes' => 'docker',
    ]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);
    $command = $payload['success']['data']['lanes'][0]['command'];

    expect($exitCode)->toBe(0)
        ->and($command)->toContain('--group=e2e-feature-canary')
        ->and($command)->not->toContain('--group=e2e-feature')
        ->and($command)->not->toContain('--canary');
});

it('uses eight docker processes by default', function (): void {
    $previous = getenv('ORBIT_E2E_PARALLEL_PROCESSES');
    putenv('ORBIT_E2E_PARALLEL_PROCESSES');

    try {
        $exitCode = Artisan::call('e2e:test', [
            '--dry-run' => true,
            '--json' => true,
            '--lanes' => 'docker',
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['lanes'][0]['command'])->toContain('--parallel', '--processes=8');
    } finally {
        $previous === false
            ? putenv('ORBIT_E2E_PARALLEL_PROCESSES')
            : putenv("ORBIT_E2E_PARALLEL_PROCESSES={$previous}");
    }
});

it('rejects docker worker counts that do not match configured host slots', function (): void {
    withE2EEnvironment(['ORBIT_E2E_PARALLEL_PROCESSES'], [
        'ORBIT_E2E_DOCKER_HOST_SLOTS' => 'sidecar1:4,sidecar2:4',
        'ORBIT_E2E_PARALLEL_PROCESSES' => '6',
    ], function (): void {
        $this->artisan('e2e:test --dry-run --json --lanes=docker')
            ->expectsOutputToContain('ORBIT_E2E_PARALLEL_PROCESSES must match total Docker slots')
            ->assertFailed();
    });
});

it('rejects docker container caps below the largest configured host slot capacity', function (): void {
    withE2EEnvironment(['ORBIT_E2E_PARALLEL_PROCESSES'], [
        'ORBIT_E2E_DOCKER_HOST_SLOTS' => 'sidecar1:4,sidecar2:4',
        'ORBIT_E2E_DOCKER_MAX_CONTAINERS_PER_HOST' => '12',
        'ORBIT_E2E_PARALLEL_PROCESSES' => '8',
    ], function (): void {
        $this->artisan('e2e:test --dry-run --json --lanes=docker')
            ->expectsOutputToContain('ORBIT_E2E_DOCKER_MAX_CONTAINERS_PER_HOST must be at least 20')
            ->assertFailed();
    });
});

it('disables pest parallel mode for a single docker process', function (): void {
    putenv('ORBIT_E2E_PARALLEL_PROCESSES=1');

    try {
        $this->artisan('e2e:test --dry-run --json --lanes=docker')
            ->doesntExpectOutputToContain('"--parallel"')
            ->doesntExpectOutputToContain('"--processes=1"')
            ->assertSuccessful();
    } finally {
        putenv('ORBIT_E2E_PARALLEL_PROCESSES');
    }
});

it('limits docker parallel runs to docker eligible e2e files', function (): void {
    $exitCode = Artisan::call('e2e:test', [
        '--dry-run' => true,
        '--json' => true,
        '--lanes' => 'docker',
    ]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);
    $lane = $payload['success']['data']['lanes'][0];
    $generatedPath = firstGeneratedDockerPath($lane['command']);

    expect($exitCode)->toBe(0)
        ->and($generatedPath)->toStartWith('tests/E2E/.docker-feature-tests/run_')
        ->and($lane['test_files'])->toContain('tests/E2E/ToolCredentialsTest.php')
        ->and($lane['test_files'])->not->toContain('tests/E2E/ToolStartTest.php')
        ->and($lane['test_files'])->not->toContain('tests/E2E/RuntimeBackendHostInitTest.php');
});

it('includes the agent topology coverage in the incus lane', function (): void {
    withE2EEnvironment(['ORBIT_E2E_INCUS_PARALLEL_PROCESSES'], [
        'ORBIT_E2E_INCUS_MAX_VMS_PER_HOST' => '12',
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
            ->and($lane['environment']['ORBIT_E2E_TOPOLOGY_STRATEGY'])->toBe('superset')
            ->and($lane['command'])->toContain('--processes=2');
    });
});

it('uses a unique generated docker test directory per plan', function (): void {
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
        $exitCode = Artisan::call('e2e:test', [
            '--dry-run' => true,
            '--json' => true,
            '--lanes' => 'docker',
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);
        $lane = $payload['success']['data']['lanes'][0];

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
