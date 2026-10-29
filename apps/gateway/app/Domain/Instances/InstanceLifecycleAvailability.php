<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\ResourceOperationException;

/** Refuses new intent before mutation when this family's full execution contract is unavailable. */
final readonly class InstanceLifecycleAvailability
{
    public function __construct(private OperationKinds $kinds) {}

    public function assertAvailable(string $kind): void
    {
        $definition = $this->kinds->definition($kind);
        if (! is_array($definition) || ($definition['execution'] ?? null) !== InstanceLifecycleExecution::class) {
            throw new ResourceOperationException('operation.kind_unavailable', 'This Instance lifecycle operation requires its complete Swamp execution kit.', 503);
        }
    }
}
