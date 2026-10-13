<?php

declare(strict_types=1);

namespace App\E2E;

use Closure;
use Exception;
use RuntimeException;

/** Times one named stage of a build or an acquisition, reports it, and names the stage in its failure. */
final class StageTimer
{
    /**
     * Runs the work, then tells `$progress` the stage and its seconds. A failed exception becomes `Step NAME failed: ...`,
     * unless an inner stage already named itself. An Error, such as {@see Interrupted}, passes unchanged.
     *
     * @template T
     *
     * @param  (Closure(string, float): void)|null  $progress
     * @param  Closure(): T  $work
     * @return T
     */
    public static function run(?Closure $progress, string $name, Closure $work): mixed
    {
        $started = microtime(true);
        try {
            $result = $work();
        } catch (Exception $exception) {
            throw str_starts_with($exception->getMessage(), 'Step ')
                ? $exception
                : new RuntimeException("Step {$name} failed: ".$exception->getMessage(), previous: $exception);
        }
        if ($progress !== null) {
            $progress($name, microtime(true) - $started);
        }

        return $result;
    }
}
