<?php

declare(strict_types=1);

namespace App\Infrastructure\Executor;

use Closure;
use Illuminate\Support\Facades\Config;
use RuntimeException;

/** A local lock cannot expire while a slow worker still has the file open. The kernel releases it when the worker dies. */
final readonly class ExecutorWakeLock
{
    /**
     * @template TValue
     *
     * @param  Closure(): TValue  $work
     * @return TValue|null
     */
    public function run(string $kind, int $operationId, Closure $work): mixed
    {
        $directory = rtrim(Config::string('orbit.home'), '/').'/locks/executor-wake';
        if (! is_dir($directory) && ! @mkdir($directory, 0o700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Could not prepare the executor wake lock directory.');
        }

        $handle = fopen($directory.'/'.$kind.'-'.($operationId % 256).'.lock', 'c');
        if ($handle === false) {
            throw new RuntimeException('Could not open the executor wake lock.');
        }

        try {
            return flock($handle, LOCK_EX | LOCK_NB) ? $work() : null;
        } finally {
            fclose($handle);
        }
    }
}
