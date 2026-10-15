<?php

declare(strict_types=1);

namespace App\Domain\Operations;

/**
 * Names the worker process that runs an operation, as `<host>:<pid>:<start time>`, and tells whether that process
 * still runs. The start time comes from `/proc/<pid>/stat`, so a process ID that a newer process reused does not
 * count as the same worker. The Gateway runs on Linux; without `/proc`, the check cannot tell and reports the
 * worker alive, so an operation never runs twice.
 */
final readonly class OperationWorker
{
    public function __construct(
        private string $procRoot = '/proc',
        private ?string $hostname = null,
        private ?int $pid = null,
    ) {}

    public function current(): string
    {
        $pid = $this->pid ?? getmypid();
        $pid = $pid === false ? 0 : $pid;

        return $this->host().':'.$pid.':'.($this->startTime($pid) ?? '0');
    }

    public function alive(string $worker): bool
    {
        if (preg_match('/\A(?<host>.+):(?<pid>[0-9]+):(?<start>[0-9]+)\z/', $worker, $match) !== 1) {
            return false;
        }

        if ($match['host'] !== $this->host()) {
            return false;
        }

        if (! is_dir($this->procRoot)) {
            return true;
        }

        $start = $this->startTime((int) $match['pid']);

        return $start !== null && $start === $match['start'];
    }

    private function host(): string
    {
        $host = $this->hostname ?? gethostname();

        return $host === false || $host === '' ? 'localhost' : $host;
    }

    /** Field 22 of `/proc/<pid>/stat`: the process start time in clock ticks after boot. */
    private function startTime(int $pid): ?string
    {
        $stat = @file_get_contents("{$this->procRoot}/{$pid}/stat");

        if (! is_string($stat)) {
            return null;
        }

        // The command name in field 2 can hold spaces and parentheses, so count fields after its last `)`.
        $close = strrpos($stat, ')');
        $fields = $close === false ? [] : preg_split('/\s+/', trim(substr($stat, $close + 1)));
        $start = is_array($fields) ? ($fields[19] ?? null) : null;

        return is_string($start) && ctype_digit($start) ? $start : null;
    }
}
