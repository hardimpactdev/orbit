<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Shared\ResourceOperationException;
use Throwable;

/** A task VM failure with a stable `task_vm.*` error code. */
final class TaskVmException extends ResourceOperationException
{
    /** The most characters of an error message that a row, a log entry, or an assistance reason keeps. */
    public const int MessageLimit = 2000;

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

    /** The stable code of any failure: its own code, or `task_vm.job_failed` when it has none. */
    public static function codeOf(Throwable $exception): string
    {
        return match (true) {
            $exception instanceof ResourceOperationException, $exception instanceof NodeProvisioningException => $exception->errorCode,
            default => TaskVmError::JobFailed->value,
        };
    }

    /** The message of any failure, cut to {@see self::MessageLimit} characters. */
    public static function messageOf(Throwable $exception): string
    {
        return mb_substr($exception->getMessage(), 0, self::MessageLimit);
    }
}
