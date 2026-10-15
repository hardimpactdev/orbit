<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use RuntimeException;

/**
 * Thrown by a best-effort step that failed. The run marks the step `failed` with the error code and continues with
 * the next step, so the operation does not fail.
 */
final class OperationStepFailed extends RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct("A best-effort step failed with [{$errorCode}].");
    }
}
