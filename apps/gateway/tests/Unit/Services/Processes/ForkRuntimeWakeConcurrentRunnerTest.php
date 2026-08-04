<?php

declare(strict_types=1);

use App\Services\Processes\ForkRuntimeWakeConcurrentRunner;

it('runs multi-task wake starts through pcntl_fork with proven overlap', function (): void {
    if (! function_exists('pcntl_fork') || ! function_exists('pcntl_waitpid')) {
        $this->markTestSkipped('pcntl is required to prove production wake start concurrency.');
    }

    $barrier = tempnam(directory: sys_get_temp_dir(), prefix: 'orbit-wake-barrier-');
    expect($barrier)->not->toBeFalse();
    file_put_contents($barrier, '0');

    $arrivalDir = sys_get_temp_dir().'/orbit-wake-arrivals-'.bin2hex(random_bytes(8));
    mkdir($arrivalDir, permissions: 0o755);

    $runner = new ForkRuntimeWakeConcurrentRunner(forceSequential: false);
    $results = $runner->run([
        'a' => static function () use ($barrier, $arrivalDir): bool {
            file_put_contents($arrivalDir.'/a', '1');
            $deadline = microtime(true) + 2.0;

            while (microtime(true) < $deadline) {
                $arrivals = count(glob($arrivalDir.'/*') ?: []);

                if ($arrivals >= 2) {
                    file_put_contents($barrier, '1');

                    return true;
                }

                usleep(5_000);
            }

            return false;
        },
        'b' => static function () use ($barrier, $arrivalDir): bool {
            file_put_contents($arrivalDir.'/b', '1');
            $deadline = microtime(true) + 2.0;

            while (microtime(true) < $deadline) {
                $arrivals = count(glob($arrivalDir.'/*') ?: []);

                if ($arrivals >= 2) {
                    return true;
                }

                usleep(5_000);
            }

            return false;
        },
    ]);

    expect($results)
        ->toBe(['a' => true, 'b' => true])
        ->and(file_get_contents($barrier))
        ->toBe('1')
        ->and(count(glob($arrivalDir.'/*') ?: []))
        ->toBe(2);

    unlink($barrier);
    unlink($arrivalDir.'/a');
    unlink($arrivalDir.'/b');
    rmdir($arrivalDir);
});

it('does not re-run already forked tasks when a later fork fails', function (): void {
    $startedPath = tempnam(directory: sys_get_temp_dir(), prefix: 'orbit-wake-started-');
    expect($startedPath)->not->toBeFalse();
    file_put_contents($startedPath, '');

    $forkAttempts = 0;
    $childPids = [];

    $runner = new ForkRuntimeWakeConcurrentRunner(
        forceSequential: false,
        fork: static function () use (&$forkAttempts, &$childPids): int {
            $forkAttempts++;

            if ($forkAttempts === 2) {
                return -1;
            }

            $pid = pcntl_fork();

            if ($pid > 0) {
                $childPids[] = $pid;
            }

            return $pid;
        },
        wait: static function (int $pid): void {
            pcntl_waitpid($pid, $status);
        },
        exitChild: static function (): never {
            exit(0);
        },
    );

    $results = $runner->run([
        'first' => static function () use ($startedPath): bool {
            file_put_contents($startedPath, file_get_contents($startedPath).'first,');

            return true;
        },
        'second' => static function () use ($startedPath): bool {
            file_put_contents($startedPath, file_get_contents($startedPath).'second,');

            return true;
        },
    ]);

    expect($results)
        ->toBe(['first' => true, 'second' => true])
        ->and(file_get_contents($startedPath))
        // first ran once in the forked child; second only via sequential fallback.
        ->toBe('first,second,')
        ->and($forkAttempts)
        ->toBe(2)
        ->and($childPids)
        ->toHaveCount(1);

    unlink($startedPath);
});
