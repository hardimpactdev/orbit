<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use RuntimeException;

/**
 * Preparing the subtask's declared topology failed. This is not a wait: the subtask asks for assistance with this
 * message, and a resolution prepares the topology again.
 */
final class TaskTopologyFailedException extends RuntimeException
{
    /** Every reason starts with this, so a resolution can tell a topology failure from others. */
    public const string Prefix = 'Task VM: topology: ';

    public function __construct(string $reason)
    {
        parent::__construct(self::Prefix.$reason.' Post a resolution to prepare it again.');
    }

    /** Whether an assistance reason came from a failed topology preparation. */
    public static function caused(?string $reason): bool
    {
        return is_string($reason) && str_starts_with($reason, self::Prefix);
    }
}
