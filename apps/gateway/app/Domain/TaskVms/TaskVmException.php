<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Shared\ResourceOperationException;
use Throwable;

/** A task VM failure with a stable `task_vm.*` error code. */
final class TaskVmException extends ResourceOperationException
{
    /** @param array{phase: string, exit_code: int|null, stderr: string}|null $imageFailure */
    public function __construct(
        TaskVmError|string $errorCode,
        string $message = 'The task VM operation failed.',
        int $status = 409,
        ?Throwable $previous = null,
        public readonly ?array $imageFailure = null,
    ) {
        parent::__construct($errorCode instanceof TaskVmError ? $errorCode->value : $errorCode, $message, $status, $previous);
    }
}
