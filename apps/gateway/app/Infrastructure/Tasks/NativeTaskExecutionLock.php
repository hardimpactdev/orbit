<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\Tasks\TaskExecutionLock;
use App\Domain\Tasks\TaskWorkspaceWaiting;
use Closure;
use RuntimeException;

/** A non-expiring, process-owned Gateway lock. A crash releases it, but never removes the lock file. */
final class NativeTaskExecutionLock implements TaskExecutionLock
{
    /** @var array<int, true> */
    private array $held = [];

    public function __construct(private readonly string $directory, private readonly float $waitSeconds = 5.0) {}

    public function synchronized(int $groupId, Closure $operation, bool $wait = true): mixed
    {
        if (isset($this->held[$groupId])) {
            return $operation();
        }
        if (! is_dir($this->directory) && ! mkdir($this->directory, 0o700, true) && ! is_dir($this->directory)) {
            throw new RuntimeException('Could not create task execution lock directory.');
        }
        $path = $this->directory.'/task-'.$groupId.'.lock';
        $handle = fopen($path, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Could not open task execution lock.');
        }
        try {
            if (! chmod($this->directory, 0o700) || ! chmod($path, 0o600)) {
                throw new RuntimeException('Could not secure task execution lock.');
            }
            $deadline = hrtime(true) + $this->waitSeconds * 1_000_000_000;
            while (! flock($handle, LOCK_EX | LOCK_NB)) {
                if (! $wait || hrtime(true) >= $deadline) {
                    throw new TaskWorkspaceWaiting('Another admitted task operation is still recording its result.');
                }
                usleep(10_000);
            }
            $this->held[$groupId] = true;

            return $operation();
        } finally {
            unset($this->held[$groupId]);
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
