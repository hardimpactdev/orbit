<?php

declare(strict_types=1);

namespace Orbit\Core\Progress;

/**
 * Forks a child process that signals the parent on a fixed interval so tick
 * callbacks run in the parent while it blocks on slow I/O.
 */
final class ForkedFrameTicker
{
    public const int DEFAULT_INTERVAL_MICROSECONDS = 100_000;

    /** @var (callable(): void)|null */
    private static $idleCallback = null;

    private static int $idleIntervalMicroseconds = self::DEFAULT_INTERVAL_MICROSECONDS;

    private ?int $pid = null;

    private bool $usesIdleCallback = false;

    public function __construct(
        private readonly int $intervalUs = self::DEFAULT_INTERVAL_MICROSECONDS,
    ) {}

    public static function hasIdleCallback(): bool
    {
        return self::$idleCallback !== null;
    }

    public static function idleIntervalMicroseconds(): int
    {
        return self::$idleIntervalMicroseconds;
    }

    public static function invokeIdleCallback(): void
    {
        if (self::$idleCallback !== null) {
            (self::$idleCallback)();
        }
    }

    public function start(callable $onTick): void
    {
        $this->stop();

        self::$idleIntervalMicroseconds = $this->intervalUs;
        self::$idleCallback = $onTick;
        $this->usesIdleCallback = true;

        if (! $this->canFork()) {
            return;
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGUSR1, function () use ($onTick): void {
            $onTick();
        });

        $pid = pcntl_fork();

        if ($pid === -1) {
            return;
        }

        if ($pid === 0) {
            // @phpstan-ignore-next-line Child only wakes the parent spinner loop.
            while (true) {
                usleep($this->intervalUs);
                posix_kill(posix_getppid(), SIGUSR1);
            }
        }

        $this->pid = $pid;
    }

    public function stop(): void
    {
        if ($this->usesIdleCallback) {
            self::$idleCallback = null;
            $this->usesIdleCallback = false;
        }

        if ($this->pid === null || ! function_exists('posix_kill')) {
            $this->pid = null;

            return;
        }

        posix_kill($this->pid, SIGTERM);

        if (function_exists('pcntl_waitpid')) {
            pcntl_waitpid($this->pid, $status);
        }

        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGUSR1, SIG_DFL);
        }

        $this->pid = null;
    }

    public function __destruct()
    {
        $this->stop();
    }

    private function canFork(): bool
    {
        return function_exists('pcntl_fork')
            && function_exists('posix_kill')
            && function_exists('posix_getppid')
            && function_exists('pcntl_async_signals')
            && function_exists('pcntl_signal');
    }
}
