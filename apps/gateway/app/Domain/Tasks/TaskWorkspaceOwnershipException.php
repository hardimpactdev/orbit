<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use RuntimeException;

/** A refusal is not a failed attempt to remove an authorized workspace. */
final class TaskWorkspaceOwnershipException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Task workspace ownership is missing or ambiguous. The Instance was retained.');
    }
}
