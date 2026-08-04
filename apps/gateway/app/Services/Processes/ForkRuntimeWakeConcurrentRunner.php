<?php

declare(strict_types=1);

namespace App\Services\Processes;

use Throwable;

/**
 * Concurrent wake-start runner using pcntl_fork when available.
 *
 * Falls back to sequential execution when fork is unavailable. Tasks must not
 * perform parent-side durable process-event writes.
 *
 * @mago-expect lint:kan-defect
 * @mago-expect lint:cyclomatic-complexity
 */
final class ForkRuntimeWakeConcurrentRunner implements RuntimeWakeConcurrentRunner
{
    /**
     * @param  array<array-key, callable(): bool>  $tasks
     * @return array<array-key, bool>
     */
    public function run(array $tasks): array
    {
        if ($tasks === []) {
            return [];
        }

        if (
            count($tasks) === 1
            || function_exists('app')
            && app()->runningUnitTests()
            || ! function_exists('pcntl_fork')
            || ! function_exists('pcntl_waitpid')
            || ! function_exists('posix_exit')
        ) {
            return $this->runSequentially($tasks);
        }

        $paths = [];
        $pids = [];

        foreach ($tasks as $key => $task) {
            $path = tempnam(directory: sys_get_temp_dir(), prefix: 'orbit-wake-start-');

            if ($path === false) {
                $this->reap($pids, $paths);

                return $this->runSequentially($tasks);
            }

            $paths[$key] = $path;
            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->reap($pids, $paths);

                return $this->runSequentially($tasks);
            }

            if ($pid === 0) {
                $ok = false;

                try {
                    $ok = $task();
                } catch (Throwable) {
                    $ok = false;
                }

                file_put_contents($path, $ok ? '1' : '0');
                posix_exit(0);
            }

            $pids[$key] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $results = [];

        foreach ($paths as $key => $path) {
            $results[$key] = is_file($path) && file_get_contents($path) === '1';

            if (is_file($path)) {
                unlink($path);
            }
        }

        return $results;
    }

    /**
     * @param  array<array-key, callable(): bool>  $tasks
     * @return array<array-key, bool>
     */
    private function runSequentially(array $tasks): array
    {
        $results = [];

        foreach ($tasks as $key => $task) {
            try {
                $results[$key] = $task();
            } catch (Throwable) {
                $results[$key] = false;
            }
        }

        return $results;
    }

    /**
     * @param  array<array-key, int>  $pids
     * @param  array<array-key, string>  $paths
     */
    private function reap(array $pids, array $paths): void
    {
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        foreach ($paths as $path) {
            if (! is_file($path)) {
                continue;
            }

            unlink($path);
        }
    }
}
