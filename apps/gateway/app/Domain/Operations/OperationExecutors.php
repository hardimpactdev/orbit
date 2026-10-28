<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use LogicException;

/** Resolves Gateway intent projection from the family-local operation declarations. */
final readonly class OperationExecutors
{
    public function swamp(string $kind): bool
    {
        return in_array($kind, OperationKinds::names(), true) && OperationKinds::executor($kind) === 'swamp';
    }

    public function completion(string $kind): ?OperationCompletion
    {
        $definition = OperationKinds::definitions()[$kind] ?? null;
        if (! is_array($definition)) {
            return null;
        }
        $completion = app($definition['completion']);
        if (! $completion instanceof OperationCompletion) {
            throw new LogicException("Operation kind [{$kind}] needs an intent completion handler.");
        }

        return $completion;
    }
}
