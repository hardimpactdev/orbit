<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\Operations\OperationKinds;
use App\Models\Operation;
use LogicException;

/** Preparation only: the family contract owns each fixed wrapper's input schema. */
trait TaskVmTransportSchema
{
    /** @param array<string, mixed> $run
     * @return array<string, mixed>
     */
    public function schema(Operation $operation, array $run): array
    {
        $kind = app(OperationKinds::class)->definition($operation->kind);
        if (! is_array($kind)) {
            throw new LogicException('The VM kind has no Swamp contract.');
        }
        foreach ($kind['runs'] as $allowed) {
            if ($allowed['transport'] === $run['transport'] && $allowed['name'] === $run['name'] && isset($allowed['input_schema'])) {
                return $allowed['input_schema'];
            }
        }
        throw new LogicException('The task VM transport requires a declared input schema.');
    }
}
