<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use RuntimeException;
use Symfony\Component\Process\Process;

final class SubprocessFixture
{
    public static function line(Process $server): string
    {
        self::waitUntil($server, static fn (): bool => str_contains($server->getOutput(), "\n"));
        $line = trim(explode("\n", $server->getOutput(), 2)[0]);

        if (! $server->isRunning()) {
            throw new RuntimeException('Fixture server exited before accepting a connection: '.$server->getErrorOutput());
        }

        if ($line === '') {
            throw new RuntimeException('Fixture server printed an empty readiness line: '.$server->getErrorOutput());
        }

        return $line;
    }

    /** @param Closure(): bool $condition */
    public static function waitUntil(Process $server, Closure $condition): void
    {
        while (true) {
            $server->checkTimeout();
            $running = $server->isRunning();

            if ($condition()) {
                return;
            }

            if (! $running) {
                throw new RuntimeException('Fixture server exited before readiness: '.$server->getErrorOutput());
            }

            usleep(10_000);
        }
    }
}
