<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Models\Operation;

/** Distinguishes native rows while a kind still retains other execution paths. */
interface NativeOperationProjection
{
    public function native(Operation $operation): bool;
}
