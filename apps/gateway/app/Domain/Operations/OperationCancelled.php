<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use RuntimeException;

/** Thrown when a run reaches a step after someone asked the operation to stop. */
final class OperationCancelled extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The operation was cancelled.');
    }
}
