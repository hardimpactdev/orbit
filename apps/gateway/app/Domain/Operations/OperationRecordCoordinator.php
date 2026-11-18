<?php

declare(strict_types=1);

namespace App\Domain\Operations;

/** Advances record-only Operations from the receipts of their admitted native work. */
interface OperationRecordCoordinator
{
    public function sweep(): void;
}
