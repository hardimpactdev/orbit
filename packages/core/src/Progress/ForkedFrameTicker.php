<?php

declare(strict_types=1);

namespace Orbit\Core\Progress;

/**
 * Forks a child process that signals the parent on a fixed interval so tick
 * callbacks run in the parent while it blocks on slow I/O. Stream and HTTP
 * pollers may also invoke the same callback; invocation is throttled so frames
 * advance once per interval.
 */
final class ForkedFrameTicker
{
    public const int DEFAULT_INTERVAL_MICROSECONDS = 300_000;

    public const int DEFAULT_INTERVAL_US = 300_000;

    /** @var (callable(): void)|null */
    private static $idleCallback = null;

    private static int $idleIntervalMicroseconds = self::DEFAULT_INTERVAL_MICROSECONDS;

    private static float $lastInvokedAt = 0.0;

    private ?int $pid = null;

    private bool $usesIdleCallback = false;

    public function __construct(
        private readonly int $intervalUs = self::DEFAULT_INTERVAL_US,
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
        if (self::$idleCallback === null) {
            return;
        }

        $now = microtime(true);
        $minimumIntervalSeconds = self::$idleIntervalMicroseconds / 1_000_000;

        if (($now - self::$lastInvokedAt) < ($minimumIntervalSeconds * 0.75)) {
            return;
        }

        self::$lastInvokedAt = $now;
        (self::$idleCallback)();
    }

    public function start(callable $onTick): void
    {
        $this->stop();

        self::$idleIntervalMicroseconds = $this->intervalUs;
        self::$idleCallback = $onTick;
        self::$lastInvokedAt = 0.0;
        $this->usesIdleCallback = true;

        if (! $this->canFork()) {
            return;
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGUSR1, static function (): void {
            self::invokeIdleCallback();
        });

        $pid = pcntl_fork();

        if ($pid === -1) {
            return;
        }

        if ($pid === 0) {
            pcntl_signal(SIGTERM, static function (): void {
                exit(0);
            });

            while (true) {
                if (posix_getppid() === 1) {
                    exit(0);
                }

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
            self::$lastInvokedAt = 0.0;
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
