<?php

declare(strict_types=1);

namespace App\Domain\Operations;

/** The status of an operation (docs/reference/operations.md#the-record). The last three never change again. */
enum OperationStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function terminal(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed, self::Cancelled], true);
    }
}
