<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use LogicException;

/** Resolves Gateway intent projection from the family-local operation declarations. */
final readonly class OperationExecutors
{
    public function __construct(private OperationKinds $kinds) {}

    public function swamp(string $kind): bool
    {
        return is_array($this->kinds->definition($kind));
    }

    public function polled(string $kind): bool
    {
        return $this->swamp($kind) && ! $this->kinds->directOnly($kind);
    }

    public function cancelGuard(string $kind): ?OperationCancelGuard
    {
        $definition = $this->kinds->definition($kind);
        $class = is_array($definition) ? ($definition['cancel_guard'] ?? null) : null;

        return $class === null ? null : app($class);
    }

    public function completion(string $kind): ?OperationCompletion
    {
        $definition = $this->kinds->definition($kind);
        if (! is_array($definition) || ! isset($definition['completion'])) {
            return null;
        }
        $completion = app($definition['completion']);
        if (! $completion instanceof OperationCompletion) {
            throw new LogicException("Operation kind [{$kind}] needs an intent completion handler.");
        }

        return $completion;
    }
}
