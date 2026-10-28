<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Models\Operation;

/** Server-derived child intents. Reads only; never admits a child while building context.
 * @phpstan-type ChildIntent array{key: string, kind: string, node_id: int|null, input: array<string, mixed>}
 */
interface OperationFamilyChildren
{
    /** @return array{intent: array<string, mixed>, children: list<ChildIntent>} */
    public function childContext(Operation $parent): array;
}
