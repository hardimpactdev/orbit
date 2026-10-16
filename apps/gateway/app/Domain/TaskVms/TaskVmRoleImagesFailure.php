<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

/** Safe completion classifications; raw process output is never part of a transfer diagnostic. */
enum TaskVmRoleImagesFailure: string
{
    case Exit = 'exit';
    case Timeout = 'timeout';
    case Truncated = 'truncated';
    case ReceiptMismatch = 'receipt_mismatch';
}
