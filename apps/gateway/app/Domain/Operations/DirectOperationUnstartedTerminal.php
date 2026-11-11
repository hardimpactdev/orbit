<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Models\Operation;

/** Settle admission-owned state when cancellation or expiry proves no native run was submitted. */
interface DirectOperationUnstartedTerminal
{
    public function unstartedTerminal(Operation $operation): void;
}
