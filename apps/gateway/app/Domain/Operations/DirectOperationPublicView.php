<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Models\Operation;

/** Optional GET-only view for an immutable, family-owned publication participant. */
interface DirectOperationPublicView
{
    public function assertPublicParticipant(Operation $operation, int $callerNodeId): void;

    /** Current public data for this authenticated Node only. Never hydrate private material.
     * @return array<string,mixed>
     */
    public function publicInput(Operation $operation, int $callerNodeId): array;
}
