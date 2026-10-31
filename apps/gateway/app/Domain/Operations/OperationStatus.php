<?php

declare(strict_types=1);

namespace App\Domain\Operations;

/** The status of an operation (docs/reference/operations.md#the-record). Terminal statuses never change again. */
enum OperationStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Waiting = 'waiting';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Interrupted = 'interrupted';

    public function terminal(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed, self::Cancelled, self::Interrupted], true);
    }
}
