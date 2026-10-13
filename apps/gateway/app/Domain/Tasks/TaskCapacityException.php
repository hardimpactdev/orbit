<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use RuntimeException;

/**
 * The group must wait: `wait` says why, and the message is the reason the group shows. This is a wait, not a
 * failure. A failed task VM throws `TaskVmFailedException` instead.
 */
final class TaskCapacityException extends RuntimeException
{
    public function __construct(public readonly TaskWait $wait, string $reason = 'No task VM has capacity.')
    {
        parent::__construct($reason);
    }
}
