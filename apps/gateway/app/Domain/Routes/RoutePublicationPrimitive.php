<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Models\Operation;

interface RoutePublicationPrimitive
{
    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function publish(Operation $operation, array $input): array;
}
