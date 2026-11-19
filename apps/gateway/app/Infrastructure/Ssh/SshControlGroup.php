<?php

declare(strict_types=1);

namespace App\Infrastructure\Ssh;

/**
 * What a process may do with the Node's shared SSH connection, by the control group it runs in
 * (docs/reference/node-provisioning.md#ssh-connections). The master of a shared connection stays in the control group of the
 * process that started it, and systemd kills that group when its unit ends: a oneshot such as the runtime hibernator, a
 * restart of the scheduler, an SSH shell that logs out. A command that rides the master then fails at once, without a packet
 * to the Node. So only a Gateway web service whose group outlives every request starts a master. Horizon's jobs open connections of their
 * own: a release drains Horizon by stopping its unit and waiting for the whole group to empty.
 */
final readonly class SshControlGroup
{
    public function __construct(private string $file = '/proc/self/cgroup') {}

    public function multiplexing(): SshMultiplexing
    {
        $lines = is_readable($this->file) ? file($this->file, FILE_IGNORE_NEW_LINES) : false;
        $inside = static fn (string $pattern): bool => is_array($lines) && array_any($lines, static fn (string $line): bool => preg_match($pattern, $line) === 1);

        return match (true) {
            $inside('~-horizon\.service$~') => SshMultiplexing::Alone,
            $inside('~/(?:php[0-9.]*-fpm|orbit-gateway-web)\.service$~') => SshMultiplexing::Master,
            default => SshMultiplexing::Ride,
        };
    }
}
