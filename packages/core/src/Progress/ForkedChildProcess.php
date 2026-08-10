<?php

declare(strict_types=1);

namespace Orbit\Core\Progress;

final class ForkedChildProcess
{
    private const int STOP_TIMEOUT_MICROSECONDS = 500_000;

    public function __construct(
        private readonly int $pid,
    ) {}

    public function stop(): void
    {
        if (! function_exists('posix_kill') || ! $this->isRunningChild()) {
            return;
        }

        posix_kill($this->pid, SIGTERM);
        $this->awaitExit();
    }

    private function isRunningChild(): bool
    {
        if (! function_exists('pcntl_waitpid')) {
            return true;
        }

        $status = 0;

        return pcntl_waitpid($this->pid, $status, WNOHANG) === 0;
    }

    private function awaitExit(): void
    {
        if (! function_exists('pcntl_waitpid')) {
            return;
        }

        $deadline = hrtime(true) + (self::STOP_TIMEOUT_MICROSECONDS * 1000);
        $result = 0;
        $status = 0;

        do {
            $result = pcntl_waitpid($this->pid, $status, WNOHANG);

            if ($result === $this->pid || $result === -1) {
                break;
            }

            usleep(10_000);
        } while (hrtime(true) < $deadline);

        if ($result === 0 && defined('SIGKILL')) {
            posix_kill($this->pid, SIGKILL);
            pcntl_waitpid($this->pid, $status, WNOHANG);
        }
    }
}
