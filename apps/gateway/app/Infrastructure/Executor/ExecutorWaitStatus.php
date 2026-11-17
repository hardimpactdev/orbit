<?php

declare(strict_types=1);

namespace App\Infrastructure\Executor;

/** How a wait for an executor result ended. Every case but `Timeout` is the status of the executor's result line. */
enum ExecutorWaitStatus: string
{
    case Done = 'done';
    case Partial = 'partial';
    case Failed = 'failed';
    case Refused = 'refused';
    case Timeout = 'timeout';
}
