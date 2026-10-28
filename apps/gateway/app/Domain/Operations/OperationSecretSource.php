<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Models\Operation;

/** A family-owned tagged resolver. Ownership is checked before any secret is read. */
interface OperationSecretSource
{
    public function name(): string;

    public function ownerId(Operation $operation): ?int;

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue;
}
