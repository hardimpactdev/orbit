<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmException;
use Closure;
use Throwable;

/** Runs the stages of a base image build or sync and of a workspace template build, with every provider. */
final class TaskVmBuildStage
{
    /**
     * Runs one stage and reports its seconds. A failure becomes `$code` and names `$work` and the stage.
     *
     * @template T
     *
     * @param  (Closure(string, float): void)|null  $progress
     * @param  Closure(): T  $stage
     * @return T
     */
    public static function run(string $code, string $work, string $name, ?Closure $progress, Closure $stage): mixed
    {
        $started = microtime(true);
        try {
            $result = $stage();
        } catch (Throwable $exception) {
            throw new TaskVmException($code, "The {$work} failed at stage [{$name}]: ".mb_substr($exception->getMessage(), 0, 2000), 502, $exception);
        }
        if ($progress instanceof Closure) {
            $progress($name, round(microtime(true) - $started, 1));
        }

        return $result;
    }
}
