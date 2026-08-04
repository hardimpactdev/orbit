<?php

declare(strict_types=1);

namespace App\Services\Processes;

use Closure;
use Throwable;

/**
 * Concurrent wake-start runner using pcntl_fork when available.
 *
 * Falls back to sequential execution only for tasks that were never forked.
 * Tasks must not perform parent-side durable process-event writes.
 *
 * @mago-expect lint:kan-defect
 * @mago-expect lint:cyclomatic-complexity
 */
final readonly class ForkRuntimeWakeConcurrentRunner implements RuntimeWakeConcurrentRunner
{
    /**
     * @param  (Closure(): int)|null  $fork  Returns child pid (>0), 0 in child, or -1 on failure.
     * @param  (Closure(int): void)|null  $wait  Reaps a child pid in the parent.
     * @param  (Closure(): never)|null  $exitChild  Terminates the forked child process.
     */
    public function __construct(
        private bool $forceSequential = false,
        private ?Closure $fork = null,
        private ?Closure $wait = null,
        private ?Closure $exitChild = null,
    ) {}

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
            $this->forceSequential
            || count($tasks) === 1
            || ! $this->canFork()
        ) {
            return $this->runSequentially($tasks);
        }

        /** @var array<array-key, int> $pids */
        $pids = [];
        /** @var array<array-key, string> $paths */
        $paths = [];

        foreach ($tasks as $key => $task) {
            $path = tempnam(directory: sys_get_temp_dir(), prefix: 'orbit-wake-start-');

            if ($path === false) {
                $forked = $this->collectForkedResults($pids, $paths);

                return $forked + $this->runSequentially($this->remainingTasks($tasks, $forked));
            }

            $pid = $this->fork();

            if ($pid === -1) {
                if (is_file($path)) {
                    unlink($path);
                }

                $forked = $this->collectForkedResults($pids, $paths);

                return $forked + $this->runSequentially($this->remainingTasks($tasks, $forked));
            }

            if ($pid === 0) {
                $ok = false;

                try {
                    $ok = $task();
                } catch (Throwable) {
                    $ok = false;
                }

                file_put_contents($path, $ok ? '1' : '0');
                ($this->exitChild ?? static function (): never {
                    exit(0);
                })();
            }

            $pids[$key] = $pid;
            $paths[$key] = $path;
        }

        return $this->collectForkedResults($pids, $paths);
    }

    private function canFork(): bool
    {
        if ($this->fork instanceof Closure) {
            return true;
        }

        return function_exists('pcntl_fork') && function_exists('pcntl_waitpid');
    }

    private function fork(): int
    {
        if ($this->fork instanceof Closure) {
            return ($this->fork)();
        }

        return pcntl_fork();
    }

    private function wait(int $pid): void
    {
        if ($this->wait instanceof Closure) {
            ($this->wait)($pid);

            return;
        }

        pcntl_waitpid($pid, $status);
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
     * @return array<array-key, bool>
     */
    private function collectForkedResults(array $pids, array $paths): array
    {
        foreach ($pids as $pid) {
            $this->wait($pid);
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
     * @param  array<array-key, bool>  $completed
     * @return array<array-key, callable(): bool>
     */
    private function remainingTasks(array $tasks, array $completed): array
    {
        $remaining = [];

        foreach ($tasks as $key => $task) {
            if (array_key_exists($key, $completed)) {
                continue;
            }

            $remaining[$key] = $task;
        }

        return $remaining;
    }
}
