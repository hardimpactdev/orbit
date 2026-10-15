<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use Closure;
use RuntimeException;

/**
 * The lease of one attempt at an operation: an exclusive `flock` on a file per operation, held while a worker runs the
 * attempt. The kernel releases it when the attempt's code ends, also by an escaped error, and when the worker process
 * dies, so a free lease on a `running` operation means no attempt runs it any more. The sweeper takes the same lease
 * before it queues an operation again, so it never requeues an attempt that still runs.
 */
final readonly class OperationAttemptLease
{
    public function __construct(private string $directory) {}

    /**
     * Runs `$work` while holding the operation's lease. Returns false, without running it, when another attempt or the
     * sweeper holds the lease.
     *
     * @param  Closure(): void  $work
     */
    public function hold(int $operationId, Closure $work): bool
    {
        $handle = $this->open($operationId);

        try {
            if (! flock($handle, LOCK_EX | LOCK_NB)) {
                return false;
            }

            try {
                $work();
            } finally {
                flock($handle, LOCK_UN);
            }

            return true;
        } finally {
            fclose($handle);
        }
    }

    /** @return resource */
    private function open(int $operationId): mixed
    {
        if (! is_dir($this->directory) && ! @mkdir($this->directory, 0o700, true) && ! is_dir($this->directory)) {
            throw new RuntimeException("Could not create the operation lease directory [{$this->directory}].");
        }

        $handle = @fopen("{$this->directory}/operation-{$operationId}.lock", 'c');

        return is_resource($handle) ? $handle : throw new RuntimeException("Could not open the lease of operation [{$operationId}].");
    }
}
