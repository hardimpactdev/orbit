<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Models\Operation;

/** Existing connection authentication only. Never mint, rotate, create or project state. */
interface OperationConnectionSecretSource extends OperationSecretSource
{
    public function resolveConnection(Operation $operation, int $id): OperationSecretValue;
}
