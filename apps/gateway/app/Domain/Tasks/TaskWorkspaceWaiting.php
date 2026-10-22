<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Shared\ResourceOperationException;

/** A workspace operation waits; the scheduler retries it without failure accounting. */
final class TaskWorkspaceWaiting extends ResourceOperationException
{
    public function __construct(string $message)
    {
        parent::__construct(
            errorCode: 'tasks.workspace_waiting',
            message: $message,
            status: 409,
        );
    }
}
