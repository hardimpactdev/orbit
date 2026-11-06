<?php

declare(strict_types=1);

namespace App\Infrastructure\Ssh;

/**
 * Whether this process runs inside the control group of a Horizon unit. A release drains Horizon by stopping its unit and
 * waiting for the whole group to empty, so a process that Horizon's jobs leave behind, such as the master of a shared SSH
 * connection, holds the release. The jobs, and the commands they run, therefore open connections of their own.
 */
final readonly class SshControlGroup
{
    public function __construct(private string $file = '/proc/self/cgroup') {}

    public function insideHorizon(): bool
    {
        $lines = is_readable($this->file) ? file($this->file, FILE_IGNORE_NEW_LINES) : false;

        return is_array($lines) && array_any($lines, static fn (string $line): bool => preg_match('~-horizon\.service$~', $line) === 1);
    }
}
