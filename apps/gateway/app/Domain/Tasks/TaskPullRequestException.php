<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use RuntimeException;

final class TaskPullRequestException extends RuntimeException
{
    /** The push has not failed: a queued job still pushes the commit, and a later call reports its result. */
    public bool $pending = false;

    public static function pending(string $message): self
    {
        $exception = new self($message);
        $exception->pending = true;

        return $exception;
    }
}
