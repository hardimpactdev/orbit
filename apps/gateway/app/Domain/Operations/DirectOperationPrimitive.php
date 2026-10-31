<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Models\Operation;

/** Optional fixed action while the family moves execution to its Node worker. */
interface DirectOperationPrimitive
{
    /** @return array<string, mixed> */
    public function execute(Operation $operation): array;
}
