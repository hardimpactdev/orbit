<?php

declare(strict_types=1);

use App\Services\Processes\ProcessRuntimeWakeConcurrentRunner;
use Carbon\CarbonInterval;
use Closure;
use Illuminate\Contracts\Concurrency\Driver as ConcurrencyDriver;
use Illuminate\Support\Defer\DeferredCallback;
use Illuminate\Support\Facades\Concurrency;
use Tests\Support\RuntimeWakeProcessConcurrencyProbe;
use Tests\TestCase;

uses(TestCase::class);

it('does not use pcntl_fork of the booted gateway for wake concurrent starts', function (): void {
    $runnerPath = app_path('Services/Processes/ProcessRuntimeWakeConcurrentRunner.php');
    $hibernationPath = app_path('Services/Processes/RuntimeHibernation.php');
    $starterPath = app_path('Actions/Processes/RuntimeWakeProcessStarter.php');

    expect(file_exists(app_path('Services/Processes/ForkRuntimeWakeConcurrentRunner.php')))
        ->toBeFalse()
        ->and(file_get_contents($runnerPath))
        ->not->toContain('pcntl_fork')
        ->not->toContain('posix_exit')->toContain("Concurrency::driver('process')")->and(file_get_contents(
            $hibernationPath,
        ))
        ->not->toContain('pcntl_fork')->toContain('RuntimeWakeProcessStarter')->and(file_get_contents(
            $starterPath,
        ))->toContain('function start(int $nodeId, int $processId, string $runtimeUnit)');
});

it('dispatches multi-task wake starts through process concurrency with proven overlap and clean-exec isolation', function (): void {
    $parentPid = getmypid();
    $token = bin2hex(random_bytes(8));
    $dir = sys_get_temp_dir()."/orbit-wake-process-{$token}";
    mkdir($dir, permissions: 0o755);

    $runner = new ProcessRuntimeWakeConcurrentRunner(forceSequential: false);
    $results = $runner->run(RuntimeWakeProcessConcurrencyProbe::overlappingTasks($dir));

    $pidA = is_file("{$dir}/a.pid") ? (int) file_get_contents("{$dir}/a.pid") : 0;
    $pidB = is_file("{$dir}/b.pid") ? (int) file_get_contents("{$dir}/b.pid") : 0;
    $envA = is_file("{$dir}/a.env") ? file_get_contents("{$dir}/a.env") : 'missing';
    $envB = is_file("{$dir}/b.env") ? file_get_contents("{$dir}/b.env") : 'missing';

    expect($results)
        ->toBe(['a' => true, 'b' => true])
        ->and($pidA)
        ->toBeGreaterThan(0)
        ->and($pidB)
        ->toBeGreaterThan(0)
        ->and($pidA)
        ->not->toBe($parentPid)->and($pidB)
        ->not->toBe($parentPid)->and($pidA)
        ->not->toBe($pidB)->and($envA)->toBe('present')->and($envB)->toBe('present')->and(is_file(
            "{$dir}/a.ok",
        ))->toBeTrue()->and(is_file("{$dir}/b.ok"))->toBeTrue();

    foreach (glob("{$dir}/*") ?: [] as $file) {
        unlink($file);
    }
    rmdir($dir);
})->skip(
    ! function_exists('proc_open'),
    'proc_open is required for Laravel process concurrency wake starts.',
);

it('fails closed without re-running tasks when the process pool throws', function (): void {
    $startedPath = tempnam(directory: sys_get_temp_dir(), prefix: 'orbit-wake-fail-closed-');
    expect($startedPath)->not->toBeFalse();
    file_put_contents($startedPath, '0');

    $runner = new ProcessRuntimeWakeConcurrentRunner(forceSequential: false);
    $throwingDriver = new class implements ConcurrencyDriver {
        public bool $runCalled = false;

        public function run(Closure|array $tasks, CarbonInterval|int|null $timeout = null): array
        {
            $this->runCalled = true;

            throw new RuntimeException('pool failure');
        }

        public function defer(Closure|array $tasks): DeferredCallback
        {
            throw new RuntimeException('defer is unused in wake process-pool failure coverage.');
        }
    };

    Concurrency::partialMock()
        ->shouldReceive('driver')
        ->once()
        ->with('process')
        ->andReturn($throwingDriver);

    $results = $runner->run([
        'a' => static function () use ($startedPath): bool {
            file_put_contents($startedPath, (string) ((int) file_get_contents($startedPath) + 1));

            return true;
        },
        'b' => static function () use ($startedPath): bool {
            file_put_contents($startedPath, (string) ((int) file_get_contents($startedPath) + 1));

            return true;
        },
    ]);

    expect($throwingDriver->runCalled)
        ->toBeTrue()
        ->and($results)
        ->toBe(['a' => false, 'b' => false])
        ->and(file_get_contents($startedPath))
        ->toBe('0');

    unlink($startedPath);
});
