<?php

declare(strict_types=1);

namespace Orbit\Core\Progress;

/**
 * Forks a child process that invokes a callback on a fixed interval so the parent
 * can keep blocking on slow I/O while the active progress row keeps animating.
 */
final class ForkedFrameTicker
{
    private const int DEFAULT_INTERVAL_US = 300_000;

    private ?int $pid = null;

    public function __construct(
        private readonly int $intervalUs = self::DEFAULT_INTERVAL_US,
    ) {}

    public function start(callable $onTick): void
    {
        $this->stop();

        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            return;
        }

        $pid = pcntl_fork();

        if ($pid === -1) {
            return;
        }

        if ($pid === 0) {
            // @phpstan-ignore-next-line Intentional child-process spinner loop.
            while (true) {
                usleep($this->intervalUs);
                $onTick();
            }
        }

        $this->pid = $pid;
    }

    public function stop(): void
    {
        if ($this->pid === null || ! function_exists('posix_kill')) {
            $this->pid = null;

            return;
        }

        posix_kill($this->pid, SIGTERM);

        if (function_exists('pcntl_waitpid')) {
            pcntl_waitpid($this->pid, $status);
        }

        $this->pid = null;
    }

    public function __destruct()
    {
        $this->stop();
    }
}
