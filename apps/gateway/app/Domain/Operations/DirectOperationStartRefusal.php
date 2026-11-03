<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Models\Operation;

/** Close admission records after a definite native start refusal, without claiming any Node effects. */
interface DirectOperationStartRefusal
{
    public function refused(Operation $operation): void;
}
