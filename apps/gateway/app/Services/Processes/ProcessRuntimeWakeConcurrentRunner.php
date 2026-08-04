<?php

declare(strict_types=1);

namespace App\Services\Processes;

use Illuminate\Contracts\Concurrency\Driver as ConcurrencyDriver;
use Illuminate\Support\Facades\Concurrency;
use Throwable;

/**
 * Wake-only concurrent start runner using Laravel Concurrency process driver.
 *
 * Spawns fresh PHP Artisan workers (never forks the booted gateway process).
 * Tasks must capture only serializable scalars and resolve services inside the
 * worker. On pool failure, already-dispatched work is never re-run; every key
 * fails closed so the parent can clean up.
 */
final readonly class ProcessRuntimeWakeConcurrentRunner implements RuntimeWakeConcurrentRunner
{
    public function __construct(
        private bool $forceSequential = false,
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

        if ($this->forceSequential || count($tasks) === 1) {
            return $this->runSequentially($tasks);
        }

        try {
            $driver = Concurrency::driver('process');

            if (! $driver instanceof ConcurrencyDriver) {
                return $this->failedClosed($tasks);
            }

            /** @var array<array-key, mixed> $results */
            $results = $driver->run($tasks);
        } catch (Throwable) {
            return $this->failedClosed($tasks);
        }

        $normalized = [];

        foreach (array_keys($tasks) as $key) {
            $normalized[$key] = ($results[$key] ?? false) === true;
        }

        return $normalized;
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
                $results[$key] = $task() === true;
            } catch (Throwable) {
                $results[$key] = false;
            }
        }

        return $results;
    }

    /**
     * @param  array<array-key, callable(): bool>  $tasks
     * @return array<array-key, bool>
     */
    private function failedClosed(array $tasks): array
    {
        $results = [];

        foreach (array_keys($tasks) as $key) {
            $results[$key] = false;
        }

        return $results;
    }
}
