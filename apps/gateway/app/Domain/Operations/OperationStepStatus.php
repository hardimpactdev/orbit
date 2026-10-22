<?php

declare(strict_types=1);

namespace App\Domain\Operations;

enum OperationStepStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Waiting = 'waiting';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
