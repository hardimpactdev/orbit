<?php

declare(strict_types=1);

namespace App\Domain\Operations;

/**
 * Names the worker process that runs an attempt, as `<host>:<pid>`, so a person can find it. Recovery never reads
 * it: the {@see OperationAttemptLease} tells whether an attempt still runs.
 */
final readonly class OperationWorker
{
    public function __construct(
        private ?string $hostname = null,
        private ?int $pid = null,
    ) {}

    public function current(): string
    {
        $host = $this->hostname ?? gethostname();
        $pid = $this->pid ?? getmypid();

        return ($host === false || $host === '' ? 'localhost' : $host).':'.($pid === false ? 0 : $pid);
    }
}
