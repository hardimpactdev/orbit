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
        if (! is_array($definition) || ($definition['execution'] ?? null) !== InstanceLifecycleExecution::class
            || ! $this->childrenAvailable($definition['children'] ?? [])) {
            throw new ResourceOperationException('operation.kind_unavailable', 'This Instance lifecycle operation requires its complete Swamp execution kit.', 503);
        }
    }

    /** @param list<array{kind:string,node_from:string,bind?:array<string,string>}> $children */
    private function childrenAvailable(array $children): bool
    {
        foreach ($children as $child) {
            $definition = $this->kinds->definition($child['kind']);
            if (! is_array($definition) || ! isset($definition['admission'])) {
                return false;
            }
        }

        return true;
    }
}
