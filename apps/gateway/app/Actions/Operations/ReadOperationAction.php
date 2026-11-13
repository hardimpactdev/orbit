<?php

declare(strict_types=1);

namespace App\Actions\Operations;

use App\Models\Operation;

/** A show returns recorded state; the reconciler owns native settlement and expiry. */
final readonly class ReadOperationAction
{
    public function execute(Operation $operation): Operation
    {
        return $operation;
    }
}
