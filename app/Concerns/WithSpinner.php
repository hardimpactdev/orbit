<?php

declare(strict_types=1);

namespace App\Concerns;

trait WithSpinner
{
    /** @var list<string> Pre-colored spinner frames: ○/◉ alternation in cyan */
    public static array $spinnerFrames = [
        "\e[36m○\e[39m",
        "\e[36m◉\e[39m",
    ];

    private static int $spinnerInterval = 300_000; // microseconds between frames

    /**
     * Run a single callable while animating a spinner via pcntl_fork.
     */
    private function runWithSpinner(callable $work, callable $render): mixed
    {
        $frames = self::$spinnerFrames;
        $render($frames[0]);

        if (! function_exists('pcntl_fork')) {
            return $work();
        }

        $pid = pcntl_fork();

        if ($pid === -1) {
            return $work();
        }

        if ($pid === 0) {
            $tick = 0;

            // @phpstan-ignore-next-line Intentional child-process spinner loop.
            while (true) {
                $render($frames[$tick++ % count($frames)]);
                usleep(self::$spinnerInterval);
            }
        }

        $error = null;
        $result = null;

        try {
            $result = $work();
        } catch (\Throwable $e) {
            $error = $e;
        }

        posix_kill($pid, SIGTERM);
        pcntl_waitpid($pid, $status);

        if ($error !== null) {
            throw $error;
        }

        return $result;
    }

    /**
     * Run sequential tasks while animating spinners for all pending items.
     *
     * Forks a single spinner child that animates all non-completed items.
     * The parent runs tasks one by one and signals completions via a temp file.
     * Use this when tasks have dependencies and can't run in parallel.
     *
     * @param  list<callable>  $tasks  Callables to run sequentially
     * @param  callable  $renderSpinner  fn(int $index, string $frame): void
     * @param  callable  $renderResult  fn(int $index, mixed $result): void
     * @param  callable|null  $renderError  fn(int $index, \Throwable $e): void
     * @return list<mixed> Results in task order
     */
    public function runSequentialWithSpinners(
        array $tasks,
        callable $renderSpinner,
        callable $renderResult,
        ?callable $renderError = null,
    ): array {
        $count = count($tasks);
        $results = array_fill(0, $count, null);

        if (! function_exists('pcntl_fork') || $count === 0) {
            foreach ($tasks as $i => $task) {
                try {
                    $results[$i] = $task();
                    $renderResult($i, $results[$i]);
                } catch (\Throwable $e) {
                    $renderError !== null ? $renderError($i, $e) : throw $e;
                }
            }

            return $results;
        }

        // Shared state: temp file where parent writes completed indices
        $stateFile = tempnam(sys_get_temp_dir(), 'orbit-spinner-');
        file_put_contents($stateFile, '');

        $pid = pcntl_fork();

        if ($pid === -1) {
            @unlink($stateFile);

            foreach ($tasks as $i => $task) {
                try {
                    $results[$i] = $task();
                    $renderResult($i, $results[$i]);
                } catch (\Throwable $e) {
                    $renderError !== null ? $renderError($i, $e) : throw $e;
                }
            }

            return $results;
        }

        if ($pid === 0) {
            $tick = 0;
            $frames = self::$spinnerFrames;
            /** @var array<int, true> $completed */
            $completed = [];

            // @phpstan-ignore-next-line Intentional child-process spinner loop.
            while (true) {
                $data = @file_get_contents($stateFile);
                if ($data !== false && $data !== '') {
                    $completed = array_fill_keys(
                        array_map(static fn (string $index): int => (int) $index, explode(',', $data)),
                        true,
                    );
                }

                $frame = $frames[$tick++ % count($frames)];
                for ($i = 0; $i < $count; $i++) {
                    if (! array_key_exists($i, $completed)) {
                        $renderSpinner($i, $frame);

                        break;
                    }
                }

                usleep(self::$spinnerInterval);
            }
        }

        usleep(100_000);

        $completedIndices = [];

        try {
            foreach ($tasks as $i => $task) {
                try {
                    $results[$i] = $task();
                    $renderResult($i, $results[$i]);
                } catch (\Throwable $e) {
                    if ($renderError !== null) {
                        $renderError($i, $e);
                    } else {
                        throw $e;
                    }

                    break;
                }

                $completedIndices[] = $i;
                file_put_contents($stateFile, implode(',', $completedIndices));
            }
        } finally {
            posix_kill($pid, SIGTERM);
            pcntl_waitpid($pid, $status);
            @unlink($stateFile);
        }

        return $results;
    }
}
