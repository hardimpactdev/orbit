<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use RuntimeException;

/** Control flow from a step to its runner; never a failed step. */
final class OperationWaitRequested extends RuntimeException
{
    public function __construct(public readonly int $delaySeconds)
    {
        parent::__construct('The step is waiting for its next read.');
    }
}
