<?php

declare(strict_types=1);

use App\Console\Commands\E2ETestCommand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

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

it('disables pest parallel mode for list-tests passthrough', function (): void {
    $this->artisan('e2e:test --dry-run --json --lanes=docker --list-tests')
        ->expectsOutputToContain('"--list-tests"')
        ->doesntExpectOutputToContain('"--parallel"')
        ->assertSuccessful();
});

it('uses six docker processes by default', function (): void {
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
            ->and($payload['success']['data']['lanes'][0]['command'])->toContain('--parallel', '--processes=6');
    } finally {
        $previous === false
            ? putenv('ORBIT_E2E_PARALLEL_PROCESSES')
            : putenv("ORBIT_E2E_PARALLEL_PROCESSES={$previous}");
    }
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
