<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Models\Operation;

/** Read Gateway proof under the cancel transaction; refuse before any tree mutation or external effect. */
interface OperationCancelGuard
{
    public function assertCancellable(Operation $operation): void;
}
